<?php
declare(strict_types=1);
/**
 * REST-API für den C#-Client.
 * Aufruf: api/index.php?route=<route>
 *
 * Header (jede Anfrage):
 *   X-Api-Key:    API-Key der Firma/Lizenz
 *   X-Machine-Id: eindeutige Geräte-ID (SHA-256-Hex)
 *   X-Auth-Token: Benutzer-Token (nach auth/login), für alle Routen außer license/* und auth/login
 */
require __DIR__ . '/../lib/bootstrap.php';

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

function hdr(string $name): string
{
    return trim((string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''));
}

try {
    $route = trim((string)($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'];
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }

    // ---- 1. API-Key + Lizenz prüfen ----
    $apiKey = hdr('X-Api-Key');
    if ($apiKey === '') {
        api_fail(401, 'API-Key fehlt.', 'api_key_missing');
    }
    $lic = q_one('SELECT l.*, k.id AS key_id FROM api_keys k JOIN licenses l ON l.id = k.license_id
                  WHERE k.key_hash = ? AND k.active = 1', [hash('sha256', $apiKey)]);
    if (!$lic) {
        api_fail(401, 'Ungültiger API-Key.', 'api_key_invalid');
    }
    q('UPDATE api_keys SET last_used = NOW() WHERE id = ?', [$lic['key_id']]);
    if (!license_is_valid($lic)) {
        api_fail(403, 'Lizenz ist abgelaufen oder deaktiviert.', 'license_invalid');
    }

    $machineId = strtolower(hdr('X-Machine-Id'));
    if (!preg_match('/^[a-f0-9]{64}$/', $machineId)) {
        api_fail(400, 'Ungültige Geräte-ID.', 'machine_id_invalid');
    }

    // ---- 2. Lizenz-Aktivierung (ohne bestehendes Gerät) ----
    if ($route === 'license/activate' && $method === 'POST') {
        if (!hash_equals($lic['license_key'], strtoupper(trim((string)($body['license_key'] ?? ''))))) {
            api_fail(403, 'Lizenzschlüssel passt nicht zum API-Key.', 'license_key_mismatch');
        }
        $dev = q_one('SELECT id FROM license_devices WHERE license_id = ? AND machine_id = ?', [$lic['id'], $machineId]);
        if (!$dev) {
            $count = (int)q_one('SELECT COUNT(*) c FROM license_devices WHERE license_id = ?', [$lic['id']])['c'];
            if ($count >= (int)$lic['max_devices']) {
                api_fail(403, 'Maximale Geräteanzahl der Lizenz erreicht.', 'device_limit');
            }
            q('INSERT INTO license_devices (license_id, machine_id, machine_name, last_seen) VALUES (?, ?, ?, NOW())',
                [$lic['id'], $machineId, mb_substr((string)($body['machine_name'] ?? ''), 0, 100)]);
        }
        api_ok(['customer' => $lic['customer'], 'expires_at' => $lic['expires_at']]);
    }

    // ---- 3. Gerät muss aktiviert sein ----
    $device = q_one('SELECT * FROM license_devices WHERE license_id = ? AND machine_id = ?', [$lic['id'], $machineId]);
    if (!$device) {
        api_fail(403, 'Gerät ist nicht aktiviert.', 'device_not_activated');
    }
    q('UPDATE license_devices SET last_seen = NOW() WHERE id = ?', [$device['id']]);

    if ($route === 'license/status' && $method === 'GET') {
        api_ok(['customer' => $lic['customer'], 'expires_at' => $lic['expires_at']]);
    }

    // ---- 4. Login ----
    if ($route === 'auth/login' && $method === 'POST') {
        $u = q_one('SELECT * FROM users WHERE username = ? AND active = 1', [trim((string)($body['username'] ?? ''))]);
        if (!$u || !password_verify((string)($body['password'] ?? ''), $u['password_hash'])) {
            usleep(700000); // Brute-Force bremsen
            api_fail(401, 'Benutzername oder Passwort falsch.', 'login_failed');
        }
        $token = random_key('', 32);
        $days = (int)cfg('token_lifetime_days');
        q('INSERT INTO api_tokens (user_id, device_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$u['id'], $device['id'], hash('sha256', $token), $days]);
        q('DELETE FROM api_tokens WHERE expires_at < NOW()');
        api_ok(['token' => $token, 'user' => ['id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role']]]);
    }

    // ---- 5. Ab hier: Benutzer-Token nötig ----
    $tok = hdr('X-Auth-Token');
    $auth = $tok === '' ? null : q_one('SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.device_id = ? AND t.expires_at > NOW() AND u.active = 1',
             [hash('sha256', $tok), $device['id']]);
    if (!$auth) {
        api_fail(401, 'Nicht angemeldet.', 'not_logged_in');
    }
    $uid = (int)$auth['id'];

    switch ($method . ' ' . $route) {
        case 'POST auth/logout':
            q('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $tok)]);
            api_ok();

        case 'GET status':
            api_ok(['status' => user_status($uid)]);

        case 'GET projects':
            api_ok(['projects' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']],
                q_all('SELECT id, name FROM projects WHERE active = 1 ORDER BY name'))]);

        case 'POST clock/in':
            $pid = isset($body['project_id']) && $body['project_id'] !== null ? (int)$body['project_id'] : null;
            clock_in($uid, $pid ?: null, (string)($body['note'] ?? ''), 'client');
            api_ok(['status' => user_status($uid)]);

        case 'POST clock/out':
            clock_out($uid);
            api_ok(['status' => user_status($uid)]);

        case 'POST break/start':
            break_start($uid);
            api_ok(['status' => user_status($uid)]);

        case 'POST break/end':
            break_end($uid);
            api_ok(['status' => user_status($uid)]);

        case 'GET entries':
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-14 days'));
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
            $rows = entries_between($uid, $from, $to);
            api_ok(['entries' => array_map(fn($r) => [
                'id' => (int)$r['id'],
                'start' => $r['start_time'],
                'end' => $r['end_time'],
                'project_name' => $r['project_name'],
                'note' => $r['note'],
                'break_seconds' => (int)$r['break_sec'],
                'worked_seconds' => (int)$r['worked_sec'],
            ], $rows)]);

        default:
            api_fail(404, 'Route nicht gefunden.', 'not_found');
    }
} catch (DomainException $ex) {
    api_fail(409, $ex->getMessage(), 'conflict');
} catch (Throwable $ex) {
    error_log('API error: ' . $ex);
    api_fail(500, 'Serverfehler.', 'server_error');
}
