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
        'db_host' => ['DB_HOST', 'localhost'], 'db_name' => ['DB_NAME', 'zeiterfassung'],
        'db_user' => ['DB_USER', 'root'], 'db_pass' => ['DB_PASS', ''],
        'timezone' => ['TIMEZONE', 'Europe/Vienna'], 'app_name' => ['APP_NAME', 'Zeiterfassung'],
        'token_lifetime_days' => ['TOKEN_LIFETIME_DAYS', '30'],
    ];
    [$envKey, $def] = $map[$key] ?? [strtoupper($key), null];
    return $GLOBALS['cfg'][$envKey] ?? $def;
}

date_default_timezone_set(cfg('timezone'));

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
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

function random_key(string $prefix, int $bytes = 24): string
{
    return $prefix . bin2hex(random_bytes($bytes));
}

function random_license_key(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parts = [];
    for ($i = 0; $i < 4; $i++) {
        $p = '';
        for ($j = 0; $j < 4; $j++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $p;
    }
    return implode('-', $parts);
}

function license_is_valid(array $lic): bool
{
    if (!(int)$lic['active']) {
        return false;
    }
    return $lic['expires_at'] === null || $lic['expires_at'] >= date('Y-m-d');
}

require __DIR__ . '/time.php';
