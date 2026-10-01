<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('hours.own', 'hours.view_all', 'reports.view_all', 'months.close', 'months.close_own');

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_REQUEST['month'] ?? '')) ? $_REQUEST['month'] : date('Y-m', strtotime('first day of last month'));

if (is_post()) {
    try {
        $uid = (int)($_POST['user_id'] ?? 0);
        switch ($_POST['action'] ?? '') {
            case 'close':
                month_close($me, $uid, $month);
                flash("Monat $month abgeschlossen.");
                break;
            case 'reopen':
                month_reopen($me, $uid, $month);
                flash("Monat $month wieder geöffnet.");
                break;
            case 'close_all':
                $n = 0;
                foreach (months_overview($month, $me) as $r) {
                    if (!$r['closed'] && $r['can_close'] && user_can($me, 'months.close')) {
                        month_close($me, $r['user_id'], $month);
                        $n++;
                    }
                }
                flash("$n Monate abgeschlossen.");
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('months.php?month=' . $month);
}

$rows = months_overview($month, $me);
$canCloseAny = user_can($me, 'months.close');
$totalWorked = array_sum(array_column($rows, 'worked'));
$totalTarget = array_sum(array_column($rows, 'target'));
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$names = ['01' => 'Jänner', '02' => 'Februar', '03' => 'März', '04' => 'April', '05' => 'Mai', '06' => 'Juni', '07' => 'Juli',
    '08' => 'August', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'];

page_header('Monatsabschluss', 'months');
?>
<div class="row filter">
    <a class="btn ghost" href="months.php?month=<?= e($prev) ?>">‹ <?= e($names[substr($prev, 5)]) ?></a>
    <b style="font-size:18px"><?= e($names[substr($month, 5)]) ?> <?= e(substr($month, 0, 4)) ?></b>
    <a class="btn ghost" href="months.php?month=<?= e($next) ?>"><?= e($names[substr($next, 5)]) ?> ›</a>
    <?php if ($canCloseAny): ?>
        <form method="post" onsubmit="return confirm('Alle noch offenen Mitarbeiter für diesen Monat abschließen?')"><?= csrf_field() ?><input type="hidden" name="month" value="<?= e($month) ?>"><button name="action" value="close_all" class="ghost">Alle abschließen</button></form>
    <?php endif; ?>
</div>

<div class="cards">
    <div class="card"><div class="lbl">Gesamte Arbeitszeit im Monat</div><div class="val"><?= fmt_hm($totalWorked) ?> h</div><div class="muted">Soll <?= fmt_hm($totalTarget) ?> h</div></div>
    <div class="card"><div class="lbl">Abgeschlossen</div><div class="val"><?= count(array_filter($rows, fn($r) => $r['closed'])) ?> <small>von <?= count($rows) ?></small></div></div>
</div>

<table>
    <tr><th>Nr.</th><th>Mitarbeiter</th><th>Arbeitszeit</th><th>Soll</th><th>Saldo</th><th>Plus-/Minusstd. (Monatsende)</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['personnel_number']) ?></td>
            <td><b><?= e($r['name']) ?></b></td>
            <td><b><?= fmt_hm($r['worked']) ?> h</b></td>
            <td><?= fmt_hm($r['target']) ?> h</td>
            <td class="<?= $r['balance'] < 0 ? 'neg' : 'pos' ?>"><?= ($r['balance'] > 0 ? '+' : '') . fmt_hm($r['balance']) ?> h</td>
            <td class="<?= $r['overtime_end'] < 0 ? 'neg' : '' ?>"><?= ($r['overtime_end'] > 0 ? '+' : '') . fmt_hm($r['overtime_end']) ?> h</td>
            <td><?= $r['closed'] ? '<span class="tag approved">abgeschlossen</span><br><small class="muted">' . e(fmt_dt($r['closed_at'])) . ' · ' . e($r['closed_by'] ?? '') . '</small>' : '<span class="tag pending">offen</span>' ?></td>
            <td class="nowrap">
                <a href="reports.php?month=<?= e($month) ?>&user=<?= (int)$r['user_id'] ?>">Details</a>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
                    <?php if (!$r['closed'] && $r['can_close']): ?><button class="link" name="action" value="close" onclick="return confirm('Monat für <?= e($r['name']) ?> abschließen? Danach sind keine Änderungen an den Stunden mehr möglich.')">Abschließen</button><?php endif; ?>
                    <?php if ($r['closed'] && $canCloseAny): ?><button class="link danger" name="action" value="reopen" onclick="return confirm('Monat wieder öffnen?')">Wieder öffnen</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Keine Mitarbeiter.</td></tr><?php endif; ?>
</table>
<p class="muted">Die Arbeitszeit wird aus den Einträgen laufend automatisch zusammengezählt. Ein Monat kann ab seinem letzten Tag abgeschlossen werden; danach lassen sich die Stunden dieses Monats nicht mehr ändern – nur die Verwaltung kann ihn wieder öffnen.</p>
<?php page_footer();
