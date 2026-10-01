<?php
declare(strict_types=1);

/**
 * Webpanel-Updates direkt aus GitHub (GITHUB_REPO / GITHUB_BRANCH in der .env).
 *
 * Zwei Wege, derselbe Button:
 *  1. Git-Checkout (Server hat `git clone` des Repos und PHP darf `git` ausführen):  git pull --ff-only
 *  2. Ohne Git (z. B. einfacher Webspace): ZIP des Branches von GitHub laden, Backup anlegen, Dateien ersetzen.
 * Danach laufen neue Datenbank-Migrationen (migrations/) automatisch.
 *
 * Automatisch nach jedem Push: GitHub-Webhook auf deploy-webhook.php (DEPLOY_WEBHOOK_SECRET in der .env).
 */

const PANEL_ROOT = __DIR__ . '/..';
/** Diese Pfade überschreibt der ZIP-Weg nie (der Git-Weg fasst ignorierte Dateien ohnehin nicht an). */
const PANEL_PROTECTED = ['.env', 'installed.lock'];

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

// ---------------------------------------------------------------- GitHub-Zugriff

function github_repo(): string
{
    $r = trim((string)cfg('github_repo'));
    return preg_match('#^[\w.\-]+/[\w.\-]+$#', $r) ? $r : '';
}

function github_branch(): string
{
    $b = trim((string)cfg('github_branch'));
    return preg_match('#^[\w./\-]+$#', $b) ? $b : 'main';
}

/** GET gegen GitHub (optional mit Token für private Repositories). Gibt den Body zurück oder speichert in $saveTo. */
function github_get(string $url, ?string $saveTo = null, int $maxBytes = 80000000): string
{
    if (!str_starts_with($url, 'https://')) {
        throw new RuntimeException('Nur https:// ist erlaubt.');
    }
    $headers = ['User-Agent: Zeiterfassung-Updater', 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    $token = trim((string)cfg('github_token'));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = $saveTo !== null ? fopen($saveTo, 'wb') : null;
        $opts = [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $headers];
        $opts[$fp ? CURLOPT_FILE : CURLOPT_RETURNTRANSFER] = $fp ?: true;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fp) {
            fclose($fp);
        }
        if ($body === false) {
            throw new RuntimeException('GitHub nicht erreichbar: ' . $err);
        }
        $body = $fp ? '' : (string)$body;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 180, 'header' => implode("\r\n", $headers), 'follow_location' => 1, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($data === false) {
            throw new RuntimeException('GitHub nicht erreichbar.');
        }
        $code = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        $body = $data;
        if ($saveTo !== null && $code < 400) {
            file_put_contents($saveTo, $data);
            $body = '';
        }
    }
    if ($code >= 400) {
        if ($saveTo !== null) {
            @unlink($saveTo);
        }
        throw new RuntimeException(match (true) {
            $code === 404 => 'Repository oder Branch nicht gefunden (' . github_repo() . ' / ' . github_branch() . '). Ist das Repository privat, wird GITHUB_TOKEN in der .env benötigt.',
            $code === 401 => 'GitHub-Token ist ungültig oder abgelaufen.',
            $code === 403 => 'GitHub verweigert den Zugriff (Abfragelimit erreicht oder Token ohne Leserecht). Später erneut versuchen.',
            default => "GitHub-Fehler $code.",
        });
    }
    if ($saveTo !== null && filesize($saveTo) > $maxBytes) {
        @unlink($saveTo);
        throw new RuntimeException('Download ist zu groß.');
    }
    return $body;
}

// ---------------------------------------------------------------- Git-Checkout (Weg 1)

/** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
function run_shell_command(string $command, string $cwd): array
{
    if (!function_exists('proc_open')) {
        return [1, '', 'proc_open() ist auf diesem Server deaktiviert.'];
    }
    // komplette Umgebung durchreichen (Proxy, SystemRoot unter Windows, …); nur Git-Eigenheiten überschreiben
    $env = array_merge(getenv() ?: [], ['GIT_TERMINAL_PROMPT' => '0', 'HOME' => storage_dir('git-home')]);
    $p = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) {
        return [1, '', "Befehl konnte nicht gestartet werden: $command"];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string)$out, (string)$err];
}

function git_cmd(string $args): string
{
    return 'git -c safe.directory=' . escapeshellarg((string)realpath(PANEL_ROOT)) . ' ' . $args;
}

/** Ist dieser Ordner ein Git-Checkout, in dem PHP git ausführen darf? */
function panel_is_git(): bool
{
    static $r = null;
    if ($r === null) {
        $r = false;
        if (is_dir(PANEL_ROOT . '/.git') && function_exists('proc_open')) {
            [$code] = run_shell_command(git_cmd('rev-parse --is-inside-work-tree'), PANEL_ROOT);
            $r = $code === 0;
        }
    }
    return $r;
}

function panel_current_sha(): ?string
{
    if (panel_is_git()) {
        [$code, $out] = run_shell_command(git_cmd('rev-parse HEAD'), PANEL_ROOT);
        if ($code === 0 && preg_match('/^[0-9a-f]{40}/', trim($out))) {
            return trim($out);
        }
    }
    $s = setting_get('panel_sha', '');
    return $s !== '' ? $s : null;
}

/** git pull --ff-only mit denselben Schutzprüfungen wie im Webpanel der Mitgliederverwaltung. */
function panel_update_git(): array
{
    $log = "== Prüfe auf lokale Änderungen ==\n";
    [$code, $out, $err] = run_shell_command(git_cmd('status --porcelain'), PANEL_ROOT);
    if ($code !== 0) {
        return ['success' => false, 'log' => $log . "Git-Status nicht lesbar.\n$err"];
    }
    $tracked = array_values(array_filter(explode("\n", trim($out)), fn($l) => $l !== '' && !str_starts_with($l, '??')));
    if ($tracked) {
        return ['success' => false, 'log' => $log . "Abgebrochen: nicht committete Änderungen an versionierten Dateien:\n" . implode("\n", $tracked)
            . "\n\nBitte per SSH sichern/committen oder verwerfen (git checkout -- <Datei>), dann erneut versuchen.\n"];
    }
    $log .= "OK, keine lokalen Änderungen.\n\n== git pull --ff-only ==\n";
    // Quelle immer aus GITHUB_REPO (.env), nicht aus der im Checkout gespeicherten Remote-Adresse (kann veraltet/falsch sein)
    $url = 'https://github.com/' . github_repo() . '.git';
    $auth = '';
    if (($tok = trim((string)cfg('github_token'))) !== '') {
        $auth = ' -c ' . escapeshellarg('http.https://github.com/.extraheader=AUTHORIZATION: basic ' . base64_encode('x-access-token:' . $tok));
    }
    [$code, $out, $err] = run_shell_command(git_cmd(ltrim($auth) . ' pull --ff-only ' . escapeshellarg($url) . ' ' . escapeshellarg(github_branch())), PANEL_ROOT);
    $log .= $out . $err;
    if ($code !== 0) {
        $hint = (stripos($err, 'permission') !== false || stripos($err, 'unable to create') !== false)
            ? "\nBerechtigungsproblem: Der Webserver-Benutzer darf im Ordner .git nicht schreiben. Einmalig per SSH: chown -R <webuser>: " . escapeshellarg((string)realpath(PANEL_ROOT)) . "\n"
            : "\nMögliche Ursachen: Historie divergiert, kein Zugriff auf das (private) Repository (Deploy-Key/Token) oder falscher Branch.\n";
        return ['success' => false, 'log' => $log . "\ngit pull fehlgeschlagen (Exit-Code $code).$hint"];
    }
    return ['success' => true, 'log' => $log];
}

// ---------------------------------------------------------------- ZIP-Weg (ohne Git)

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
    $file = storage_dir('backups') . '/panel-' . date('Ymd-His') . '.zip';
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

/** Spielt ein Panel-ZIP ein (GitHub-Download oder Backup). Das Repository-Wurzelverzeichnis darf eine Ebene tiefer liegen (GitHub-ZIP). */
function panel_apply_zip(string $zipFile): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung „zip“ fehlt.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('ZIP-Datei kann nicht gelesen werden.');
    }
    $prefix = null;
    $hasIndex = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = str_replace('\\', '/', (string)$zip->getNameIndex($i));
        if ($n === 'VERSION' || preg_match('#^[^/]+/VERSION$#', $n)) {
            if ($prefix === null || $n === 'VERSION') {
                $prefix = substr($n, 0, -7);
            }
        }
        if ($n === 'index.php' || preg_match('#^[^/]+/index\.php$#', $n)) {
            $hasIndex[substr($n, 0, -9)] = true;
        }
    }
    $zip->close();
    if ($prefix === null || !isset($hasIndex[$prefix])) {
        throw new RuntimeException('Kein gültiges Webpanel-Paket (VERSION/index.php fehlen).');
    }
    $backup = panel_backup();
    try {
        $files = panel_extract_zip($zipFile, $prefix);
    } catch (Throwable $e) {
        try {
            panel_extract_zip($backup, '');
        } catch (Throwable) {
            // Rollback-Fehler: der Original-Fehler ist wichtiger
        }
        throw new RuntimeException('Update fehlgeschlagen (' . $e->getMessage() . ') – der vorherige Stand wurde wiederhergestellt.');
    }
    panel_prune_backups(5);
    return ['files' => $files, 'backup' => basename($backup)];
}

function panel_restore(string $name): array
{
    if (!preg_match('/^panel-\d{8}-\d{6}\.zip$/', $name)) {
        throw new RuntimeException('Ungültiger Backup-Name.');
    }
    $file = storage_dir('backups') . '/' . $name;
    if (!is_file($file)) {
        throw new RuntimeException('Backup nicht gefunden.');
    }
    $r = panel_apply_zip($file);
    setting_set('panel_last_update', date('d.m.Y H:i') . ' → Backup ' . $name);
    setting_set('panel_sha', '');
    migrate();
    return $r;
}

function panel_update_zip(?string $sha): array
{
    $repo = github_repo();
    $tmp = storage_dir('tmp') . '/panel-' . bin2hex(random_bytes(6)) . '.zip';
    $log = "== ZIP von GitHub laden ($repo, " . github_branch() . ") ==\n";
    try {
        github_get("https://api.github.com/repos/$repo/zipball/" . rawurlencode(github_branch()), $tmp);
        $r = panel_apply_zip($tmp);
    } finally {
        @unlink($tmp);
    }
    if ($sha) {
        setting_set('panel_sha', $sha);
    }
    return ['success' => true, 'log' => $log . "{$r['files']} Dateien aktualisiert, Backup: {$r['backup']}\n"];
}

// ---------------------------------------------------------------- Prüfen & Aktualisieren

/** Neuester Commit des Branches auf GitHub; merkt sich das Ergebnis. */
function panel_check_remote(): array
{
    $repo = github_repo();
    if ($repo === '') {
        throw new RuntimeException('GITHUB_REPO ist in der .env nicht gesetzt (Format: Besitzer/Repository).');
    }
    $c = json_decode(github_get("https://api.github.com/repos/$repo/commits/" . rawurlencode(github_branch())), true);
    if (!is_array($c) || !preg_match('/^[0-9a-f]{40}$/', (string)($c['sha'] ?? ''))) {
        throw new RuntimeException('Unerwartete Antwort von GitHub.');
    }
    $latest = [
        'sha' => $c['sha'], 'short' => substr($c['sha'], 0, 7),
        'message' => mb_substr(strtok((string)($c['commit']['message'] ?? ''), "\n") ?: '', 0, 200),
        'date' => (string)($c['commit']['committer']['date'] ?? ''), 'url' => (string)($c['html_url'] ?? ''),
    ];
    setting_set('panel_latest', json_encode($latest, JSON_UNESCAPED_UNICODE));
    setting_set('panel_checked_at', (string)time());
    $cur = panel_current_sha();
    return $latest + ['current' => $cur, 'available' => $cur !== $latest['sha']];
}

function panel_latest_known(): ?array
{
    $m = json_decode(setting_get('panel_latest', ''), true);
    if (!is_array($m) || empty($m['sha'])) {
        return null;
    }
    $cur = panel_current_sha();
    return $m + ['current' => $cur, 'available' => $cur !== $m['sha']];
}

/** Führt das Update aus (Git oder ZIP) und danach die Migrationen. Rückgabe: success, log, mode. */
function panel_update(): array
{
    set_time_limit(300);
    if (github_repo() === '') {
        return ['success' => false, 'mode' => '-', 'log' => "GITHUB_REPO ist in der .env nicht gesetzt.\n"];
    }
    try {
        $latest = panel_check_remote();
        $mode = panel_is_git() ? 'git' : 'zip';
        $r = $mode === 'git' ? panel_update_git() : panel_update_zip($latest['sha']);
    } catch (Throwable $e) {
        return ['success' => false, 'mode' => '-', 'log' => 'Fehler: ' . $e->getMessage() . "\n"];
    }
    $r['mode'] = $mode;
    if (!$r['success']) {
        return $r;
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    try {
        $ran = migrate();
        $r['log'] .= "\n== Datenbank ==\n" . ($ran ? 'Migrationen ausgeführt: ' . implode(', ', $ran) : 'Keine neuen Migrationen.') . "\n";
    } catch (Throwable $e) {
        $r['success'] = false;
        $r['log'] .= "\nDatei-Update ok, aber die Datenbank-Migration schlug fehl: " . $e->getMessage() . "\n";
        return $r;
    }
    $now = panel_current_sha() ?? $latest['sha'];
    setting_set('panel_last_update', date('d.m.Y H:i') . ' → ' . substr($now, 0, 7) . ' (' . $mode . ')');
    setting_set('panel_latest', json_encode($latest, JSON_UNESCAPED_UNICODE));
    $r['log'] .= "\nFertig – jetzt auf Stand " . substr($now, 0, 7) . ".\n";
    return $r;
}

/**
 * Fallback ohne Webhook: Einmal pro Tag prüfen (Dashboard eines Rolleninhabers bzw. Cron), im Modus „auto“ installieren.
 * Rückgabe: null oder ['type' => 'ok'|'err'|'info', 'msg' => string]
 */
function panel_auto_tick(bool $force = false): ?array
{
    $mode = setting_get('panel_update_mode', 'off');
    if ($mode === 'off' || github_repo() === '') {
        return null;
    }
    if (!$force && time() - (int)setting_get('panel_checked_at', '0') < 86400) {
        return null;
    }
    if (time() - (int)setting_get('panel_update_lock', '0') < 600) {
        return null;
    }
    setting_set('panel_update_lock', (string)time());
    try {
        $m = panel_check_remote();
        if (!$m['available']) {
            return ['type' => 'info', 'msg' => 'Panel ist aktuell (' . $m['short'] . ').'];
        }
        if ($mode === 'auto') {
            $r = panel_update();
            return ['type' => $r['success'] ? 'ok' : 'err', 'msg' => $r['success'] ? 'Automatisch aktualisiert auf ' . $m['short'] . ' – ' . $m['message'] : 'Automatisches Update fehlgeschlagen: ' . trim(substr($r['log'], -300))];
        }
        return ['type' => 'info', 'msg' => 'Neue Version auf GitHub: ' . $m['short'] . ' – ' . $m['message']];
    } catch (Throwable $e) {
        setting_set('panel_checked_at', (string)time());
        error_log('Panel-Update: ' . $e->getMessage());
        return ['type' => 'err', 'msg' => 'Update-Prüfung fehlgeschlagen: ' . $e->getMessage()];
    } finally {
        setting_set('panel_update_lock', '0');
    }
}
