<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('updates.manage');
set_time_limit(300);

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'save_settings':
                updates_save_settings($me, (string)($_POST['panel_update_mode'] ?? ''));
                flash('Einstellungen gespeichert.');
                break;
            case 'check':
                $m = panel_check_remote();
                flash($m['available'] ? 'Neue Version auf GitHub: ' . $m['short'] . ' – ' . $m['message'] : 'Das Webpanel ist auf dem neuesten Stand (' . $m['short'] . ').');
                break;
            case 'update':
                $_SESSION['update_result'] = panel_update(); // Ergebnis nach dem Redirect anzeigen (frische Anfrage = frischer Code)
                break;
            case 'restore':
                $r = panel_restore((string)($_POST['name'] ?? ''));
                flash("Backup wiederhergestellt ({$r['files']} Dateien).");
                break;
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('updates.php');
}

$result = $_SESSION['update_result'] ?? null;
unset($_SESSION['update_result']);
$u = updates_overview();
$modes = ['off' => 'Aus', 'notify' => 'Nur benachrichtigen', 'auto' => 'Automatisch installieren'];
$host = ($_SERVER['HTTPS'] ?? '') === 'off' || empty($_SERVER['HTTPS']) ? 'http' : 'https';
$webhookUrl = $host . '://' . ($_SERVER['HTTP_HOST'] ?? 'dein-server') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . $u['webhook_path'];

page_header('Updates', 'upd');
?>
<?php if ($result): ?>
    <div class="flash <?= $result['success'] ? 'ok' : 'err' ?>"><?= $result['success'] ? 'Update erfolgreich.' : 'Update fehlgeschlagen – siehe Protokoll.' ?></div>
    <pre class="log"><?= e($result['log']) ?></pre>
<?php endif; ?>

<div class="cards">
    <div class="card"><div class="lbl">Webpanel</div>
        <div class="val"><?= $u['current_sha'] ? e(substr($u['current_sha'], 0, 7)) : 'unbekannt' ?></div>
        <div class="muted">Version <?= e($u['version']) ?> · <?= e($u['last_update'] ?: 'noch nie über den Button aktualisiert') ?></div></div>
    <div class="card"><div class="lbl">Neuester Stand auf GitHub</div>
        <?php if ($u['latest']): ?>
            <div class="val"><?= e($u['latest']['short'] ?? substr($u['latest']['sha'], 0, 7)) ?> <?= $u['latest']['available'] ? '<span class="tag pending">neu</span>' : '<span class="tag approved">aktuell</span>' ?></div>
            <div class="muted"><?= e($u['latest']['message']) ?></div>
        <?php else: ?><div class="val">–</div><div class="muted">noch nicht geprüft</div><?php endif; ?></div>
    <div class="card"><div class="lbl">Update-Quelle</div>
        <div><b><?= $u['repo'] !== '' ? e($u['repo']) : '<span class="neg">GITHUB_REPO fehlt</span>' ?></b> · Branch <?= e($u['branch']) ?></div>
        <div class="muted">Weg: <?= $u['mode'] === 'git' ? 'Git-Checkout (git pull)' : 'ZIP-Download von GitHub (kein Git auf dem Server)' ?></div></div>
</div>

<div class="card">
    <h2 style="margin-top:0">Webpanel aktualisieren</h2>
    <p>Holt die neueste Version des Webpanels von GitHub und spielt sie ein. Neue Datenbank-Änderungen werden automatisch ausgeführt, <code>.env</code> und <code>storage/</code> bleiben unberührt.</p>
    <div class="row filter">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check"><button class="ghost" <?= $u['repo'] === '' ? 'disabled' : '' ?>>Auf Updates prüfen</button></form>
        <form method="post" onsubmit="return confirm('Jetzt die neueste Version von GitHub einspielen?')"><?= csrf_field() ?><input type="hidden" name="action" value="update"><button class="go" <?= $u['repo'] === '' ? 'disabled' : '' ?>>Jetzt aktualisieren</button></form>
    </div>
    <?php if (!$u['env']['git'] && !$u['env']['zip']): ?><div class="flash err">Weder Git noch die PHP-Erweiterung „zip“ sind verfügbar – Updates sind auf diesem Server nicht möglich.</div><?php endif; ?>
    <?php if (!$u['env']['writable']): ?><div class="flash err">Der Panel-Ordner ist für PHP nicht beschreibbar.</div><?php endif; ?>
</div>

<h2>Automatisch nach jedem Push (Webhook)</h2>
<div class="card">
    <?php if ($u['webhook_configured']): ?>
        <p>✅ Der Webhook ist eingerichtet. Auf GitHub unter <i>Repository → Settings → Webhooks</i> eintragen:</p>
    <?php else: ?>
        <p>Noch nicht eingerichtet. Damit das Webpanel nach jedem Push von selbst aktualisiert wird: in der <code>.env</code> <code>DEPLOY_WEBHOOK_SECRET=&lt;langer Zufallswert&gt;</code> setzen und auf GitHub unter <i>Repository → Settings → Webhooks → Add webhook</i> eintragen:</p>
    <?php endif; ?>
    <ul>
        <li>Payload URL: <code><?= e($webhookUrl) ?></code></li>
        <li>Content type: <code>application/json</code></li>
        <li>Secret: derselbe Wert wie <code>DEPLOY_WEBHOOK_SECRET</code></li>
        <li>Ereignis: nur „Just the push event“</li>
    </ul>
</div>

<h2>Einstellungen</h2>
<form method="post" class="card stack" style="max-width:640px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <label>Verhalten ohne Webhook (täglicher Fallback)
        <select name="panel_update_mode"><?php foreach ($modes as $k => $l): ?><option value="<?= $k ?>" <?= $u['panel_update_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div><button>Speichern</button></div>
</form>

<?php if ($u['backups']): ?>
<h2>Backups (ZIP-Weg, letzte 5)</h2>
<table style="max-width:560px">
    <?php foreach ($u['backups'] as $b): ?>
        <tr><td><code><?= e($b) ?></code></td>
            <td><form method="post" onsubmit="return confirm('Diesen Stand wiederherstellen?')"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="name" value="<?= e($b) ?>"><button class="link">Wiederherstellen</button></form></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php page_footer();
