<?php
declare(strict_types=1);

/** Lädt .env (KEY=VALUE, # Kommentare, optional "quoted"). Suche: webpanel/.env, sonst eine Ebene darüber. */
function load_env(): array
{
    $env = [];
    foreach ([__DIR__ . '/../.env', __DIR__ . '/../../.env'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $env[trim($k)] = $v;
        }
        return $env;
    }
    http_response_code(500);
    exit('.env fehlt – .env.example nach .env kopieren und ausfüllen.');
}

$GLOBALS['cfg'] = load_env();

function cfg(string $key): ?string
{
    static $map = [
        'db_host' => ['DB_HOST', 'localhost'], 'db_port' => ['DB_PORT', '3306'], 'db_name' => ['DB_NAME', 'zeiterfassung'],
        'db_user' => ['DB_USER', 'root'], 'db_pass' => ['DB_PASS', ''],
        'timezone' => ['TIMEZONE', 'Europe/Vienna'], 'app_name' => ['APP_NAME', 'Zeiterfassung'],
        'token_lifetime_days' => ['TOKEN_LIFETIME_DAYS', '30'], 'github_branch' => ['GITHUB_BRANCH', 'main'],
        'vacation_weeks' => ['VACATION_WEEKS', '5'], 'entry_edit_days' => ['ENTRY_EDIT_DAYS', '31'], 'holidays' => ['HOLIDAYS', 'AT'],
    ];
    [$envKey, $def] = $map[$key] ?? [strtoupper($key), null];
    return $GLOBALS['cfg'][$envKey] ?? $def;
}

date_default_timezone_set(cfg('timezone'));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';port=' . (int)cfg('db_port') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // MySQL-Zeitzone = PHP-Zeitzone, damit NOW() und date() übereinstimmen
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_hm(int $sec): string
{
    $neg = $sec < 0;
    $sec = abs($sec);
    return ($neg ? '-' : '') . intdiv($sec, 3600) . ':' . str_pad((string)intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT);
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d.m.Y H:i', strtotime($dt)) : '–';
}

function fmt_d(?string $d): string
{
    return $d ? date('d.m.Y', strtotime($d)) : '–';
}

require __DIR__ . '/time.php';
require __DIR__ . '/rbac.php';
require __DIR__ . '/hr.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/business.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/updater.php';
require __DIR__ . '/admin_sys.php';

/**
 * Nie eine leere Seite: unbehandelte Fehler werden geloggt und mit Hinweis angezeigt.
 * Details (Fehlertext) nur mit APP_DEBUG=1 in der .env.
 */
set_exception_handler(function (Throwable $e): void {
    error_log('Zeiterfassung: ' . $e);
    $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? $e->getCode()) : 0;
    $hint = match (true) {
        $code === 1146 => 'Die Datenbank-Tabellen fehlen. Bitte zuerst install.php im Browser aufrufen.',
        $code === 1045 => 'Datenbank-Zugang abgelehnt. Bitte DB_USER und DB_PASS in der .env prüfen.',
        in_array($code, [1044, 1049], true) => 'Die Datenbank existiert nicht oder der Benutzer hat keinen Zugriff darauf. Bitte DB_NAME und DB_USER in der .env prüfen.',
        in_array($code, [2002, 2006], true) => 'Der Datenbank-Server ist nicht erreichbar. Bitte DB_HOST (und ggf. DB_PORT) in der .env prüfen.',
        $e instanceof PDOException => 'Datenbankfehler. Details stehen im PHP-Error-Log (oder APP_DEBUG=1 in der .env setzen).',
        default => 'Unerwarteter Fehler. Details stehen im PHP-Error-Log (oder APP_DEBUG=1 in der .env setzen).',
    };
    $detail = cfg('app_debug') === '1' ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $hint . ($detail ? "\n" . $detail : '') . "\n");
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title><link rel="stylesheet" href="' . css_url() . '"></head>'
        . '<body><main style="max-width:560px;margin-top:60px"><h1>Es ist ein Fehler aufgetreten</h1>'
        . '<div class="flash err">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</div>'
        . ($detail !== '' ? '<pre style="white-space:pre-wrap">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>' : '')
        . '</main></body></html>';
});

/** URL der Stylesheet-Datei mit Änderungszeit, damit Browser nach einem Update nie eine veraltete Version aus dem Cache nehmen. */
function css_url(): string
{
    $f = __DIR__ . '/../assets/style.css';
    return 'assets/style.css?v=' . (is_file($f) ? filemtime($f) : 0);
}
