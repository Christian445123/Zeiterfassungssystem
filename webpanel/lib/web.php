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

/** Hat der angemeldete Benutzer dieses Recht? */
function can(string $perm): bool
{
    $u = current_user();
    return $u !== null && user_can($u, $perm);
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

/** Login erforderlich; mit Rechte-Schlüsseln: mindestens eines davon muss vorhanden sein. */
function require_login(string ...$anyOf): array
{
    $u = current_user();
    if (!$u) {
        redirect('index.php');
    }
    ensure_migrated(); // neue Tabellen/Spalten nach einem Update automatisch anlegen
    if (!empty($u['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'password.php') {
        redirect('password.php'); // Start-/Standard-Passwort erst ändern
    }
    if ($anyOf) {
        $ok = false;
        foreach ($anyOf as $perm) {
            $ok = $ok || user_can($u, $perm);
        }
        if (!$ok) {
            http_response_code(403);
            page_header('Kein Zugriff');
            echo '<div class="flash err">Für diese Seite fehlt dir die Berechtigung.</div>';
            page_footer();
            exit;
        }
    }
    if (is_post()) {
        csrf_check();
    }
    return $u;
}

/** Filter/Kontext: Wer das Recht $perm hat, darf einen anderen Mitarbeiter wählen, sonst nur sich selbst. */
function selected_user_id(array $me, string $perm, string $param = 'user'): int
{
    if (user_can($me, $perm) && isset($_REQUEST[$param]) && (int)$_REQUEST[$param] > 0) {
        return (int)$_REQUEST[$param];
    }
    return (int)$me['id'];
}

function page_header(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = [];
    if ($u) {
        $c = fn(string ...$p): bool => (bool)array_filter($p, fn($x) => user_can($u, $x));
        $nav[] = ['dashboard.php', 'Übersicht', 'dash', true];
        $nav[] = ['schedule.php', 'Dienstplan', 'schedule', $c('schedule.view')];
        $nav[] = ['entries.php', 'Zeiten', 'entries', $c('hours.own', 'hours.view_all')];
        $nav[] = ['reports.php', 'Auswertung', 'reports', $c('hours.own', 'reports.view_all')];
        $nav[] = ['months.php', 'Monatsabschluss', 'months', $c('hours.own', 'hours.view_all', 'reports.view_all', 'months.close', 'months.close_own')];
        $nav[] = ['calendar.php', 'Urlaubsplaner', 'calendar', $c('schedule.view', 'absences.request', 'absences.manage')];
        $nav[] = ['absences.php', 'Abwesenheiten', 'abs', $c('absences.request', 'absences.manage')];
        $nav[] = ['users.php', 'Mitarbeiter', 'users', $c('users.view', 'users.manage')];
        $nav[] = ['roles.php', 'Rollen & Rechte', 'roles', $c('roles.manage')];
        $nav[] = ['business.php', 'Betrieb', 'business', $c('business.manage')];
        $nav[] = ['updates.php', 'Updates', 'upd', $c('updates.manage')];
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' – ' . e(cfg('app_name')) . '</title><link rel="stylesheet" href="' . e(css_url()) . '"></head><body' . ($u ? ' class="app"' : '') . '>';
    if ($u) {
        echo '<aside class="side"><div class="brand"><span class="logo">⏱</span> ' . e(cfg('app_name')) . '</div><nav>';
        foreach ($nav as [$href, $label, $key, $show]) {
            if ($show) {
                echo '<a href="' . $href . '"' . ($key === $active ? ' class="on"' : '') . '>' . e($label) . '</a>';
            }
        }
        echo '</nav><div class="who"><b>' . e($u['full_name']) . '</b><br><span class="muted">' . e(user_role($u)['name']) . ' · Nr. ' . e($u['personnel_number'] ?? '') . '</span>'
            . '<br><a href="password.php">Passwort</a> · <a href="logout.php">Abmelden</a></div></aside><div class="content">';
    }
    echo '<main><h1>' . e($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="flash ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function page_footer(): void
{
    echo '<footer class="foot">' . e(cfg('app_name')) . ' v' . e(panel_version()) . '</footer></main>';
    echo current_user() ? '</div></body></html>' : '</body></html>';
}

function user_select(string $name, int $selected, bool $onlyActive = true, string $extra = ''): string
{
    $rows = q_all('SELECT id, full_name, personnel_number FROM users ' . ($onlyActive ? 'WHERE active = 1 ' : '') . 'ORDER BY full_name');
    $h = '<select name="' . e($name) . '" ' . $extra . '>';
    foreach ($rows as $r) {
        $h .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $selected ? ' selected' : '') . '>' . e($r['full_name']) . ' (' . e($r['personnel_number'] ?? '') . ')</option>';
    }
    return $h . '</select>';
}

