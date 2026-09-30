<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();
$uid = (int)$me['id'];

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'in':
                $pid = (int)($_POST['project_id'] ?? 0);
                clock_in($uid, $pid ?: null, (string)($_POST['note'] ?? ''), 'web');
                flash('Eingestempelt.');
                break;
            case 'out':
                clock_out($uid);
                flash('Ausgestempelt.');
                break;
            case 'break_start':
                break_start($uid);
                flash('Pause gestartet.');
                break;
            case 'break_end':
                break_end($uid);
                flash('Pause beendet.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('dashboard.php');
}

$st = user_status($uid);
$updateNotice = null;
if ($me['role'] === 'admin') {
    try {
        ensure_migrated();
        $tick = panel_auto_tick(); // prüft max. 1x/Tag, installiert im Modus „automatisch“
        if ($tick && $tick['type'] !== 'info') {
            flash($tick['msg'], $tick['type']);
        }
        $latest = panel_latest_known();
        $updateNotice = $latest && $latest['available'] ? $latest['version'] : null;
    } catch (Throwable $ex) {
        error_log('Update-Check: ' . $ex->getMessage());
    }
}
page_header('Dashboard', 'dash');
if ($updateNotice): ?>
    <div class="flash ok">Neue Panel-Version <b><?= e($updateNotice) ?></b> verfügbar – <a href="updates.php">zu den Updates</a></div>
<?php endif;
?>
<div class="card clock">
    <?php if (!$st['clocked_in']): ?>
        <div class="state off">Nicht eingestempelt</div>
        <form method="post" class="row">
            <?= csrf_field() ?><input type="hidden" name="action" value="in">
            <?= project_select('project_id', null) ?>
            <input name="note" placeholder="Notiz (optional)" maxlength="500">
            <button class="big go">Kommen</button>
        </form>
    <?php else: ?>
        <div class="state <?= $st['on_break'] ? 'brk' : 'on' ?>">
            <?= $st['on_break'] ? 'In Pause' : 'Eingestempelt' ?> seit <?= e(date('H:i', strtotime($st['entry']['start']))) ?>
            <?= $st['entry']['project_name'] ? ' · ' . e($st['entry']['project_name']) : '' ?>
        </div>
        <div class="timer" id="timer" data-sec="<?= (int)$st['current_seconds'] ?>" data-run="<?= $st['on_break'] ? 0 : 1 ?>">0:00:00</div>
        <form method="post" class="row">
            <?= csrf_field() ?>
            <?php if ($st['on_break']): ?>
                <button name="action" value="break_end" class="big">Pause beenden</button>
            <?php else: ?>
                <button name="action" value="break_start" class="big">Pause</button>
            <?php endif; ?>
            <button name="action" value="out" class="big stop">Gehen</button>
        </form>
    <?php endif; ?>
</div>

<div class="cards">
    <div class="card"><div class="lbl">Heute</div><div class="val"><?= fmt_hm($st['today_seconds']) ?> h</div></div>
    <div class="card"><div class="lbl">Diese Woche</div><div class="val"><?= fmt_hm($st['week_seconds']) ?> / <?= fmt_hm($st['week_target_seconds']) ?> h</div></div>
</div>

<?php
db()->exec(SHIFTS_SQL);
$myShifts = q_all('SELECT s.*, p.name AS project_name FROM shifts s LEFT JOIN projects p ON p.id = s.project_id
                   WHERE s.user_id = ? AND s.shift_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                   ORDER BY s.shift_date, s.start_time', [$uid]); ?>
<h2>Meine Schichten (nächste 7 Tage)</h2>
<table>
    <tr><th>Tag</th><th>Zeit</th><th>Stunden</th><th>Projekt</th><th>Notiz</th></tr>
    <?php foreach ($myShifts as $s): ?>
        <tr><td><?= e(date('d.m.Y', strtotime($s['shift_date']))) ?></td>
            <td><?= e(substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)) ?></td>
            <td><?= fmt_hm(shift_minutes($s['start_time'], $s['end_time'], (int)$s['break_min']) * 60) ?></td>
            <td><?= e($s['project_name'] ?? '–') ?></td><td><?= e($s['note']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$myShifts): ?><tr><td colspan="5" class="muted">Keine Schichten eingeplant. <a href="schedule.php">Zum Dienstplan</a></td></tr><?php endif; ?>
</table>

<?php if ($me['role'] === 'admin'):
    $live = q_all("SELECT u.full_name, e.start_time, p.name AS project_name,
                   (SELECT COUNT(*) FROM breaks b WHERE b.entry_id = e.id AND b.end_time IS NULL) AS on_break
                   FROM time_entries e JOIN users u ON u.id = e.user_id LEFT JOIN projects p ON p.id = e.project_id
                   WHERE e.end_time IS NULL ORDER BY e.start_time"); ?>
    <h2>Aktuell eingestempelt (<?= count($live) ?>)</h2>
    <table>
        <tr><th>Mitarbeiter</th><th>Seit</th><th>Projekt</th><th>Status</th></tr>
        <?php foreach ($live as $r): ?>
            <tr><td><?= e($r['full_name']) ?></td><td><?= e(fmt_dt($r['start_time'])) ?></td>
                <td><?= e($r['project_name'] ?? '–') ?></td><td><?= $r['on_break'] ? 'Pause' : 'Arbeit' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$live): ?><tr><td colspan="4" class="muted">Niemand eingestempelt.</td></tr><?php endif; ?>
    </table>
<?php endif; ?>

<script>
(function () {
    var t = document.getElementById('timer');
    if (!t) return;
    var base = parseInt(t.dataset.sec, 10), run = t.dataset.run === '1', t0 = Date.now();
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function draw() {
        var s = base + (run ? Math.floor((Date.now() - t0) / 1000) : 0);
        t.textContent = Math.floor(s / 3600) + ':' + pad(Math.floor(s % 3600 / 60)) + ':' + pad(s % 60);
    }
    draw(); setInterval(draw, 1000);
})();
</script>
<?php page_footer();
