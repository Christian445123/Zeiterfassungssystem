<?php
declare(strict_types=1);
// Cron: täglich  ->  0 4 * * *  php /pfad/zu/webpanel/cron_update.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/bootstrap.php';
ensure_migrated();
$r = panel_auto_tick(true);
echo ($r['msg'] ?? 'Nichts zu tun (Updates aus oder PANEL_UPDATE_URL fehlt).'), PHP_EOL;
exit(($r['type'] ?? '') === 'err' ? 1 : 0);
