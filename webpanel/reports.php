<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$uid = selected_user_id($me);
$user = q_one('SELECT * FROM users WHERE id = ?', [$uid]) ?? $me;
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$byDay = [];
foreach (entries_between($uid, $from, $to) as $r) {
    $d = substr($r['start_time'], 0, 10);
    $byDay[$d]['worked'] = ($byDay[$d]['worked'] ?? 0) + (int)$r['worked_sec'];
    $byDay[$d]['break'] = ($byDay[$d]['break'] ?? 0) + (int)$r['break_sec'];
    $byDay[$d]['first'] = min($byDay[$d]['first'] ?? $r['start_time'], $r['start_time']);
    $last = $r['end_time'] ?? date('Y-m-d H:i:s');
    $byDay[$d]['last'] = max($byDay[$d]['last'] ?? $last, $last);
}
$abs = absence_days($uid, $from, $to);
$dailyTarget = (int)round((float)$user['weekly_hours'] / 5 * 3600);
$wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$rows = [];
$totWorked = $totTarget = 0;
$absCount = ['vacation' => 0, 'sick' => 0, 'other' => 0];
for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
    $d = date('Y-m-d', $t);
    $dow = (int)date('w', $t);
    $isWork = $dow >= 1 && $dow <= 5;
    $target = ($isWork && !isset($abs[$d])) ? $dailyTarget : 0;
    if ($isWork && isset($abs[$d])) {
        $absCount[$abs[$d]]++;
    }
    $worked = $byDay[$d]['worked'] ?? 0;
    $totWorked += $worked;
    $totTarget += $target;
    $rows[] = ['date' => $d, 'wd' => $wd[$dow], 'weekend' => !$isWork, 'first' => $byDay[$d]['first'] ?? null,
        'last' => $byDay[$d]['last'] ?? null, 'break' => $byDay[$d]['break'] ?? 0, 'worked' => $worked,
        'target' => $target, 'abs' => $abs[$d] ?? null];
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zeiten_' . preg_replace('/\W+/', '_', $user['username']) . '_' . $month . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Datum', 'Tag', 'Beginn', 'Ende', 'Pause', 'Arbeitszeit', 'Soll', 'Differenz', 'Abwesenheit'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [fmt_d($r['date']), $r['wd'], $r['first'] ? date('H:i', strtotime($r['first'])) : '',
            $r['last'] ? date('H:i', strtotime($r['last'])) : '', fmt_hm($r['break']), fmt_hm($r['worked']),
            fmt_hm($r['target']), fmt_hm($r['worked'] - $r['target']), $r['abs'] ? ABSENCE_TYPES[$r['abs']] : ''], ';');
    }
    fputcsv($out, ['Summe', '', '', '', '', fmt_hm($totWorked), fmt_hm($totTarget), fmt_hm($totWorked - $totTarget), ''], ';');
    exit;
}

page_header('Auswertung', 'reports');
$qs = http_build_query(['month' => $month, 'user' => $uid]);
?>
<form method="get" class="row filter">
    <label>Monat <input type="month" name="month" value="<?= e($month) ?>"></label>
    <?php if ($me['role'] === 'admin'): ?><label>Mitarbeiter <?= user_select('user', $uid, false) ?></label><?php endif; ?>
    <button>Anzeigen</button>
    <a class="btn" href="reports.php?<?= e($qs) ?>&csv=1">CSV-Export</a>
</form>

<div class="cards">
    <div class="card"><div class="lbl">Ist</div><div class="val"><?= fmt_hm($totWorked) ?> h</div></div>
    <div class="card"><div class="lbl">Soll</div><div class="val"><?= fmt_hm($totTarget) ?> h</div></div>
    <div class="card"><div class="lbl">Saldo</div><div class="val <?= $totWorked - $totTarget < 0 ? 'neg' : 'pos' ?>"><?= fmt_hm($totWorked - $totTarget) ?> h</div></div>
    <div class="card"><div class="lbl">Urlaub / Krank</div><div class="val"><?= $absCount['vacation'] ?> / <?= $absCount['sick'] ?> Tage</div></div>
</div>

<table>
    <tr><th>Datum</th><th>Beginn</th><th>Ende</th><th>Pause</th><th>Ist</th><th>Soll</th><th>Saldo</th><th>Abwesenheit</th></tr>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['weekend'] ? 'weekend' : '' ?>">
            <td><?= e($r['wd']) ?> <?= e(date('d.m.', strtotime($r['date']))) ?></td>
            <td><?= $r['first'] ? e(date('H:i', strtotime($r['first']))) : '' ?></td>
            <td><?= $r['last'] ? e(date('H:i', strtotime($r['last']))) : '' ?></td>
            <td><?= $r['break'] ? fmt_hm($r['break']) : '' ?></td>
            <td><?= $r['worked'] ? fmt_hm($r['worked']) : '' ?></td>
            <td><?= $r['target'] ? fmt_hm($r['target']) : '' ?></td>
            <td><?= ($r['worked'] || $r['target']) ? fmt_hm($r['worked'] - $r['target']) : '' ?></td>
            <td><?= $r['abs'] ? e(ABSENCE_TYPES[$r['abs']]) : '' ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="muted">Soll = Wochenstunden ÷ 5 je Werktag (Mo–Fr), abzüglich genehmigter Abwesenheiten. Feiertage werden nicht berücksichtigt – dafür Abwesenheit „Sonstiges“ eintragen.</p>
<?php page_footer();
