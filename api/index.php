<?php
declare(strict_types=1);
/**
 * REST-API für die Mobile Apps (iOS/Android).   Aufruf: api/index.php?route=<route>
 *
 * Anmeldung:  POST auth/login {login, password, device}  →  {token, user}
 * Danach bei jeder Anfrage:  Header  X-Auth-Token: <token>   (oder Authorization: Bearer <token>)
 * Antworten sind JSON: {"ok":true,…} bzw. {"ok":false,"error":"…","code":"…"}.
 * Jede Route prüft die Rechte des Benutzers (Rolle) – dieselben Regeln wie im Webpanel.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/api_routes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_fail(int $code, string $msg, string $errCode = 'error'): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg, 'code' => $errCode], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(array $data = []): never
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function api_header(string $name): string
{
    return trim((string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''));
}

try {
    $route = trim((string)($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'];
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    // ---- öffentlich: Verbindungstest (Server-Adresse prüfen) ----
    if ($route === 'info' && $method === 'GET') {
        api_ok(['app' => cfg('app_name'), 'version' => panel_version(), 'api' => 1, 'timezone' => cfg('timezone'),
            'app_update' => app_update_info()]);
    }

    ensure_migrated();

    // ---- Anmeldung mit Personalnummer ----
    if ($route === 'auth/login' && $method === 'POST') {
        $login = trim((string)($body['login'] ?? ''));
        $key = hash('sha256', strtolower($login) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        // Passwort-Raten bremsen: höchstens 10 Fehlversuche je Nummer+Adresse in 15 Minuten
        $fails = (int)q_one('SELECT COUNT(*) c FROM login_attempts WHERE attempt_key = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)', [$key])['c'];
        if ($fails >= 10) {
            api_fail(429, 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.', 'too_many_attempts');
        }
        $u = q_one('SELECT * FROM users WHERE (personnel_number = ? OR username = ?) AND active = 1', [$login, $login]);
        if (!$u || !password_verify((string)($body['password'] ?? ''), $u['password_hash'])) {
            q('INSERT INTO login_attempts (attempt_key) VALUES (?)', [$key]);
            usleep(700000);
            api_fail(401, 'Personalnummer oder Passwort falsch.', 'login_failed');
        }
        q('DELETE FROM login_attempts WHERE attempt_key = ? OR attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)', [$key]);
        $token = bin2hex(random_bytes(32));
        q('INSERT INTO app_tokens (user_id, token_hash, device, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$u['id'], hash('sha256', $token), mb_substr((string)($body['device'] ?? ''), 0, 100), (int)cfg('token_lifetime_days')]);
        q('DELETE FROM app_tokens WHERE expires_at < NOW()');
        api_ok(['token' => $token, 'user' => api_user($u)]);
    }

    // ---- ab hier: Token nötig ----
    $tok = api_header('X-Auth-Token');
    if ($tok === '' && preg_match('/^Bearer\s+(\S+)/i', api_header('Authorization'), $m)) {
        $tok = $m[1];
    }
    $auth = $tok === '' ? null : q_one('SELECT u.* FROM app_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.active = 1', [hash('sha256', $tok)]);
    if (!$auth) {
        api_fail(401, 'Nicht angemeldet oder Sitzung abgelaufen.', 'not_logged_in');
    }
    q('UPDATE app_tokens SET last_used = NOW() WHERE token_hash = ?', [hash('sha256', $tok)]);
    // Start-/Standardpasswort: bis zur Änderung nur me, auth/password und auth/logout
    if (!empty($auth['must_change_password']) && !in_array($route, ['me', 'auth/password', 'auth/logout'], true)) {
        api_fail(403, 'Bitte zuerst das Passwort ändern.', 'password_change_required');
    }

    api_dispatch($method, $route, $body, $auth, $tok);
    api_fail(404, 'Route nicht gefunden.', 'not_found');
} catch (PermissionException $ex) {
    api_fail(403, $ex->getMessage(), 'forbidden');
} catch (DomainException $ex) {
    api_fail(409, $ex->getMessage(), 'conflict');
} catch (Throwable $ex) {
    error_log('API-Fehler: ' . $ex);
    api_fail(500, 'Serverfehler.', 'server_error');
}
