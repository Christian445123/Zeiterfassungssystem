<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('schedule.view');
$admin = can('schedule.edit');
$seeAll = can('hours.view_all');
$seeOwn = can('hours.own');

$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_REQUEST['week'] ?? '')) ? $_REQUEST['week'] : 'today';
[$ws, $we] = week_bounds($ref);
$self = 'schedule.php?week=' . $ws;

if (is_post()) {
    if (!$admin) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $r = shift_save($me, $_POST);
                flash($r['warning'] ?? 'Schicht gespeichert.', $r['warning'] ? 'err' : 'ok');
                break;
            case 'delete':
                shift_delete($me, $id);
                flash('Schicht gelöscht.');
                break;
            case 'copy_prev':
                flash(shifts_copy_prev($me, $ws, $we) . ' Schichten aus der Vorwoche kopiert.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect($self);
}

$data = schedule_week_data($ws, $me);
$days = $data['days'];
$today = date('Y-m-d');
$hm = fn(int $min): string => fmt_hm($min * 60);
$statusLabel = [
    'ok' => 'anwesend', 'late' => 'verspätet', 'short' => 'zu kurz', 'missing' => 'nichts eingetragen',
    'unplanned' => 'ungeplant', 'pending' => 'heute noch offen', 'excused' => 'entschuldigt', 'future' => '', '' => '',
];

page_header('Dienstplan', 'schedule');
?>
<div class="row filter">
    <a class="btn" href="schedule.php?week=<?= e(date('Y-m-d', strtotime("$ws -7 days"))) ?>">‹ Vorwoche</a>
    <a class="btn" href="schedule.php">Diese Woche</a>
    <a class="btn" href="schedule.php?week=<?= e(date('Y-m-d', strtotime("$ws +7 days"))) ?>">Nächste Woche ›</a>
    <b>KW <?= (int)$data['kw'] ?> · <?= e(fmt_d($ws)) ?> – <?= e(fmt_d($we)) ?></b>
    <?php if ($admin): ?>
        <form method="post" onsubmit="return confirm('Alle Schichten der Vorwoche in diese Woche kopieren?')">
            <?= csrf_field() ?><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="action" value="copy_prev">
            <button class="ghost">Vorwoche kopieren</button>
        </form>
    <?php endif; ?>
</div>

<h2>Dienstplan (Soll) – geplante Schichten</h2>
<div class="plan-wrap">
<table class="plan">
    <tr>
        <th class="emp">Mitarbeiter</th>
        <?php foreach ($days as $i => $d): ?>
            <th class="<?= $d === $today ? 'today' : '' ?>"><?= WEEKDAY_SHORT[$i + 1] ?> <?= e(date('d.m.', strtotime($d))) ?>
                <?php $bd = business_day($d); ?>
                <br><small class="muted"><?= $bd['open'] ? e($bd['from'] . '–' . $bd['to']) : 'geschlossen' ?></small>
                <?php if ($bd['label']): ?><br><small class="warnc"><?= e($bd['label']) ?></small><?php endif; ?></th>
        <?php endforeach; ?>
        <th>Stunden</th>
    </tr>
    <?php foreach ($data['users'] as $u):
        $diff = $u['soll_min'] === null ? 0 : $u['plan_min'] - $u['soll_min']; ?>
        <tr class="<?= $u['id'] === (int)$me['id'] ? 'mine' : '' ?>">
            <td class="emp"><b><?= e($u['name']) ?></b><br><small class="muted"><?= e($u['weekly_hours']) ?> h/Woche</small></td>
            <?php foreach ($days as $d): $c = $u['cells'][$d]; ?>
                <td class="cell <?= $d === $today ? 'today' : '' ?> <?= $c['closed'] ? 'holiday' : '' ?>">
                    <?php if ($c['absence']): ?><span class="tag pending"><?= e(ABSENCE_TYPES[$c['absence']['type']]) ?><?= $c['absence']['hours'] ? ' ' . e($c['absence']['hours']) . ' h' : '' ?></span><?php endif; ?>
                    <?php foreach ($c['shifts'] as $s):
                        $label = '<b>' . e($s['start'] . '–' . $s['end']) . '</b> <span class="muted">' . $hm($s['minutes']) . ' h</span>'
                            . ($s['note'] ? '<br><small class="muted">' . e($s['note']) . '</small>' : '');
                        if ($admin): ?>
                            <button type="button" class="shift" data-shift="<?= e(json_encode([
                                'id' => $s['id'], 'user_id' => $u['id'], 'date' => $d, 'start' => $s['start'], 'end' => $s['end'],
                                'brk' => $s['break_min'], 'note' => $s['note'],
                            ])) ?>"><?= $label ?></button>
                        <?php else: ?>
                            <div class="shift"><?= $label ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($admin): ?>
                        <button type="button" class="add" title="Schicht hinzufügen" data-user="<?= $u['id'] ?>" data-date="<?= e($d) ?>">+</button>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td class="sums nowrap">
                <b><?= $hm($u['plan_min']) ?></b> geplant<br>
                <?php if ($u['soll_min'] !== null): ?><span class="muted">Soll <?= $hm($u['soll_min']) ?></span><br>
                <span class="<?= $diff > 0 ? 'neg' : ($diff < 0 ? 'warnc' : 'pos') ?>"><?= $diff === 0 ? '✓ passt' : ($diff > 0 ? '+' : '') . $hm($diff) . ' zum Soll' ?></span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$data['users']): ?><tr><td colspan="9" class="muted">Keine aktiven Mitarbeiter.</td></tr><?php endif; ?>
    <tr class="sum">
        <td class="emp">Summe pro Tag</td>
        <?php foreach ($days as $d): ?>
            <td><?= $hm($data['day_plan_min'][$d]) ?> h<br><span class="muted"><?= (int)$data['day_planned'][$d] ?> MA</span></td>
        <?php endforeach; ?>
        <td><b><?= $hm(array_sum($data['day_plan_min'])) ?> h</b></td>
    </tr>
</table>
</div>

<?php $istUsers = array_values(array_filter($data['users'], fn($u) => $u['show_actual'])); if ($istUsers): ?>
<h2>Anwesenheit (Ist) – aus den Zeiteinträgen<?= $seeAll ? '' : ' (nur deine eigene)' ?></h2>
<div class="plan-wrap">
<table class="plan">
    <tr>
        <th class="emp">Mitarbeiter</th>
        <?php foreach ($days as $i => $d): ?>
            <th class="<?= $d === $today ? 'today' : '' ?>"><?= WEEKDAY_SHORT[$i + 1] ?> <?= e(date('d.m.', strtotime($d))) ?></th>
        <?php endforeach; ?>
        <th>Stunden</th>
    </tr>
    <?php foreach ($istUsers as $u):
        $diff = $u['ist_min'] - $u['plan_min']; ?>
        <tr class="<?= $u['id'] === (int)$me['id'] ? 'mine' : '' ?>">
            <td class="emp"><b><?= e($u['name']) ?></b></td>
            <?php foreach ($days as $d): $c = $u['cells'][$d]; $a = $c['actual']; ?>
                <td class="cell st-<?= e($c['status']) ?> <?= $d === $today ? 'today' : '' ?>">
                    <?php if ($c['absence']): ?><span class="tag pending"><?= e(ABSENCE_TYPES[$c['absence']['type']]) ?><?= $c['absence']['hours'] ? ' ' . e($c['absence']['hours']) . ' h' : '' ?></span><?php endif; ?>
                    <?php if ($c['holiday'] && !$a['entries']): ?><span class="tag"><?= e($c['holiday']) ?></span><?php endif; ?>
                    <?php foreach ($a['entries'] as $e): ?>
                        <div class="att"><b><?= e($e['start']) ?>–<?= $e['end'] ? e($e['end']) : '<i>läuft</i>' ?></b> <span class="muted"><?= $hm($e['worked_min']) ?> h</span></div>
                    <?php endforeach; ?>
                    <?php if ($c['plan_min']): ?><small class="muted">Plan <?= e($c['shifts'][0]['start']) ?>–<?= e(end($c['shifts'])['end']) ?></small><?php endif; ?>
                    <?php if ($statusLabel[$c['status']] ?? ''): ?>
                        <div class="state-label"><?= e($statusLabel[$c['status']]) ?><?= $c['status'] === 'late' ? ' +' . (int)$c['late_min'] . ' min' : '' ?></div>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td class="sums nowrap">
                <b><?= $hm($u['ist_min']) ?></b> Ist<br>
                <span class="muted">Plan <?= $hm($u['plan_min']) ?> · Soll <?= $hm($u['soll_min']) ?></span><br>
                <span class="<?= $diff < 0 ? 'warnc' : 'pos' ?>"><?= ($diff > 0 ? '+' : '') . $hm($diff) ?> zum Plan</span>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($seeAll): ?><tr class="sum">
        <td class="emp">Summe pro Tag</td>
        <?php foreach ($days as $d): ?>
            <td><?= $hm($data['day_ist_min'][$d]) ?> h<br><span class="muted"><?= (int)$data['day_present'][$d] ?> anwesend</span></td>
        <?php endforeach; ?>
        <td><b><?= $hm(array_sum($data['day_ist_min'])) ?> h</b></td>
    </tr><?php endif; ?>
</table>
</div>
<?php endif; ?>
<p class="muted">Soll = Wochenstunden laut Arbeitszeitmodell (ohne Feiertage und Abwesenheiten). Plan = eingeteilte Schichten (Länge minus Pause). Ist = eingetragene Arbeitszeit.
    Verspätet = mehr als 10 Min. nach Schichtbeginn; Ende vor Beginn = Schicht über Mitternacht.</p>

<?php if ($admin): ?>
<dialog id="dlg">
    <form method="post" class="stack">
        <h2 id="dlgTitle">Schicht</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="week" value="<?= e($ws) ?>">
        <input type="hidden" name="id" id="f_id" value="0">
        <label>Mitarbeiter <select name="user_id" id="f_user"><?php foreach ($data['users'] as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
        <label>Datum <input type="date" name="shift_date" id="f_date" required></label>
        <div class="row" style="justify-content:flex-start">
            <label>Beginn <input type="time" name="start_time" id="f_start" value="08:00" required></label>
            <label>Ende <input type="time" name="end_time" id="f_end" value="16:30" required></label>
            <label>Pause (Min) <input type="number" min="0" name="break_min" id="f_brk" value="30" style="width:90px"></label>
        </div>
        <label>Notiz <input name="note" id="f_note" maxlength="255"></label>
        <div class="muted" id="f_calc"></div>
        <div class="row" style="justify-content:flex-start">
            <button name="action" value="save">Speichern</button>
            <button name="action" value="delete" id="f_del" class="danger" formnovalidate onclick="return confirm('Schicht löschen?')">Löschen</button>
            <button type="button" class="ghost" onclick="dlg.close()">Abbrechen</button>
        </div>
    </form>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('dlg');
    window.dlg = dlg;
    var $ = function (id) { return document.getElementById(id); };
    function calc() {
        var s = $('f_start').value, e = $('f_end').value, b = parseInt($('f_brk').value || '0', 10);
        if (!s || !e) { $('f_calc').textContent = ''; return; }
        var sm = +s.slice(0, 2) * 60 + +s.slice(3), em = +e.slice(0, 2) * 60 + +e.slice(3);
        if (em <= sm) em += 1440;
        var m = em - sm - b;
        $('f_calc').textContent = m > 0 ? 'Zählt als ' + Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2) + ' Stunden' : 'Pause zu lang';
    }
    ['f_start', 'f_end', 'f_brk'].forEach(function (i) { $(i).addEventListener('input', calc); });
    function open(v) {
        $('dlgTitle').textContent = v.id ? 'Schicht bearbeiten' : 'Schicht hinzufügen';
        $('f_id').value = v.id || 0;
        $('f_user').value = v.user_id; $('f_date').value = v.date;
        $('f_start').value = v.start || '08:00'; $('f_end').value = v.end || '16:30';
        $('f_brk').value = v.brk != null ? v.brk : 30;
        $('f_note').value = v.note || '';
        $('f_del').style.display = v.id ? '' : 'none';
        calc(); dlg.showModal();
    }
    document.querySelectorAll('.plan .add').forEach(function (b) {
        b.addEventListener('click', function () { open({ user_id: b.dataset.user, date: b.dataset.date }); });
    });
    document.querySelectorAll('.plan .shift[data-shift]').forEach(function (b) {
        b.addEventListener('click', function () { open(JSON.parse(b.dataset.shift)); });
    });
})();
</script>
<?php endif; ?>
<?php page_footer();
