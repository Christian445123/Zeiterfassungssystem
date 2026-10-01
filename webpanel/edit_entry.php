<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('hours.own', 'hours.edit_all');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$entry = $id ? entry_by_id($id) : null;
if ($id && (!$entry || !entry_editable($me, $entry))) {
    flash('Dieser Eintrag existiert nicht oder kann nicht mehr geändert werden.', 'err');
    redirect('entries.php');
}

if (is_post()) {
    try {
        entry_save($me, $_POST + ['source' => 'web']);
        flash('Gespeichert.');
        redirect('entries.php');
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
}

$v = fn(string $k, $default = '') => $_POST[$k] ?? $default;
$dateVal = $v('date', $entry ? substr($entry['start_time'], 0, 10) : date('Y-m-d'));
$startVal = $v('start', $entry ? substr($entry['start_time'], 11, 5) : '');
$endVal = $v('end', $entry && $entry['end_time'] ? substr($entry['end_time'], 11, 5) : '');
$allEdit = can('hours.edit_all');

page_header($entry ? 'Eintrag bearbeiten' : 'Stunden eintragen', 'entries');
?>
<form method="post" class="stack card" style="max-width:520px">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($entry): ?>
        <p>Mitarbeiter: <b><?= e($entry['full_name']) ?></b></p>
    <?php elseif ($allEdit): ?>
        <label>Mitarbeiter <?= user_select('user_id', (int)$v('user_id', $me['id'])) ?></label>
    <?php endif; ?>
    <label>Datum <input type="date" name="date" value="<?= e($dateVal) ?>" required></label>
    <div class="row" style="justify-content:flex-start">
        <label>Von <input type="time" name="start" value="<?= e($startVal) ?>" required></label>
        <label>Bis <input type="time" name="end" value="<?= e($endVal) ?>" required></label>
        <label>Pause (Min) <input type="number" min="0" name="break_min" value="<?= (int)$v('break_min', $entry['manual_break_min'] ?? 0) ?>" style="width:100px"></label>
    </div>    <label>Notiz <input name="note" maxlength="500" value="<?= e($v('note', $entry['note'] ?? '')) ?>"></label>
    <div class="muted">Ende vor Beginn = Nachtschicht über Mitternacht. Überschneidungen mit anderen Einträgen sind nicht möglich.</div>
    <div class="row" style="justify-content:flex-start"><button>Speichern</button><a class="btn ghost" href="entries.php">Abbrechen</a></div>
</form>
<?php page_footer();
