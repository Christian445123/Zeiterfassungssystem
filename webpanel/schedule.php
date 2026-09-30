<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();
$admin = $me['role'] === 'admin';
db()->exec(SHIFTS_SQL); // idempotent, damit auch bestehende Installationen die Tabelle bekommen

$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_REQUEST['week'] ?? '')) ? $_REQUEST['week'] : 'today';
[$ws, $we] = week_bounds($ref);
$self = 'schedule.php?week=' . $ws;

function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

if (is_post()) {
    if (!$admin) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $date = (string)($_POST['shift_date'] ?? '');
            $st = (string)($_POST['start_time'] ?? '');
            $en = (string)($_POST['end_time'] ?? '');
            $brk = max(0, (int)($_POST['break_min'] ?? 0));
            $pid = (int)($_POST['project_id'] ?? 0) ?: null;
            if (!q_one('SELECT id FROM users WHERE id = ? AND active = 1', [$uid]) || !strtotime($date) || !valid_time($st) || !valid_time($en)) {
                throw new DomainException('Bitte Mitarbeiter, Datum und gültige Zeiten (HH:MM) angeben.');
            }
            if ($st === $en) {
                throw new DomainException('Beginn und Ende dürfen nicht gleich sein.');
            }
            if (shift_minutes($st, $en, $brk) <= 0) {
                throw new DomainException('Die Pause ist länger als die Schicht.');
            }
            // Überschneidung am selben Tag?
            $s1 = time_to_min($st);
            $e1 = time_to_min($en) <= $s1 ? time_to_min($en) + 1440 : time_to_min($en);
            foreach (q_all('SELECT * FROM shifts WHERE user_id = ? AND shift_date = ? AND id <> ?', [$uid, $date, $id]) as $o) {
                $s2 = time_to_min($o['start_time']);
                $e2 = time_to_min($o['end_time']) <= $s2 ? time_to_min($o['end_time']) + 1440 : time_to_min($o['end_time']);
                if ($s1 < $e2 && $s2 < $e1) {
                    throw new DomainException('Überschneidung mit einer bestehenden Schicht dieses Mitarbeiters.');
                }
            }
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            if ($id) {
                q('UPDATE shifts SET user_id=?, shift_date=?, start_time=?, end_time=?, break_min=?, project_id=?, note=? WHERE id=?',
                    [$uid, $date, $st, $en, $brk, $pid, $note, $id]);
            } else {
                q('INSERT INTO shifts (user_id, shift_date, start_time, end_time, break_min, project_id, note) VALUES (?,?,?,?,?,?,?)',
                    [$uid, $date, $st, $en, $brk, $pid, $note]);
            }
            if (isset(absence_days($uid, $date, $date)[$date])) {
                flash('Gespeichert – Achtung: Der Mitarbeiter hat an diesem Tag eine genehmigte Abwesenheit.', 'err');
            } else {
                flash('Schicht gespeichert.');
            }
        } elseif ($action === 'delete' && $id) {
            q('DELETE FROM shifts WHERE id = ?', [$id]);
            flash('Schicht gelöscht.');
        } elseif ($action === 'copy_prev') {
            if (q_one('SELECT id FROM shifts WHERE shift_date BETWEEN ? AND ? LIMIT 1', [$ws, $we])) {
                throw new DomainException('Diese Woche enthält schon Schichten – Kopieren nur in eine leere Woche.');
            }
            $n = q("INSERT INTO shifts (user_id, shift_date, start_time, end_time, break_min, project_id, note)
                    SELECT s.user_id, DATE_ADD(s.shift_date, INTERVAL 7 DAY), s.start_time, s.end_time, s.break_min, s.project_id, s.note
                    FROM shifts s JOIN users u ON u.id = s.user_id AND u.active = 1
                    WHERE s.shift_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_SUB(?, INTERVAL 7 DAY)", [$ws, $we])->rowCount();
            flash($n ? "$n Schichten aus der Vorwoche kopiert." : 'Die Vorwoche enthält keine Schichten.', $n ? 'ok' : 'err');
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect($self);
}

// ---- Daten der Woche ----
$users = q_all('SELECT id, full_name, weekly_hours FROM users WHERE active = 1 ORDER BY full_name');
$cells = [];
$dayMin = [];
$dayCount = [];
$planMin = [];
foreach (q_all('SELECT s.*, p.name AS project_name FROM shifts s LEFT JOIN projects p ON p.id = s.project_id
                WHERE s.shift_date BETWEEN ? AND ? ORDER BY s.shift_date, s.start_time', [$ws, $we]) as $s) {
    $m = shift_minutes($s['start_time'], $s['end_time'], (int)$s['break_min']);
    $s['minutes'] = $m;
    $cells[$s['user_id']][$s['shift_date']][] = $s;
    $dayMin[$s['shift_date']] = ($dayMin[$s['shift_date']] ?? 0) + $m;
    $planMin[$s['user_id']] = ($planMin[$s['user_id']] ?? 0) + $m;
}
foreach ($cells as $byDate) {
    foreach ($byDate as $d => $list) {
        $dayCount[$d] = ($dayCount[$d] ?? 0) + 1; // Mitarbeiter mit Schicht an dem Tag
    }
}

$days = [];
$wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime("$ws +$i days"));
}
$projects = q_all('SELECT id, name FROM projects WHERE active = 1 ORDER BY name');
$totalPlan = array_sum($planMin);

page_header('Dienstplan', 'schedule');
?>
<div class="row filter">
    <a class="btn" href="schedule.php?week=<?= e(date('Y-m-d', strtotime("$ws -7 days"))) ?>">‹ Vorwoche</a>
    <a class="btn" href="schedule.php">Diese Woche</a>
    <a class="btn" href="schedule.php?week=<?= e(date('Y-m-d', strtotime("$ws +7 days"))) ?>">Nächste Woche ›</a>
    <b>KW <?= e(date('W', strtotime($ws))) ?> · <?= e(fmt_d($ws)) ?> – <?= e(fmt_d($we)) ?></b>
    <?php if ($admin): ?>
        <form method="post" onsubmit="return confirm('Alle Schichten der Vorwoche in diese Woche kopieren?')">
            <?= csrf_field() ?><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="action" value="copy_prev">
            <button class="ghost">Vorwoche kopieren</button>
        </form>
    <?php endif; ?>
</div>

<div class="plan-wrap">
<table class="plan">
    <tr>
        <th class="emp">Mitarbeiter</th>
        <?php foreach ($days as $i => $d): ?>
            <th class="<?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= $wd[$i] ?> <?= e(date('d.m.', strtotime($d))) ?></th>
        <?php endforeach; ?>
        <th>Stunden</th>
    </tr>
    <?php foreach ($users as $u):
        $uid = (int)$u['id'];
        $abs = absence_days($uid, $ws, $we);
        $plan = $planMin[$uid] ?? 0;
        $soll = (int)round((float)$u['weekly_hours'] * 60);
        $ist = intdiv(worked_between($uid, $ws, $we), 60);
        $diff = $plan - $soll; ?>
        <tr class="<?= $uid === (int)$me['id'] ? 'mine' : '' ?>">
            <td class="emp"><b><?= e($u['full_name']) ?></b></td>
            <?php foreach ($days as $d): ?>
                <td class="cell <?= $d === date('Y-m-d') ? 'today' : '' ?>">
                    <?php if (isset($abs[$d])): ?><span class="tag pending"><?= e(ABSENCE_TYPES[$abs[$d]]) ?></span><?php endif; ?>
                    <?php foreach ($cells[$uid][$d] ?? [] as $s):
                        $label = '<b>' . e(substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)) . '</b> <span class="muted">' . fmt_hm($s['minutes'] * 60) . ' h</span>'
                            . ($s['project_name'] ? '<br><small>' . e($s['project_name']) . '</small>' : '')
                            . ($s['note'] ? '<br><small class="muted">' . e($s['note']) . '</small>' : '');
                        if ($admin): ?>
                            <button type="button" class="shift" data-shift="<?= e(json_encode([
                                'id' => (int)$s['id'], 'user_id' => $uid, 'date' => $d, 'start' => substr($s['start_time'], 0, 5),
                                'end' => substr($s['end_time'], 0, 5), 'brk' => (int)$s['break_min'],
                                'project' => (int)$s['project_id'], 'note' => $s['note'],
                            ])) ?>"><?= $label ?></button>
                        <?php else: ?>
                            <div class="shift"><?= $label ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($admin): ?>
                        <button type="button" class="add" title="Schicht hinzufügen" data-user="<?= $uid ?>" data-date="<?= e($d) ?>">+</button>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td class="sums nowrap">
                <b><?= fmt_hm($plan * 60) ?></b> geplant<br>
                <span class="muted">Soll <?= fmt_hm($soll * 60) ?> · Ist <?= fmt_hm($ist * 60) ?></span><br>
                <span class="<?= $diff > 0 ? 'neg' : ($diff < 0 ? 'warnc' : 'pos') ?>"><?= $diff === 0 ? '✓ passt' : ($diff > 0 ? '+' : '') . fmt_hm($diff * 60) . ' zum Soll' ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="9" class="muted">Keine aktiven Mitarbeiter.</td></tr><?php endif; ?>
    <tr class="sum">
        <td class="emp">Summe pro Tag</td>
        <?php foreach ($days as $d): ?>
            <td><?= fmt_hm(($dayMin[$d] ?? 0) * 60) ?> h<br><span class="muted"><?= (int)($dayCount[$d] ?? 0) ?> MA</span></td>
        <?php endforeach; ?>
        <td><b><?= fmt_hm($totalPlan * 60) ?> h</b></td>
    </tr>
</table>
</div>
<p class="muted">Stunden = Schichtlänge abzüglich Pause. „Ist“ sind die tatsächlich gestempelten Stunden dieser Woche. Ende vor Beginn = Schicht über Mitternacht.</p>

<?php if ($admin): ?>
<dialog id="dlg">
    <form method="post" class="stack">
        <h2 id="dlgTitle">Schicht</h2>
        <?= csrf_field() ?>
        <input type="hidden" name="week" value="<?= e($ws) ?>">
        <input type="hidden" name="id" id="f_id" value="0">
        <label>Mitarbeiter <select name="user_id" id="f_user"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?></select></label>
        <label>Datum <input type="date" name="shift_date" id="f_date" required></label>
        <div class="row" style="justify-content:flex-start">
            <label>Beginn <input type="time" name="start_time" id="f_start" value="08:00" required></label>
            <label>Ende <input type="time" name="end_time" id="f_end" value="16:30" required></label>
            <label>Pause (Min) <input type="number" min="0" name="break_min" id="f_brk" value="30" style="width:90px"></label>
        </div>
        <label>Projekt <select name="project_id" id="f_proj"><option value="0">– kein Projekt –</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
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
        $('f_proj').value = v.project || 0; $('f_note').value = v.note || '';
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
