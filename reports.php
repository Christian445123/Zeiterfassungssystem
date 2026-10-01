<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('hours.own', 'reports.view_all');

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$uid = selected_user_id($me, 'reports.view_all');
$user = q_one('SELECT * FROM users WHERE id = ?', [$uid]) ?? $me;
$rep = report_data($user, $month);
$showVacation = can('absences.request') || can('absences.manage');

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zeiten_' . preg_replace('/\W+/', '_', (string)$user['personnel_number']) . '_' . $month . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Datum', 'Tag', 'Beginn', 'Ende', 'Pause', 'Arbeitszeit', 'Soll', 'Differenz', 'Abwesenheit/Feiertag'], ';');
    foreach ($rep['rows'] as $r) {
        $note = $r['absence'] ? ABSENCE_TYPES[$r['absence']['type']] . ($r['absence']['hours'] ? ' ' . $r['absence']['hours'] . ' h' : '') : ($r['holiday'] ?? '');
        fputcsv($out, [fmt_d($r['date']), $r['weekday'], $r['first'] ? date('H:i', strtotime($r['first'])) : '',
            $r['last'] ? date('H:i', strtotime($r['last'])) : '', fmt_hm($r['break']), fmt_hm($r['worked']),
            fmt_hm($r['target']), fmt_hm($r['worked'] - $r['target']), $note], ';');
    }
    fputcsv($out, ['Summe', '', '', '', '', fmt_hm($rep['worked']), fmt_hm($rep['target']), fmt_hm($rep['balance']), ''], ';');
    exit;
}

page_header('Auswertung', 'reports');
$qs = http_build_query(['month' => $month, 'user' => $uid]);
$vac = $rep['vacation'];
$num = fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
?>
<form method="get" class="row filter">
    <label>Monat <input type="month" name="month" value="<?= e($month) ?>"></label>
    <?php if (can('reports.view_all')): ?><label>Mitarbeiter <?= user_select('user', $uid, false) ?></label><?php endif; ?>
    <button>Anzeigen</button>
    <a class="btn ghost" href="reports.php?<?= e($qs) ?>&csv=1">CSV-Export</a>
</form>
<p><b><?= e($user['full_name']) ?></b> · <?= e($user['weekly_hours']) ?> h/Woche · Arbeitstage: <?= e(implode(', ', array_map(fn($d) => WEEKDAY_SHORT[$d], user_workdays($user)))) ?></p>
<?php $closing = month_closing((int)$user['id'], $month); ?>
<?php if ($closing): ?><p><span class="tag approved">Monat abgeschlossen</span> <span class="muted">am <?= e(fmt_dt($closing['closed_at'])) ?> von <?= e($closing['closed_by_name'] ?? '') ?> · Arbeitszeit laut Abschluss <?= fmt_hm((int)$closing['worked_sec']) ?> h</span></p>
<?php else: ?><p class="muted">Monat noch offen – <a href="months.php?month=<?= e($month) ?>">zum Monatsabschluss</a></p><?php endif; ?>

<div class="cards">
    <div class="card"><div class="lbl">Ist</div><div class="val"><?= fmt_hm($rep['worked']) ?> h</div></div>
    <div class="card"><div class="lbl">Soll</div><div class="val"><?= fmt_hm($rep['target']) ?> h</div></div>
    <div class="card"><div class="lbl">Saldo Monat</div><div class="val <?= $rep['balance'] < 0 ? 'neg' : 'pos' ?>"><?= ($rep['balance'] > 0 ? '+' : '') . fmt_hm($rep['balance']) ?> h</div></div>
    <div class="card"><div class="lbl">Plus-/Minusstunden gesamt</div><div class="val <?= $rep['overtime']['seconds'] < 0 ? 'neg' : 'pos' ?>"><?= ($rep['overtime']['seconds'] > 0 ? '+' : '') . fmt_hm($rep['overtime']['seconds']) ?> h</div>
        <div class="muted">seit <?= e(fmt_d($rep['overtime']['since'])) ?><?= $rep['overtime']['adjust_seconds'] ? ' · davon Buchungen ' . fmt_hm($rep['overtime']['adjust_seconds']) . ' h' : '' ?></div></div>
</div>
<?php if ($showVacation): ?>
<div class="cards">
    <div class="card"><div class="lbl">Urlaubsanspruch <?= (int)$vac['year'] ?></div><div class="val"><?= $num($vac['entitlement']) ?> <small>Tage</small></div>
        <div class="muted"><?= $num((float)$vac['carryover']) ?> Übertrag · <?= $num($vac['taken']) ?> genommen<?= $vac['pending'] > 0 ? ' · ' . $num($vac['pending']) . ' beantragt' : '' ?></div></div>
    <div class="card"><div class="lbl">Resturlaub</div><div class="val <?= $vac['remaining'] < 0 ? 'neg' : '' ?>"><?= $num($vac['remaining']) ?> <small>Tage</small></div></div>
    <div class="card"><div class="lbl">Krankenstand <?= (int)$vac['year'] ?></div><div class="val"><?= $num($vac['sick_days']) ?> <small>Tage</small></div></div>
    <div class="card"><div class="lbl">Arzt / Zeitausgleich</div><div class="val"><?= $num($vac['doctor_days']) ?> / <?= $num($vac['comp_days']) ?> <small>Tage</small></div></div>
</div>
<?php endif; ?>

<table>
    <tr><th>Datum</th><th>Beginn</th><th>Ende</th><th>Pause</th><th>Ist</th><th>Soll</th><th>Saldo</th><th>Hinweis</th></tr>
    <?php foreach ($rep['rows'] as $r): ?>
        <tr class="<?= $r['workday'] && !$r['holiday'] ? '' : 'weekend' ?>">
            <td><?= e($r['weekday']) ?> <?= e(date('d.m.', strtotime($r['date']))) ?></td>
            <td><?= $r['first'] ? e(date('H:i', strtotime($r['first']))) : '' ?></td>
            <td><?= $r['last'] ? e(date('H:i', strtotime($r['last']))) : '' ?></td>
            <td><?= $r['break'] ? fmt_hm($r['break']) : '' ?></td>
            <td><?= $r['worked'] ? fmt_hm($r['worked']) : '' ?></td>
            <td><?= $r['target'] ? fmt_hm($r['target']) : '' ?></td>
            <td><?= ($r['worked'] || $r['target']) ? fmt_hm($r['worked'] - $r['target']) : '' ?></td>
            <td><?php if ($r['absence']): ?><span class="tag pending"><?= e(ABSENCE_TYPES[$r['absence']['type']]) ?><?= $r['absence']['hours'] ? ' ' . e($r['absence']['hours']) . ' h' : '' ?></span>
                <?php elseif ($r['holiday']): ?><span class="tag"><?= e($r['holiday']) ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="muted">Soll = Wochenstunden ÷ Arbeitstage je Arbeitstag – ohne Feiertage und (genehmigten) Urlaub, Krankenstand, Arzt und Sonstiges. Zeitausgleich lässt das Soll stehen und mindert dadurch das Überstundenkonto.</p>
<?php page_footer();
