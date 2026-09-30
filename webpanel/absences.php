<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();
$admin = $me['role'] === 'admin';

if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $type = $_POST['type'] ?? '';
        $f = (string)($_POST['date_from'] ?? '');
        $t = (string)($_POST['date_to'] ?? '');
        $target = $admin ? (int)($_POST['user_id'] ?? $me['id']) : (int)$me['id'];
        if (!isset(ABSENCE_TYPES[$type]) || !strtotime($f) || !strtotime($t) || $t < $f) {
            flash('Bitte gültigen Typ und Zeitraum angeben.', 'err');
        } else {
            // Admin-Einträge sind sofort genehmigt
            q('INSERT INTO absences (user_id, type, date_from, date_to, note, status) VALUES (?,?,?,?,?,?)',
                [$target, $type, $f, $t, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255), $admin ? 'approved' : 'pending']);
            flash($admin ? 'Abwesenheit eingetragen.' : 'Antrag gestellt.');
        }
    } elseif ($admin && in_array($action, ['approved', 'rejected'], true)) {
        q('UPDATE absences SET status = ? WHERE id = ?', [$action, (int)$_POST['id']]);
        flash('Status geändert.');
    } elseif ($action === 'delete') {
        $a = q_one('SELECT * FROM absences WHERE id = ?', [(int)$_POST['id']]);
        if ($a && ($admin || ((int)$a['user_id'] === (int)$me['id'] && $a['status'] === 'pending'))) {
            q('DELETE FROM absences WHERE id = ?', [$a['id']]);
            flash('Gelöscht.');
        }
    }
    redirect('absences.php');
}

$rows = $admin
    ? q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id ORDER BY a.date_from DESC LIMIT 200')
    : q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.date_from DESC', [$me['id']]);

page_header('Abwesenheiten', 'abs');
?>
<form method="post" class="row filter">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <?php if ($admin): ?><label>Mitarbeiter <?= user_select('user_id', (int)$me['id']) ?></label><?php endif; ?>
    <label>Art <select name="type"><?php foreach (ABSENCE_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Von <input type="date" name="date_from" required></label>
    <label>Bis <input type="date" name="date_to" required></label>
    <label>Notiz <input name="note" maxlength="255"></label>
    <button><?= $admin ? 'Eintragen' : 'Beantragen' ?></button>
</form>

<table>
    <tr><?php if ($admin): ?><th>Mitarbeiter</th><?php endif; ?><th>Art</th><th>Von</th><th>Bis</th><th>Notiz</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($admin): ?><td><?= e($r['full_name']) ?></td><?php endif; ?>
            <td><?= e(ABSENCE_TYPES[$r['type']]) ?></td>
            <td><?= e(fmt_d($r['date_from'])) ?></td><td><?= e(fmt_d($r['date_to'])) ?></td>
            <td><?= e($r['note']) ?></td>
            <td><span class="tag <?= e($r['status']) ?>"><?= e(ABSENCE_STATUS[$r['status']]) ?></span></td>
            <td class="nowrap">
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <?php if ($admin && $r['status'] !== 'approved'): ?><button class="link" name="action" value="approved">Genehmigen</button><?php endif; ?>
                    <?php if ($admin && $r['status'] !== 'rejected'): ?><button class="link" name="action" value="rejected">Ablehnen</button><?php endif; ?>
                    <?php if ($admin || $r['status'] === 'pending'): ?><button class="link danger" name="action" value="delete" onclick="return confirm('Löschen?')">Löschen</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Keine Einträge.</td></tr><?php endif; ?>
</table>
<?php page_footer();
