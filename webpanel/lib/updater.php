<?php
declare(strict_types=1);

/**
 * Update-Logik:
 *  - Webpanel: Manifest prüfen (PANEL_UPDATE_URL in .env) oder ZIP hochladen -> Backup -> Dateien ersetzen -> Migrationen
 *  - Client-Releases: ZIPs liegen in storage/releases/ und werden über die API an die Clients verteilt
 */

const PANEL_ROOT = __DIR__ . '/..';
/** Diese Pfade überschreibt ein Panel-Update nie. */
const PANEL_PROTECTED = ['.env', 'installed.lock', 'install.php'];

function valid_version(string $v): bool
{
    return (bool)preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $v);
}

function panel_version(): string
{
    $v = trim((string)@file_get_contents(PANEL_ROOT . '/VERSION'));
    return valid_version($v) ? $v : '0.0.0';
}

function storage_dir(string $sub): string
{
    $base = PANEL_ROOT . '/storage';
    if (!is_dir($base)) {
        mkdir($base, 0750, true);
    }
    if (!is_file($base . '/.htaccess')) {
        file_put_contents($base . '/.htaccess', "Require all denied\n");
    }
    $dir = $base . '/' . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function http_get(string $url, ?string $saveTo = null, int $maxBytes = 50000000): string
{
    if (!preg_match('#^https://#i', $url)) {
        throw new RuntimeException('Update-URL muss mit https:// beginnen.');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = $saveTo !== null ? fopen($saveTo, 'wb') : null;
        $opts = [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Zeiterfassung-Updater', CURLOPT_FAILONERROR => true,
        ];
        $opts[$fp ? CURLOPT_FILE : CURLOPT_RETURNTRANSFER] = $fp ?: true;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fp) {
            fclose($fp);
        }
        if ($body === false) {
            throw new RuntimeException('Download fehlgeschlagen: ' . $err);
        }
        $body = $fp ? '' : (string)$body;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 180, 'user_agent' => 'Zeiterfassung-Updater', 'follow_location' => 1, 'max_redirects' => 3]]);
        $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($data === false) {
            throw new RuntimeException('Download fehlgeschlagen (URL nicht erreichbar).');
        }
        if ($saveTo !== null) {
            file_put_contents($saveTo, $data);
            $body = '';
        } else {
            $body = $data;
        }
    }
    if ($saveTo !== null && filesize($saveTo) > $maxBytes) {
        @unlink($saveTo);
        throw new RuntimeException('Update-Datei ist zu groß.');
    }
    return $body;
}

// ---------------------------------------------------------------- Panel-Update

/** Holt das Manifest {"version","url","sha256","notes"} und merkt es sich. */
function panel_check_remote(): array
{
    $url = trim((string)cfg('panel_update_url'));
    if ($url === '') {
        throw new RuntimeException('PANEL_UPDATE_URL ist in der .env nicht gesetzt.');
    }
    $raw = http_get($url, null, 200000);
    $m = json_decode(preg_replace("/^\xEF\xBB\xBF/", "", $raw), true); // BOM tolerieren
    if (!is_array($m) || !valid_version((string)($m['version'] ?? '')) || !preg_match('#^https://#i', (string)($m['url'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/i', (string)($m['sha256'] ?? ''))) {
        throw new RuntimeException('Ungültiges Update-Manifest.');
    }
    $m = ['version' => $m['version'], 'url' => $m['url'], 'sha256' => strtolower($m['sha256']),
        'notes' => mb_substr((string)($m['notes'] ?? ''), 0, 2000)];
    setting_set('panel_latest', json_encode($m, JSON_UNESCAPED_UNICODE));
    setting_set('panel_checked_at', (string)time());
    return $m + ['available' => version_compare($m['version'], panel_version(), '>')];
}

/** Zuletzt bekanntes Manifest (ohne neue Abfrage) oder null. */
function panel_latest_known(): ?array
{
    $m = json_decode(setting_get('panel_latest', ''), true);
    if (!is_array($m) || !valid_version((string)($m['version'] ?? ''))) {
        return null;
    }
    return $m + ['available' => version_compare($m['version'], panel_version(), '>')];
}

function panel_install_remote(array $m): array
{
    $tmp = storage_dir('tmp') . '/panel-' . bin2hex(random_bytes(6)) . '.zip';
    try {
        http_get($m['url'], $tmp);
        if (!hash_equals($m['sha256'], (string)hash_file('sha256', $tmp))) {
            throw new RuntimeException('Prüfsumme (SHA-256) stimmt nicht – Update abgebrochen.');
        }
        return panel_apply_zip($tmp, false, $m['version']);
    } finally {
        @unlink($tmp);
    }
}

function panel_is_protected(string $rel): bool
{
    return in_array($rel, PANEL_PROTECTED, true) || str_starts_with($rel, 'storage/') || str_starts_with($rel, '.git');
}

function safe_rel_path(string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] === '/' || str_contains($rel, ':') || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
        return null;
    }
    return $rel;
}

function panel_backup(): string
{
    $file = storage_dir('backups') . '/panel-' . panel_version() . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Backup konnte nicht angelegt werden (storage/ nicht beschreibbar?).');
    }
    $root = realpath(PANEL_ROOT);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (!panel_is_protected($rel)) {
            $zip->addFile($f->getPathname(), $rel);
        }
    }
    $zip->close();
    return $file;
}

function panel_backups(): array
{
    $list = glob(storage_dir('backups') . '/panel-*.zip') ?: [];
    rsort($list);
    return array_map('basename', $list);
}

function panel_prune_backups(int $keep): void
{
    foreach (array_slice(panel_backups(), $keep) as $name) {
        @unlink(storage_dir('backups') . '/' . $name);
    }
}

function panel_extract_zip(string $zipFile, string $prefix): int
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('ZIP-Datei kann nicht gelesen werden.');
    }
    $root = realpath(PANEL_ROOT);
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            continue;
        }
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || str_ends_with($rel, '/')) {
            continue;
        }
        $rel = safe_rel_path($rel);
        if ($rel === null) {
            throw new RuntimeException('Unsicherer Pfad im Update: ' . $name);
        }
        if (panel_is_protected($rel)) {
            continue;
        }
        $data = $zip->getFromIndex($i);
        if ($data === false) {
            throw new RuntimeException('Datei im ZIP nicht lesbar: ' . $rel);
        }
        $target = $root . '/' . $rel;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
            throw new RuntimeException('Ordner kann nicht angelegt werden: ' . dirname($rel));
        }
        $tmp = $target . '.upd';
        if (file_put_contents($tmp, $data) === false) {
            throw new RuntimeException('Keine Schreibrechte für ' . $rel);
        }
        // rename() ist atomar; unter Windows scheitert es bei gerade ausgeführten Dateien -> dann direkt überschreiben
        if (!@rename($tmp, $target) && !@copy($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Datei kann nicht ersetzt werden: ' . $rel);
        }
        @unlink($tmp);
        $count++;
    }
    $zip->close();
    return $count;
}

/** Spielt ein Panel-ZIP ein. Rückgabe: version, files, migrations, backup. */
function panel_apply_zip(string $zipFile, bool $allowOlder = false, ?string $expectVersion = null): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung „zip“ fehlt.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('ZIP-Datei kann nicht gelesen werden.');
    }
    $prefix = null;
    $verIndex = null;
    $hasIndex = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($n === 'VERSION' || preg_match('#^[^/]+/VERSION$#', $n)) {
            if ($prefix === null || $n === 'VERSION') {
                $prefix = substr($n, 0, -7);
                $verIndex = $i;
            }
        }
        if ($n === 'index.php' || preg_match('#^[^/]+/index\.php$#', $n)) {
            $hasIndex[substr($n, 0, -9)] = true;
        }
    }
    if ($prefix === null || !isset($hasIndex[$prefix])) {
        $zip->close();
        throw new RuntimeException('Kein gültiges Panel-Update (VERSION/index.php fehlen).');
    }
    $ver = trim((string)$zip->getFromIndex($verIndex));
    $zip->close();
    if (!valid_version($ver)) {
        throw new RuntimeException('Ungültige Version im Update-Paket.');
    }
    if ($expectVersion !== null && $ver !== $expectVersion) {
        throw new RuntimeException("Paket enthält Version $ver, erwartet wurde $expectVersion.");
    }
    if (!$allowOlder && version_compare($ver, panel_version(), '<=')) {
        throw new RuntimeException("Version $ver ist nicht neuer als die installierte (" . panel_version() . ').');
    }

    $backup = panel_backup();
    try {
        $files = panel_extract_zip($zipFile, $prefix);
    } catch (Throwable $e) {
        try {
            panel_extract_zip($backup, '');
        } catch (Throwable) {
            // Rollback-Fehler: Original-Fehler ist wichtiger
        }
        throw new RuntimeException('Update fehlgeschlagen (' . $e->getMessage() . ') – der vorherige Stand wurde wiederhergestellt.');
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    try {
        $ran = migrate();
    } catch (Throwable $e) {
        throw new RuntimeException("Dateien sind auf Version $ver, aber die Datenbank-Migration schlug fehl: " . $e->getMessage()
            . ' – Backup: ' . basename($backup));
    }
    setting_set('panel_last_update', date('d.m.Y H:i') . ' → ' . $ver);
    panel_prune_backups(5);
    return ['version' => $ver, 'files' => $files, 'migrations' => $ran, 'backup' => basename($backup)];
}

function panel_restore(string $name): array
{
    if (!preg_match('/^panel-\d+\.\d+\.\d+-\d{8}-\d{6}\.zip$/', $name)) {
        throw new RuntimeException('Ungültiger Backup-Name.');
    }
    $file = storage_dir('backups') . '/' . $name;
    if (!is_file($file)) {
        throw new RuntimeException('Backup nicht gefunden.');
    }
    return panel_apply_zip($file, true);
}

function panel_result_text(array $r): string
{
    return "Panel auf Version {$r['version']} aktualisiert ({$r['files']} Dateien, " . count($r['migrations'])
        . " Migrationen). Backup: {$r['backup']}";
}

/**
 * Automatik: prüft höchstens alle 24 h (oder $force) und installiert im Modus „auto“.
 * Wird von cron_update.php und (mangels Cron) beim Öffnen des Admin-Dashboards aufgerufen.
 * Rückgabe: null oder ['type' => 'ok'|'err'|'info', 'msg' => string]
 */
function panel_auto_tick(bool $force = false): ?array
{
    $mode = setting_get('panel_update_mode', 'off');
    if ($mode === 'off' || trim((string)cfg('panel_update_url')) === '') {
        return null;
    }
    if (!$force && time() - (int)setting_get('panel_checked_at', '0') < 86400) {
        return null;
    }
    if (time() - (int)setting_get('panel_update_lock', '0') < 600) {
        return null; // läuft gerade
    }
    setting_set('panel_update_lock', (string)time());
    try {
        $m = panel_check_remote();
        if (!$m['available']) {
            return ['type' => 'info', 'msg' => 'Panel ist aktuell (' . panel_version() . ').'];
        }
        if ($mode === 'auto') {
            return ['type' => 'ok', 'msg' => 'Automatisch: ' . panel_result_text(panel_install_remote($m))];
        }
        return ['type' => 'info', 'msg' => "Neue Panel-Version {$m['version']} verfügbar."];
    } catch (Throwable $e) {
        setting_set('panel_checked_at', (string)time()); // keine Endlosschleife bei Fehlern
        error_log('Panel-Update: ' . $e->getMessage());
        return ['type' => 'err', 'msg' => 'Automatisches Update fehlgeschlagen: ' . $e->getMessage()];
    } finally {
        setting_set('panel_update_lock', '0');
    }
}

// ---------------------------------------------------------------- Client-Releases

function client_release_path(string $version): string
{
    if (!valid_version($version)) {
        throw new InvalidArgumentException('Ungültige Version.');
    }
    return storage_dir('releases') . '/client-' . $version . '.zip';
}

/** Aktive Releases, neueste zuerst. */
function client_releases_active(): array
{
    $rows = q_all('SELECT * FROM client_releases WHERE active = 1');
    usort($rows, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $rows;
}
