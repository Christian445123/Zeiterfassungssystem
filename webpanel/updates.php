<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login(true);
set_time_limit(300);

try {
    ensure_migrated();
} catch (Throwable $ex) {
    flash('Datenbank-Migration fehlgeschlagen: ' . $ex->getMessage(), 'err');
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu groß (PHP-Limit upload_max_filesize = ' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_NO_FILE => 'Bitte eine ZIP-Datei auswählen.',
        default => 'Upload fehlgeschlagen (Code ' . $code . ').',
    };
}

function require_zip_upload(): string
{
    $code = (int)($_FILES['zip']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_text($code));
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung „zip“ fehlt.');
    }
    return $_FILES['zip']['tmp_name'];
}

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'save_settings':
                $pm = in_array($_POST['panel_update_mode'] ?? '', ['off', 'notify', 'auto'], true) ? $_POST['panel_update_mode'] : 'off';
                $cp = in_array($_POST['client_update_policy'] ?? '', ['off', 'notify', 'auto'], true) ? $_POST['client_update_policy'] : 'notify';
                setting_set('panel_update_mode', $pm);
                setting_set('client_update_policy', $cp);
                flash('Einstellungen gespeichert.');
                break;

            case 'panel_check':
                $m = panel_check_remote();
                flash($m['available'] ? "Neue Version {$m['version']} verfügbar." : 'Das Panel ist auf dem neuesten Stand.');
                break;

            case 'panel_install':
                $m = panel_check_remote(); // frisch holen, nichts aus dem Formular übernehmen
                if (!$m['available']) {
                    throw new RuntimeException('Kein neueres Update verfügbar.');
                }
                flash(panel_result_text(panel_install_remote($m)));
                break;

            case 'panel_upload':
                flash(panel_result_text(panel_apply_zip(require_zip_upload())));
                break;

            case 'panel_restore':
                flash(panel_result_text(panel_restore((string)($_POST['name'] ?? ''))));
                break;

            case 'client_upload':
                $ver = trim((string)($_POST['version'] ?? ''));
                if (!valid_version($ver)) {
                    throw new RuntimeException('Version im Format 1.2.3 angeben.');
                }
                if (q_one('SELECT id FROM client_releases WHERE version = ?', [$ver])) {
                    throw new RuntimeException("Version $ver existiert bereits.");
                }
                $tmp = require_zip_upload();
                $zip = new ZipArchive();
                if ($zip->open($tmp) !== true || $zip->locateName('Zeiterfassung.exe') === false) {
                    throw new RuntimeException('Das ZIP muss Zeiterfassung.exe im Hauptverzeichnis enthalten (Ausgabe von tools/build-release.ps1).');
                }
                $zip->close();
                $dest = client_release_path($ver);
                if (!move_uploaded_file($tmp, $dest)) {
                    throw new RuntimeException('Datei konnte nicht gespeichert werden (storage/ beschreibbar?).');
                }
                q('INSERT INTO client_releases (version, file_name, sha256, size_bytes, notes, mandatory) VALUES (?,?,?,?,?,?)',
                    [$ver, basename($dest), hash_file('sha256', $dest), filesize($dest), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000), isset($_POST['mandatory']) ? 1 : 0]);
                flash("Client-Version $ver veröffentlicht.");
                break;

            case 'client_toggle':
                q('UPDATE client_releases SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
                break;

            case 'client_delete':
                $r = q_one('SELECT version FROM client_releases WHERE id = ?', [(int)$_POST['id']]);
                if ($r) {
                    @unlink(client_release_path($r['version']));
                    q('DELETE FROM client_releases WHERE id = ?', [(int)$_POST['id']]);
                    flash('Release gelöscht.');
                }
                break;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('updates.php');
}

$panelMode = setting_get('panel_update_mode', 'off');
$clientPolicy = setting_get('client_update_policy', 'notify');
$latest = panel_latest_known();
$checkedAt = (int)setting_get('panel_checked_at', '0');
$manifestUrl = trim((string)cfg('panel_update_url'));
$releases = q_all('SELECT * FROM client_releases ORDER BY id DESC');
$activeRel = client_releases_active();
$backups = panel_backups();
$modes = ['off' => 'Aus', 'notify' => 'Nur benachrichtigen', 'auto' => 'Automatisch installieren'];

page_header('Updates', 'upd');
?>
<div class="cards">
    <div class="card"><div class="lbl">Panel-Version</div><div class="val"><?= e(panel_version()) ?></div>
        <div class="muted"><?= e(setting_get('panel_last_update', 'noch nie aktualisiert')) ?></div></div>
    <div class="card"><div class="lbl">Neueste Client-Version</div><div class="val"><?= $activeRel ? e($activeRel[0]['version']) : '–' ?></div>
        <div class="muted"><?= count($activeRel) ?> aktive Release(s)</div></div>
    <div class="card"><div class="lbl">Server-Voraussetzungen</div>
        <div>ZIP-Erweiterung: <b><?= class_exists('ZipArchive') ? '✓' : '✗ fehlt' ?></b></div>
        <div>Panel-Ordner beschreibbar: <b><?= is_writable(PANEL_ROOT) ? '✓' : '✗ nein' ?></b></div>
        <div>Upload-Limit: <b><?= e(ini_get('upload_max_filesize')) ?></b></div></div>
</div>

<h2>Einstellungen</h2>
<form method="post" class="card stack" style="max-width:640px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <label>Webpanel-Updates
        <select name="panel_update_mode"><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $panelMode === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div class="muted">„Automatisch“ prüft täglich und installiert neue Versionen selbst (per Cron <code>php cron_update.php</code> oder, ohne Cron, beim Öffnen des Dashboards durch einen Admin). Benötigt <code>PANEL_UPDATE_URL</code> in der .env.</div>
    <label>Vorgabe für die Desktop-Anwendung (Clients)
        <select name="client_update_policy"><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $clientPolicy === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div class="muted">Gilt für Clients, die in ihren Einstellungen „Vorgabe des Servers“ gewählt haben. Als <b>Pflicht-Update</b> markierte Versionen werden immer installiert.</div>
    <div><button>Speichern</button></div>
</form>

<h2>Desktop-Anwendung (Client)</h2>
<form method="post" enctype="multipart/form-data" class="card row filter">
    <?= csrf_field() ?><input type="hidden" name="action" value="client_upload">
    <label>Version <input name="version" placeholder="1.1.0" required style="width:100px"></label>
    <label>ZIP (aus tools/build-release.ps1) <input type="file" name="zip" accept=".zip" required></label>
    <label>Änderungen <input name="notes" maxlength="2000" style="width:260px"></label>
    <label class="check" style="flex-direction:row"><input type="checkbox" name="mandatory"> Pflicht-Update</label>
    <button>Veröffentlichen</button>
</form>
<table>
    <tr><th>Version</th><th>Größe</th><th>SHA-256</th><th>Änderungen</th><th>Pflicht</th><th>Status</th><th>Datum</th><th></th></tr>
    <?php foreach ($releases as $r): ?>
        <tr><td><b><?= e($r['version']) ?></b></td><td><?= e(number_format((int)$r['size_bytes'] / 1024, 0, ',', '.')) ?> KB</td>
            <td><code><?= e(substr($r['sha256'], 0, 12)) ?>…</code></td><td><?= e($r['notes']) ?></td>
            <td><?= $r['mandatory'] ? 'Ja' : '' ?></td><td><?= $r['active'] ? 'Aktiv' : 'Zurückgezogen' ?></td><td><?= e(fmt_dt($r['created_at'])) ?></td>
            <td class="nowrap"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="link" name="action" value="client_toggle"><?= $r['active'] ? 'Zurückziehen' : 'Aktivieren' ?></button>
                <button class="link danger" name="action" value="client_delete" onclick="return confirm('Release endgültig löschen?')">Löschen</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$releases): ?><tr><td colspan="8" class="muted">Noch kein Release veröffentlicht.</td></tr><?php endif; ?>
</table>

<h2>Webpanel</h2>
<div class="card">
    <p>Update-Quelle: <?= $manifestUrl !== '' ? '<code>' . e($manifestUrl) . '</code>' : '<span class="muted">nicht gesetzt (PANEL_UPDATE_URL in der .env)</span>' ?>
        · Zuletzt geprüft: <?= $checkedAt ? e(date('d.m.Y H:i', $checkedAt)) : 'nie' ?></p>
    <?php if ($latest && $latest['available']): ?>
        <div class="flash ok"><b>Version <?= e($latest['version']) ?> verfügbar.</b> <?= e($latest['notes']) ?></div>
    <?php elseif ($latest): ?>
        <p class="muted">Neueste bekannte Version: <?= e($latest['version']) ?> – du bist aktuell.</p>
    <?php endif; ?>
    <div class="row filter">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="panel_check"><button <?= $manifestUrl === '' ? 'disabled' : '' ?>>Jetzt prüfen</button></form>
        <?php if ($latest && $latest['available']): ?>
            <form method="post" onsubmit="return confirm('Panel jetzt auf <?= e($latest['version']) ?> aktualisieren? Vorher wird automatisch ein Backup angelegt.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="panel_install"><button class="go">Auf <?= e($latest['version']) ?> aktualisieren</button></form>
        <?php endif; ?>
    </div>

    <h3>Update-Paket manuell hochladen</h3>
    <form method="post" enctype="multipart/form-data" class="row filter" onsubmit="return confirm('Update-Paket einspielen? Vorher wird automatisch ein Backup angelegt.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="panel_upload">
        <label>webpanel-x.y.z.zip <input type="file" name="zip" accept=".zip" required></label><button>Hochladen &amp; installieren</button>
    </form>

    <h3>Backups (letzte 5)</h3>
    <table>
        <?php foreach ($backups as $b): ?>
            <tr><td><code><?= e($b) ?></code></td>
                <td><form method="post" onsubmit="return confirm('Diesen Stand wiederherstellen?')"><?= csrf_field() ?><input type="hidden" name="action" value="panel_restore"><input type="hidden" name="name" value="<?= e($b) ?>"><button class="link">Wiederherstellen</button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?><tr><td class="muted">Noch keine Backups.</td></tr><?php endif; ?>
    </table>
    <p class="muted">Ein Update ersetzt nur Programmdateien. <code>.env</code>, <code>storage/</code> und die Datenbank-Inhalte bleiben unangetastet; neue Datenbank-Migrationen laufen automatisch.</p>
</div>
<?php page_footer();
