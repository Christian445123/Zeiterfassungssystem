<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login();

if (is_post() && ($_POST['action'] ?? '') === 'save_entry') {
    try {
        flash(manual_entry($me, $_POST));
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('dashboard.php');
}

$d = dashboard_data($me);
$tracks = user_tracks($me);
$recent = can('hours.own') && $tracks ? array_slice(entries_between((int)$me['id'], date('Y-m-d', strtotime('-60 days')), date('Y-m-d')), 0, 8) : [];

// Update-Hinweis + Auto-Update nur für Rolleninhaber mit Update-Recht
$updateNotice = null;
if (can('updates.manage')) {
    try {
        $tick = panel_auto_tick(); // prüft max. 1x/Tag, installiert im Modus „automatisch“
        if ($tick && $tick['type'] !== 'info') {
            flash($tick['msg'], $tick['type']);
        }
        $latest = panel_latest_known();
        $updateNotice = $latest && $latest['available'] ? $latest['short'] : null;
    } catch (Throwable $ex) {
        error_log('Update-Check: ' . $ex->getMessage());
    }
}

page_header('Hallo ' . explode(' ', $me['full_name'])[0], 'dash');
if ($updateNotice): ?>
    <div class="flash ok">Neue Panel-Version <b><?= e($updateNotice) ?></b> verfügbar – <a href="updates.php">zu den Updates</a></div>
<?php endif; ?>

<?php if (!$tracks): ?>
    <div class="flash ok">Dies ist ein <b>Verwaltungskonto</b>: Hier wird keine Zeit erfasst. Du kannst Mitarbeiter einteilen (Dienstplan), Urlaube und Rollen verwalten und Einstellungen vornehmen.</div>
<?php endif; ?>

<?php if (can('hours.own') && $tracks): ?>
<div class="cards">
    <div class="card"><div class="lbl">Heute</div><div class="val"><?= fmt_hm($d['today_seconds']) ?> h</div></div>
    <div class="card"><div class="lbl">Diese Woche</div><div class="val"><?= fmt_hm($d['week']['worked']) ?> <small>/ <?= fmt_hm($d['week']['target']) ?> h</small></div></div>
    <?php if ($d['month']): ?>
        <div class="card"><div class="lbl">Dieser Monat (automatisch summiert)</div><div class="val"><?= fmt_hm($d['month']['worked']) ?> <small>/ <?= fmt_hm($d['month']['target']) ?> h</small></div>
            <div class="muted"><?= $d['month']['closed'] ? 'Monat abgeschlossen' : 'Noch offen – Abschluss unter <a href="months.php">Monatsabschluss</a>' ?></div></div>
    <?php endif; ?>
    <div class="card"><div class="lbl">Plus-/Minusstunden</div><div class="val <?= $d['overtime_seconds'] < 0 ? 'neg' : 'pos' ?>"><?= ($d['overtime_seconds'] > 0 ? '+' : '') . fmt_hm($d['overtime_seconds']) ?> h</div></div>
    <?php if ($d['vacation']): ?>
        <div class="card"><div class="lbl">Resturlaub <?= (int)$d['vacation']['year'] ?></div><div class="val"><?= e(rtrim(rtrim(number_format($d['vacation']['remaining'], 1, ',', ''), '0'), ',')) ?> <small>Tage</small></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0">Stunden eintragen</h2>
    <form method="post" class="row filter">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_entry">
        <?php $canAbs = can('absences.request') || can('absences.manage'); ?>
        <label>Art <select name="kind" id="kind"><option value="work">Arbeitszeit</option>
            <?php if ($canAbs): foreach (['doctor', 'sick', 'vacation', 'comp', 'other'] as $k): ?><option value="<?= $k ?>"><?= e(ABSENCE_TYPES[$k]) ?></option><?php endforeach; endif; ?></select></label>
        <label>Datum <input type="date" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" data-max="<?= date('Y-m-d') ?>" required></label>
        <label>Von <input type="time" name="start" data-wt required></label>
        <label>Bis <input type="time" name="end" data-wt required></label>
        <label>Pause (Min) <input type="number" name="break_min" min="0" value="0" style="width:90px"></label>
        <label>Notiz <input name="note" maxlength="500" placeholder="optional"></label>
        <button class="go">Eintragen</button>
    </form>
    <div class="muted">Ende vor Beginn = Nachtschicht über Mitternacht. Arbeitszeit kann nur für bereits Geleistetes eingetragen werden.</div>
    <div class="muted" id="kindHint" style="display:none">Arzt, Krankenstand &amp; Co.: <b>ohne Von/Bis = ganzer Tag</b>, mit Von/Bis nur diese Stunden (z. B. Arzt 10:00–11:30). Auch für kommende Tage möglich; wartet auf Genehmigung.</div>
    <script>
(function () {
    var k = document.getElementById('kind');
    if (!k) return;
    function sync() {
        var work = k.value === 'work';
        document.querySelectorAll('[data-wt]').forEach(function (i) { i.required = work; });
        var d = document.querySelector('input[name=date]');
        if (d && d.dataset.max) { if (work) d.max = d.dataset.max; else d.removeAttribute('max'); }
        var h = document.getElementById('kindHint'); if (h) h.style.display = work ? 'none' : '';
    }
    k.addEventListener('change', sync); sync();
})();
</script>

</div>
<?php endif; ?>

<?php if (can('schedule.view')): ?>
    <h2>Meine Schichten (nächste 7 Tage)</h2>
    <table>
        <tr><th>Tag</th><th>Zeit</th><th>Stunden</th><th>Notiz</th></tr>
        <?php foreach ($d['shifts'] as $s): ?>
            <tr><td><?= e(WEEKDAY_SHORT[(int)date('N', strtotime($s['date']))] . ' ' . date('d.m.Y', strtotime($s['date']))) ?></td>
                <td><?= e($s['start'] . '–' . $s['end']) ?></td><td><?= fmt_hm($s['minutes'] * 60) ?></td>
                <td><?= e($s['note']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['shifts']): ?><tr><td colspan="4" class="muted">Keine Schichten eingeplant. <a href="schedule.php">Zum Dienstplan</a></td></tr><?php endif; ?>
    </table>
<?php endif; ?>

<?php if ($recent): ?>
    <h2>Meine letzten Einträge</h2>
    <table>
        <tr><th>Tag</th><th>Von – Bis</th><th>Pause</th><th>Arbeitszeit</th><th>Notiz</th><th></th></tr>
        <?php foreach ($recent as $r): ?>
            <tr><td><?= e(fmt_d($r['start_time'])) ?></td>
                <td><?= e(date('H:i', strtotime($r['start_time']))) ?>–<?= $r['end_time'] ? e(date('H:i', strtotime($r['end_time']))) : '?' ?></td>
                <td><?= fmt_hm((int)$r['break_sec']) ?></td><td><b><?= fmt_hm((int)$r['worked_sec']) ?></b></td>
                <td><?= e($r['note']) ?></td>
                <td><?php if (entry_editable($me, $r)): ?><a href="edit_entry.php?id=<?= (int)$r['id'] ?>">Bearbeiten</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php page_footer();
