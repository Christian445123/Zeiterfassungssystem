<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function current_user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q_one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
        }
    }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges CSRF-Token. Bitte Seite neu laden.');
    }
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function require_login(bool $admin = false): array
{
    $u = current_user();
    if (!$u) {
        redirect('index.php');
    }
    if (!empty($u['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
        redirect('password.php'); // Standard-Passwort erst ändern
    }
    if ($admin && $u['role'] !== 'admin') {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    if (is_post()) {
        csrf_check();
    }
    return $u;
}

/** Filter/Kontext: Admin darf jeden User wählen, sonst nur sich selbst. */
function selected_user_id(array $me, string $param = 'user'): int
{
    if ($me['role'] === 'admin' && isset($_REQUEST[$param]) && (int)$_REQUEST[$param] > 0) {
        return (int)$_REQUEST[$param];
    }
    return (int)$me['id'];
}

function page_header(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = [
        ['dashboard.php', 'Dashboard', 'dash'],
        ['schedule.php', 'Dienstplan', 'schedule'],
        ['entries.php', 'Zeiten', 'entries'],
        ['reports.php', 'Auswertung', 'reports'],
        ['absences.php', 'Abwesenheiten', 'abs'],
    ];
    if ($u && $u['role'] === 'admin') {
        $nav[] = ['users.php', 'Mitarbeiter', 'users'];
        $nav[] = ['projects.php', 'Projekte', 'projects'];
        $nav[] = ['licenses.php', 'Lizenzen & API', 'lic'];
        $nav[] = ['updates.php', 'Updates', 'upd'];
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' – ' . e(cfg('app_name')) . '</title><link rel="stylesheet" href="assets/style.css"></head><body>';
    if ($u) {
        echo '<header class="top"><div class="brand">' . e(cfg('app_name')) . '</div><nav>';
        foreach ($nav as [$href, $label, $key]) {
            echo '<a href="' . $href . '"' . ($key === $active ? ' class="on"' : '') . '>' . e($label) . '</a>';
        }
        echo '</nav><div class="who">' . e($u['full_name']) . ' · <a href="password.php">Passwort</a> · <a href="logout.php">Abmelden</a></div></header>';
    }
    echo '<main><h1>' . e($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function page_footer(): void
{
    echo '<footer class="foot">' . e(cfg('app_name')) . ' v' . e(panel_version()) . '</footer></main></body></html>';
}

function user_select(string $name, int $selected, bool $onlyActive = true, string $extra = ''): string
{
    $rows = q_all('SELECT id, full_name FROM users ' . ($onlyActive ? 'WHERE active = 1 ' : '') . 'ORDER BY full_name');
    $h = '<select name="' . e($name) . '" ' . $extra . '>';
    foreach ($rows as $r) {
        $h .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['full_name']) . '</option>';
    }
    return $h . '</select>';
}

function project_select(string $name, ?int $selected): string
{
    $h = '<select name="' . e($name) . '"><option value="">– kein Projekt –</option>';
    foreach (q_all('SELECT id, name FROM projects WHERE active = 1 OR id = ? ORDER BY name', [$selected ?? 0]) as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $selected ? ' selected' : '') . '>' . e($p['name']) . '</option>';
    }
    return $h . '</select>';
}

const ABSENCE_TYPES = ['vacation' => 'Urlaub', 'sick' => 'Krankenstand', 'other' => 'Sonstiges'];
const ABSENCE_STATUS = ['pending' => 'Offen', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt'];
