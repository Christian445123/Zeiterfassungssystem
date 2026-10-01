<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('hours.own', 'hours.view_all');
$viewAll = can('hours.view_all');

if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    try {
        entry_delete($me, (int)$_POST['id']);
        flash('Eintrag gelöscht.');
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('entries.php?' . http_build_query(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '', 'user' => $_POST['user'] ?? '']));
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
// Wer nur eigene Stunden sehen darf, bekommt immer nur die eigenen; sonst 0 = alle
$filterUser = $viewAll ? (int)($_GET['user'] ?? 0) : (int)$me['id'];
$rows = entries_between($filterUser ?: null, $from, $to);
$total = array_sum(array_map(fn($r) => (int)$r['worked_sec'], $rows));
$showName = $viewAll && !$filterUser;
$canAdd = can('hours.own') || can('hours.edit_all');

page_header('Zeiten', 'entries');
?>
<form method="get" class="row filter">
    <label>Von <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>Bis <input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php if ($viewAll): ?>
        <label>Mitarbeiter
            <select name="user"><option value="0">Alle</option>
                <?php foreach (q_all('SELECT id, full_name FROM users WHERE time_tracking = 1 ORDER BY full_name') as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $filterUser ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select></label>
    <?php endif; ?>
    <button>Filtern</button>
    <?php if ($canAdd): ?><a class="btn" href="edit_entry.php">+ Stunden eintragen</a><?php endif; ?>
</form>

<table>
    <tr><?php if ($showName): ?><th>Mitarbeiter</th><?php endif; ?><th>Tag</th><th>Von – Bis</th><th>Pause</th><th>Arbeitszeit</th><th>Notiz</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($showName): ?><td><?= e($r['full_name']) ?></td><?php endif; ?>
            <td><?= e(WEEKDAY_SHORT[(int)date('N', strtotime($r['start_time']))] . ' ' . fmt_d($r['start_time'])) ?></td>
            <td><?= e(date('H:i', strtotime($r['start_time']))) ?>–<?= $r['end_time'] ? e(date('H:i', strtotime($r['end_time']))) : '<span class="tag">offen</span>' ?></td>
            <td><?= fmt_hm((int)$r['break_sec']) ?></td>
            <td><b><?= fmt_hm((int)$r['worked_sec']) ?></b></td>
            <td><?= e($r['note']) ?></td>
            <td class="nowrap">
                <?php if (entry_editable($me, $r)): ?>
                    <a href="edit_entry.php?id=<?= (int)$r['id'] ?>">Bearbeiten</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Eintrag wirklich löschen?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="user" value="<?= $filterUser ?>">
                        <button class="link danger">Löschen</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Keine Einträge im Zeitraum.</td></tr><?php endif; ?>
    <tr class="sum"><td colspan="<?= $showName ? 4 : 3 ?>">Summe</td><td colspan="3"><b><?= fmt_hm($total) ?> h</b></td></tr>
</table>
<?php page_footer();
