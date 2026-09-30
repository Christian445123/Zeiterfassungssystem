<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login(true);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$entry = $id ? entry_by_id($id) : null;
if ($id && !$entry) {
    flash('Eintrag nicht gefunden.', 'err');
    redirect('entries.php');
}

if (is_post()) {
    $start = strtotime((string)($_POST['start_time'] ?? ''));
    $endRaw = trim((string)($_POST['end_time'] ?? ''));
    $end = $endRaw === '' ? null : strtotime($endRaw);
    $breakMin = max(0, (int)($_POST['manual_break_min'] ?? 0));
    $pid = (int)($_POST['project_id'] ?? 0) ?: null;
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    $userId = $entry ? (int)$entry['user_id'] : (int)($_POST['user_id'] ?? 0);

    if (!$start || ($endRaw !== '' && !$end)) {
        flash('Ungültiges Datum.', 'err');
    } elseif ($end !== null && $end <= $start) {
        flash('Ende muss nach dem Beginn liegen.', 'err');
    } elseif (!$userId) {
        flash('Mitarbeiter wählen.', 'err');
    } elseif ($end === null && ($o = open_entry($userId)) && (int)$o['id'] !== $id) {
        flash('Dieser Mitarbeiter hat bereits einen laufenden Eintrag.', 'err');
    } else {
        $s = date('Y-m-d H:i:s', $start);
        $e2 = $end ? date('Y-m-d H:i:s', $end) : null;
        if ($entry) {
            q('UPDATE time_entries SET project_id=?, start_time=?, end_time=?, manual_break_min=?, note=? WHERE id=?',
                [$pid, $s, $e2, $breakMin, $note, $id]);
        } else {
            q("INSERT INTO time_entries (user_id, project_id, start_time, end_time, manual_break_min, note, source) VALUES (?,?,?,?,?,?, 'manual')",
                [$userId, $pid, $s, $e2, $breakMin, $note]);
        }
        flash('Gespeichert.');
        redirect('entries.php');
    }
}

$v = fn(string $k, $default = '') => $_POST[$k] ?? $default;
$startVal = $v('start_time', $entry ? date('Y-m-d\TH:i', strtotime($entry['start_time'])) : date('Y-m-d\T08:00'));
$endVal = $v('end_time', $entry && $entry['end_time'] ? date('Y-m-d\TH:i', strtotime($entry['end_time'])) : '');

page_header($entry ? 'Eintrag bearbeiten' : 'Manueller Eintrag', 'entries');
?>
<form method="post" class="stack" style="max-width:480px">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($entry): ?>
        <p>Mitarbeiter: <b><?= e($entry['full_name']) ?></b></p>
    <?php else: ?>
        <label>Mitarbeiter <?= user_select('user_id', (int)$v('user_id', 0)) ?></label>
    <?php endif; ?>
    <label>Kommen <input type="datetime-local" name="start_time" value="<?= e($startVal) ?>" required></label>
    <label>Gehen (leer = läuft noch) <input type="datetime-local" name="end_time" value="<?= e($endVal) ?>"></label>
    <label>Zusätzliche Pause (Minuten) <input type="number" min="0" name="manual_break_min" value="<?= (int)$v('manual_break_min', $entry['manual_break_min'] ?? 0) ?>"></label>
    <label>Projekt <?= project_select('project_id', isset($_POST['project_id']) ? ((int)$_POST['project_id'] ?: null) : ($entry['project_id'] ?? null)) ?></label>
    <label>Notiz <input name="note" maxlength="500" value="<?= e($v('note', $entry['note'] ?? '')) ?>"></label>
    <?php if ($entry): ?>
        <?php $bs = q_all('SELECT * FROM breaks WHERE entry_id = ? ORDER BY start_time', [$id]); if ($bs): ?>
            <div class="muted">Erfasste Pausen: <?= implode(', ', array_map(fn($b) => date('H:i', strtotime($b['start_time'])) . '–' . ($b['end_time'] ? date('H:i', strtotime($b['end_time'])) : 'läuft'), $bs)) ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="row"><button>Speichern</button><a class="btn" href="entries.php">Abbrechen</a></div>
</form>
<?php page_footer();
