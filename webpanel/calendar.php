<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('schedule.view', 'absences.request', 'absences.manage');

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$cal = absence_calendar_data($month, $me);
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$names = ['01' => 'Jänner', '02' => 'Februar', '03' => 'März', '04' => 'April', '05' => 'Mai', '06' => 'Juni', '07' => 'Juli',
    '08' => 'August', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'];

page_header('Urlaubsplaner', 'calendar');
?>
<div class="row filter">
    <a class="btn ghost" href="calendar.php?month=<?= e($prev) ?>">‹ <?= e($names[substr($prev, 5)]) ?></a>
    <b style="font-size:18px"><?= e($names[substr($month, 5)]) ?> <?= e(substr($month, 0, 4)) ?></b>
    <a class="btn ghost" href="calendar.php?month=<?= e($next) ?>"><?= e($names[substr($next, 5)]) ?> ›</a>
    <a class="btn ghost" href="calendar.php">Heute</a>
</div>
<div class="legend">
    <span class="cal-u">U</span> Urlaub <span class="cal-u pend">U</span> beantragt
    <span class="cal-k">K</span> Krank <span class="cal-a">A</span> Arzt <span class="cal-z">Z</span> Zeitausgleich <span class="cal-s">S</span> Sonstiges
    <span class="cal-away">–</span> abwesend
</div>
<div class="plan-wrap">
<table class="cal">
    <tr><th class="emp">Mitarbeiter</th>
        <?php foreach ($cal['days'] as $d): ?>
            <th class="<?= $d['dow'] >= 6 ? 'we' : '' ?> <?= $d['holiday'] ? 'ho' : '' ?> <?= $d['date'] === date('Y-m-d') ? 'today' : '' ?>" title="<?= e($d['holiday'] ?? '') ?>">
                <?= e(substr($d['weekday'], 0, 1)) ?><br><?= (int)substr($d['date'], 8) ?></th>
        <?php endforeach; ?>
    </tr>
    <?php foreach ($cal['users'] as $u): ?>
        <tr class="<?= $u['id'] === (int)$me['id'] ? 'mine' : '' ?>">
            <td class="emp"><?= e($u['name']) ?></td>
            <?php foreach ($cal['days'] as $d):
                $c = $u['cells'][$d['date']];
                $a = $c['absence'];
                $short = $a ? ABSENCE_SHORT[$a['type']] : ''; ?>
                <td class="<?= $d['dow'] >= 6 ? 'we' : '' ?> <?= $d['holiday'] ? 'ho' : '' ?> <?= !$c['works'] && !$a ? 'nw' : '' ?>">
                    <?php if ($a): ?>
                        <span class="cal-<?= $a['type'] === 'away' ? 'away' : e(strtolower($short)) ?> <?= $a['status'] === 'pending' ? 'pend' : '' ?>"
                              title="<?= e(($a['type'] === 'away' ? 'abwesend' : ABSENCE_TYPES[$a['type']]) . ($a['hours'] ? ' ' . $a['hours'] . ' h' : '') . ($a['status'] === 'pending' ? ' (beantragt)' : '')) ?>"><?= e($short) ?></span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    <tr class="sum"><td class="emp">Abwesend (ganztägig)</td>
        <?php foreach ($cal['days'] as $d): ?><td class="<?= $d['off'] > 0 ? 'cnt' : '' ?>"><?= $d['off'] ?: '' ?></td><?php endforeach; ?></tr>
    <tr class="sum"><td class="emp">Im Dienst</td>
        <?php foreach ($cal['days'] as $d): ?><td><?= ($d['working'] - $d['off']) ?: '' ?></td><?php endforeach; ?></tr>
</table>
</div>
<p class="muted">Grau hinterlegte Tage sind für den Mitarbeiter arbeitsfrei (Wochenende, Feiertag oder freier Wochentag). Krankenstand und Arzt anderer Mitarbeiter siehst du nur als „abwesend“.</p>
<?php page_footer();
