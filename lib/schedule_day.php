<?php
declare(strict_types=1);

/**
 * Tagesansicht des Schichtplans (wird von schedule.php eingebunden): Zeitleiste je Mitarbeiter über den Tag
 * mit Besetzung pro halber Stunde. Erwartet: $data (schedule_week_data), $day (Y-m-d), $admin, $me, $hm.
 */
$bd = business_day($day);
$slot = 30;
$from = $bd['open'] ? time_to_min($bd['from']) : 7 * 60;
$to = $bd['open'] ? time_to_min($bd['to']) : 18 * 60 + 30;
$openFrom = $bd['open'] ? time_to_min($bd['from']) : null;
$openTo = $bd['open'] ? time_to_min($bd['to']) : null;

// Zeitraum so erweitern, dass jede Schicht sichtbar ist
foreach ($data['users'] as $u) {
    foreach ($u['cells'][$day]['shifts'] as $s) {
        $a = time_to_min($s['start']);
        $b = time_to_min($s['end']);
        $b = $b <= $a ? 1440 : $b;
        $from = min($from, intdiv($a, $slot) * $slot);
        $to = max($to, (int)(ceil($b / $slot) * $slot));
    }
}
$from = intdiv($from, $slot) * $slot;
$slots = max(1, intdiv($to - $from, $slot));
$coverage = array_fill(0, $slots, 0);

/** Segmente einer Zeile: ['gap'|'shift', Anzahl Slots, Schicht|null] */
$rows = [];
foreach ($data['users'] as $u) {
    $segs = [];
    $cursor = 0;
    $shifts = $u['cells'][$day]['shifts'];
    usort($shifts, fn($x, $y) => strcmp($x['start'], $y['start']));
    foreach ($shifts as $s) {
        $a = time_to_min($s['start']);
        $b = time_to_min($s['end']);
        $b = $b <= $a ? 1440 : $b;
        $si = max($cursor, (int)floor(($a - $from) / $slot));
        $ei = min($slots, (int)ceil(($b - $from) / $slot));
        if ($ei <= $si) {
            continue;
        }
        if ($si > $cursor) {
            $segs[] = ['gap', $si - $cursor, null];
        }
        $segs[] = ['shift', $ei - $si, $s];
        for ($i = $si; $i < $ei; $i++) {
            $coverage[$i]++;
        }
        $cursor = $ei;
    }
    if ($cursor < $slots) {
        $segs[] = ['gap', $slots - $cursor, null];
    }
    $rows[] = [$u, $segs];
}
$slotOpen = function (int $i) use ($from, $slot, $openFrom, $openTo): bool {
    $m = $from + $i * $slot;
    return $openFrom !== null && $m >= $openFrom && $m < $openTo;
};
$gaps = 0;
foreach ($coverage as $i => $c) {
    if ($slotOpen($i) && $c === 0) {
        $gaps++;
    }
}
?>
<div class="row filter" style="align-items:center">
    <b style="font-size:17px"><?= e(WEEKDAY_SHORT[(int)date('N', strtotime($day))] . ' ' . fmt_d($day)) ?></b>
    <?php if ($bd['open']): ?>
        <span class="tag approved">geöffnet <?= e($bd['from'] . '–' . $bd['to']) ?></span>
    <?php else: ?>
        <span class="tag pending">geschlossen: <?= e($bd['reason']) ?></span>
        <span class="muted">Schichten können trotzdem eingeteilt werden.</span>
    <?php endif; ?>
    <?php if ($bd['open'] && $gaps): ?><span class="tag rejected"><?= $gaps * $slot ?> Min. ohne Besetzung</span>
    <?php elseif ($bd['open']): ?><span class="tag approved">durchgehend besetzt</span><?php endif; ?>
</div>

<div class="plan-wrap">
<table class="plan planday">
    <tr>
        <th class="emp">Mitarbeiter</th>
        <?php
        for ($i = 0; $i < $slots;) {
            $m = $from + $i * $slot;
            if ($m % 60 === 0 && $i + 1 < $slots) {
                echo '<th colspan="2" class="hr">' . sprintf('%02d:00', intdiv($m, 60)) . '</th>';
                $i += 2;
            } else {
                echo '<th class="hr"></th>';
                $i++;
            }
        }
        ?>
        <th class="sumcol">Stunden</th>
    </tr>
    <?php foreach ($rows as [$u, $segs]): $cell = $u['cells'][$day]; ?>
        <tr class="<?= $u['id'] === (int)$me['id'] ? 'mine' : '' ?>">
            <td class="emp"><b><?= e($u['name']) ?></b>
                <?php if ($admin): ?><br><button type="button" class="add" data-user="<?= $u['id'] ?>" data-date="<?= e($day) ?>">+ einteilen</button><?php endif; ?>
                <?php if ($cell['absence']): ?><br><span class="tag pending"><?= e(ABSENCE_TYPES[$cell['absence']['type']]) ?></span><?php endif; ?></td>
            <?php
            $idx = 0;
            foreach ($segs as [$kind, $n, $s]) {
                if ($kind === 'gap') {
                    for ($k = 0; $k < $n; $k++, $idx++) {
                        echo '<td class="slot' . ($slotOpen($idx) ? ' openslot' : '') . '"></td>';
                    }
                    continue;
                }
                $label = e($s['start'] . '–' . $s['end']) . ($n >= 3 ? '<br><small>' . $hm($s['minutes']) . ' h</small>' : '');
                if ($admin) {
                    $json = e(json_encode(['id' => $s['id'], 'user_id' => $u['id'], 'date' => $day, 'start' => $s['start'], 'end' => $s['end'], 'brk' => $s['break_min'], 'note' => $s['note']]));
                    echo '<td class="slot" colspan="' . $n . '"><button type="button" class="shift" data-shift="' . $json . '" title="' . e($s['note']) . '">' . $label . '</button></td>';
                } else {
                    echo '<td class="slot" colspan="' . $n . '"><div class="shift">' . $label . '</div></td>';
                }
                $idx += $n;
            }
            ?>
            <td class="sums nowrap"><b><?= $hm($cell['plan_min']) ?> h</b><br><span class="muted"><?= count($cell['shifts']) ?> Schicht<?= count($cell['shifts']) === 1 ? '' : 'en' ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $slots + 2 ?>" class="muted">Keine Mitarbeiter mit Zeiterfassung.</td></tr><?php endif; ?>
    <tr class="sum">
        <td class="emp">Besetzung</td>
        <?php foreach ($coverage as $i => $c): $open = $slotOpen($i); ?>
            <td class="cov <?= !$open ? 'offc' : ($c === 0 ? 'gap' : 'okc') ?>" title="<?= e(sprintf('%02d:%02d', intdiv($from + $i * $slot, 60), ($from + $i * $slot) % 60)) ?>"><?= $c ?: ($open ? '0' : '') ?></td>
        <?php endforeach; ?>
        <td></td>
    </tr>
</table>
</div>
<p class="muted">Die Leiste zeigt je Mitarbeiter die eingeteilten Schichten, darunter die Besetzung pro halber Stunde. Rot = innerhalb der Öffnungszeiten (<?= $bd['open'] ? e($bd['from'] . '–' . $bd['to']) : 'heute geschlossen' ?>) ist niemand eingeteilt.
    <?php if ($admin): ?>Klick auf eine Schicht bearbeitet sie, „+ einteilen“ legt eine neue an.<?php endif; ?></p>
