<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();

if (is_post() && ($_POST['action'] ?? '') === 'delete' && $me['role'] === 'admin') {
    q('DELETE FROM time_entries WHERE id = ?', [(int)$_POST['id']]);
    flash('Eintrag gelöscht.');
    redirect('entries.php?' . http_build_query(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '', 'user' => $_POST['user'] ?? '']));
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$admin = $me['role'] === 'admin';
$filterUser = $admin ? (int)($_GET['user'] ?? 0) : (int)$me['id']; // 0 = alle (nur Admin)
$rows = entries_between($filterUser ?: null, $from, $to);
$total = array_sum(array_map(fn($r) => (int)$r['worked_sec'], $rows));

page_header('Zeiten', 'entries');
?>
<form method="get" class="row filter">
    <label>Von <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>Bis <input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php if ($admin): ?>
        <label>Mitarbeiter
            <select name="user"><option value="0">Alle</option>
                <?php foreach (q_all('SELECT id, full_name FROM users ORDER BY full_name') as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $filterUser ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select></label>
    <?php endif; ?>
    <button>Filtern</button>
    <?php if ($admin): ?><a class="btn" href="edit_entry.php">+ Manueller Eintrag</a><?php endif; ?>
</form>

<table>
    <tr><?php if ($admin): ?><th>Mitarbeiter</th><?php endif; ?><th>Kommen</th><th>Gehen</th><th>Pause</th><th>Arbeitszeit</th><th>Projekt</th><th>Notiz</th><th>Quelle</th><?php if ($admin): ?><th></th><?php endif; ?></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($admin): ?><td><?= e($r['full_name']) ?></td><?php endif; ?>
            <td><?= e(fmt_dt($r['start_time'])) ?></td>
            <td><?= $r['end_time'] ? e(fmt_dt($r['end_time'])) : '<span class="tag">läuft</span>' ?></td>
            <td><?= fmt_hm((int)$r['break_sec']) ?></td>
            <td><b><?= fmt_hm((int)$r['worked_sec']) ?></b></td>
            <td><?= e($r['project_name'] ?? '–') ?></td>
            <td><?= e($r['note']) ?></td>
            <td><?= e($r['source']) ?></td>
            <?php if ($admin): ?>
                <td class="nowrap">
                    <a href="edit_entry.php?id=<?= (int)$r['id'] ?>">Bearbeiten</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Eintrag wirklich löschen?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="user" value="<?= $filterUser ?>">
                        <button class="link danger">Löschen</button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted">Keine Einträge.</td></tr><?php endif; ?>
    <tr class="sum"><td colspan="<?= $admin ? 4 : 3 ?>">Summe</td><td colspan="5"><b><?= fmt_hm($total) ?> h</b></td></tr>
</table>
<?php page_footer();
