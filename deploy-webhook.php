<?php
declare(strict_types=1);

/**
 * GitHub-Webhook: aktualisiert das Webpanel automatisch nach jedem Push auf den Branch (GITHUB_BRANCH).
 * Macht dasselbe wie der Button „Jetzt aktualisieren“ unter Updates (git pull bzw. ZIP von GitHub, danach Migrationen).
 *
 * Einrichtung (einmalig):
 *   1. In der .env:  DEPLOY_WEBHOOK_SECRET=<langer Zufallswert>
 *   2. GitHub → Repository → Settings → Webhooks → Add webhook
 *        Payload URL:  https://<domain>/deploy-webhook.php
 *        Content type: application/json
 *        Secret:       derselbe Wert
 *        Ereignisse:   „Just the push event“
 *
 * Ohne Secret lehnt das Skript jede Anfrage ab (fail closed).
 */

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function hook_fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hook_fail(405, 'Nur POST erlaubt.');
}
$secret = trim((string)cfg('deploy_webhook_secret'));
if ($secret === '') {
    hook_fail(503, 'Webhook ist nicht eingerichtet.');
}
$payload = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($sig === '' || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $sig)) {
    error_log('deploy-webhook: ungültige Signatur');
    hook_fail(401, 'Ungültige Signatur.');
}
$event = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($event === 'ping') {
    echo json_encode(['ok' => true, 'message' => 'pong']);
    exit;
}
if ($event !== 'push') {
    echo json_encode(['ok' => true, 'message' => 'Ereignis ignoriert: ' . $event]);
    exit;
}
$data = json_decode($payload, true);
if (!is_array($data) || ($data['ref'] ?? '') !== 'refs/heads/' . github_branch()) {
    echo json_encode(['ok' => true, 'message' => 'Push auf anderen Branch ignoriert.']);
    exit;
}

// nur ein Update gleichzeitig
$lock = @fopen(storage_dir('tmp') . '/deploy.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'message' => 'Ein Update läuft bereits, übersprungen.']);
    exit;
}
try {
    ensure_migrated();
    $r = panel_update();
    error_log('deploy-webhook: Update ' . ($r['success'] ? 'ok' : 'FEHLER'));
    echo json_encode(['ok' => $r['success'], 'mode' => $r['mode'], 'log' => $r['log']], JSON_UNESCAPED_UNICODE);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
