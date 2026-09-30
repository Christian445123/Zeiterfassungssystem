<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
require_login(true);

$newKey = null;
if (is_post()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    switch ($action) {
        case 'lic_create':
            $customer = trim((string)($_POST['customer'] ?? ''));
            $exp = trim((string)($_POST['expires_at'] ?? ''));
            if ($customer === '') {
                flash('Kunde fehlt.', 'err');
                break;
            }
            q('INSERT INTO licenses (license_key, customer, max_devices, expires_at) VALUES (?,?,?,?)',
                [random_license_key(), mb_substr($customer, 0, 100), max(1, (int)($_POST['max_devices'] ?? 5)), $exp !== '' ? $exp : null]);
            flash('Lizenz erstellt.');
            break;
        case 'lic_update':
            $exp = trim((string)($_POST['expires_at'] ?? ''));
            q('UPDATE licenses SET max_devices = ?, expires_at = ? WHERE id = ?',
                [max(1, (int)$_POST['max_devices']), $exp !== '' ? $exp : null, $id]);
            flash('Lizenz aktualisiert.');
            break;
        case 'lic_toggle':
            q('UPDATE licenses SET active = 1 - active WHERE id = ?', [$id]);
            break;
        case 'dev_remove':
            q('DELETE FROM license_devices WHERE id = ?', [$id]);
            flash('Gerät entfernt (Anmeldungen dieses Geräts sind ungültig).');
            break;
        case 'key_create':
            $key = random_key('zk_');
            q('INSERT INTO api_keys (license_id, key_hash, key_prefix, label) VALUES (?,?,?,?)',
                [(int)$_POST['license_id'], hash('sha256', $key), substr($key, 0, 10), mb_substr(trim((string)($_POST['label'] ?? '')), 0, 100)]);
            $newKey = $key; // wird nur jetzt angezeigt
            break;
        case 'key_toggle':
            q('UPDATE api_keys SET active = 1 - active WHERE id = ?', [$id]);
            break;
        case 'key_delete':
            q('DELETE FROM api_keys WHERE id = ?', [$id]);
            flash('API-Key gelöscht.');
            break;
    }
    if ($newKey === null) {
        redirect('licenses.php');
    }
}

$lics = q_all('SELECT * FROM licenses ORDER BY id');
page_header('Lizenzen & API', 'lic');
?>
<?php if ($newKey): ?>
    <div class="flash ok">Neuer API-Key – wird nur einmal angezeigt:<br><code><?= e($newKey) ?></code></div>
<?php endif; ?>

<h2>Neue Lizenz</h2>
<form method="post" class="row filter">
    <?= csrf_field() ?><input type="hidden" name="action" value="lic_create">
    <label>Kunde <input name="customer" required></label>
    <label>Max. Geräte <input type="number" min="1" name="max_devices" value="5" style="width:80px"></label>
    <label>Gültig bis (leer = unbegrenzt) <input type="date" name="expires_at"></label>
    <button>Erstellen</button>
</form>

<?php foreach ($lics as $l):
    $devs = q_all('SELECT * FROM license_devices WHERE license_id = ? ORDER BY activated_at', [$l['id']]);
    $keys = q_all('SELECT * FROM api_keys WHERE license_id = ? ORDER BY id', [$l['id']]);
    $valid = license_is_valid($l); ?>
    <div class="card">
        <h2><?= e($l['customer']) ?> <span class="tag <?= $valid ? 'approved' : 'rejected' ?>"><?= $valid ? 'gültig' : ($l['active'] ? 'abgelaufen' : 'deaktiviert') ?></span></h2>
        <p>Lizenzschlüssel: <code><?= e($l['license_key']) ?></code> · Geräte: <?= count($devs) ?>/<?= (int)$l['max_devices'] ?> · Läuft bis: <?= $l['expires_at'] ? e(fmt_d($l['expires_at'])) : 'unbegrenzt' ?></p>
        <form method="post" class="row filter">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <label>Max. Geräte <input type="number" min="1" name="max_devices" value="<?= (int)$l['max_devices'] ?>" style="width:80px"></label>
            <label>Gültig bis <input type="date" name="expires_at" value="<?= e($l['expires_at'] ?? '') ?>"></label>
            <button name="action" value="lic_update">Speichern</button>
            <button name="action" value="lic_toggle" class="<?= $l['active'] ? 'danger' : '' ?>"><?= $l['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
        </form>

        <h3>Geräte</h3>
        <table>
            <tr><th>Rechner</th><th>Geräte-ID</th><th>Aktiviert</th><th>Zuletzt gesehen</th><th></th></tr>
            <?php foreach ($devs as $d): ?>
                <tr><td><?= e($d['machine_name']) ?></td><td><code><?= e(substr($d['machine_id'], 0, 12)) ?>…</code></td>
                    <td><?= e(fmt_dt($d['activated_at'])) ?></td><td><?= e(fmt_dt($d['last_seen'])) ?></td>
                    <td><form method="post" onsubmit="return confirm('Gerät entfernen?')"><?= csrf_field() ?><input type="hidden" name="action" value="dev_remove"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="link danger">Entfernen</button></form></td></tr>
            <?php endforeach; ?>
            <?php if (!$devs): ?><tr><td colspan="5" class="muted">Noch kein Gerät aktiviert.</td></tr><?php endif; ?>
        </table>

        <h3>API-Keys</h3>
        <table>
            <tr><th>Bezeichnung</th><th>Präfix</th><th>Erstellt</th><th>Zuletzt genutzt</th><th>Status</th><th></th></tr>
            <?php foreach ($keys as $k): ?>
                <tr><td><?= e($k['label']) ?></td><td><code><?= e($k['key_prefix']) ?>…</code></td>
                    <td><?= e(fmt_dt($k['created_at'])) ?></td><td><?= e(fmt_dt($k['last_used'])) ?></td>
                    <td><?= $k['active'] ? 'Aktiv' : 'Gesperrt' ?></td>
                    <td class="nowrap"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                        <button class="link" name="action" value="key_toggle"><?= $k['active'] ? 'Sperren' : 'Entsperren' ?></button>
                        <button class="link danger" name="action" value="key_delete" onclick="return confirm('API-Key löschen?')">Löschen</button></form></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post" class="row filter">
            <?= csrf_field() ?><input type="hidden" name="action" value="key_create"><input type="hidden" name="license_id" value="<?= (int)$l['id'] ?>">
            <label>Neuer API-Key – Bezeichnung <input name="label" placeholder="z. B. Büro-PCs"></label><button>Generieren</button>
        </form>
    </div>
<?php endforeach; ?>
<?php page_footer();
