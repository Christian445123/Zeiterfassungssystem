<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('users.view', 'users.manage');
$manage = can('users.manage');

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                $in = $_POST;
                $in['day_hours'] = (array)($_POST['day_hours'] ?? []);
                $in['active'] = isset($_POST['id']) && (int)$_POST['id'] > 0 ? isset($_POST['active']) : 1;
                user_save($me, $in);
                flash('Gespeichert.');
                break;
            case 'overtime':
                $min = (int)round((float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')) * 60);
                overtime_adjust($me, (int)$_POST['user_id'], (string)($_POST['date'] ?? ''), $min, (string)($_POST['note'] ?? ''));
                flash('Überstunden gebucht.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('users.php');
}

$edit = isset($_GET['edit']) ? q_one('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']]) : null;
$roles = q_all('SELECT id, name FROM roles ORDER BY is_system DESC, name');
$users = q_all('SELECT * FROM users ORDER BY active DESC, full_name');
$num = fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

page_header('Mitarbeiter', 'users');
?>
<?php if ($manage): ?>
<div class="card" style="max-width:760px">
    <h2 style="margin-top:0"><?= $edit ? 'Bearbeiten: ' . e($edit['full_name']) : 'Neuer Mitarbeiter' ?></h2>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="row" style="justify-content:flex-start">
            <label>Personalnummer<input name="personnel_number" value="<?= e($edit['personnel_number'] ?? '') ?>" placeholder="<?= $edit ? '' : 'automatisch ' . e(next_personnel_number()) ?>" style="width:150px"></label>
            <label>Name<input name="full_name" required value="<?= e($edit['full_name'] ?? '') ?>" style="width:260px"></label>
            <label>Rolle<select name="role_id"><?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (int)($edit['role_id'] ?? 0) === (int)$r['id'] || (!$edit && $r['name'] === 'Mitarbeiter') ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
        </div>
        <fieldset class="fs"><legend>Arbeitszeitmodell – Soll-Stunden je Wochentag</legend>
            <div class="row" style="justify-content:flex-start">
                <?php $dh = $edit ? user_day_hours($edit) : [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 8]; foreach (WEEKDAY_SHORT as $n => $l): ?>
                    <label><?= $l ?><input name="day_hours[<?= $n ?>]" value="<?= isset($dh[$n]) ? e(rtrim(rtrim(number_format($dh[$n], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="frei" style="width:70px"></label>
                <?php endforeach; ?>
                <label>Eintrittsdatum<input type="date" name="start_date" value="<?= e($edit['start_date'] ?? '') ?>"></label>
            </div>
            <div class="muted">Pro Wochentag die Stunden eintragen, an denen der Mitarbeiter arbeitet (leer = frei). Die Wochenstunden ergeben sich automatisch. Danach richten sich Soll, Plus-/Minusstunden und Urlaubstage.</div>
        </fieldset>
        <fieldset class="fs"><legend>Urlaub</legend>
            <div class="row" style="justify-content:flex-start">
                <label>Urlaubstage pro Jahr (leer = automatisch <?= (float)cfg('vacation_weeks') ?> Wochen)<input name="vacation_days" value="<?= e($edit['vacation_days_override'] ?? '') ?>" style="width:150px"></label>
                <label>Resturlaub-Übertrag (Tage)<input name="vacation_carryover" value="<?= e($edit['vacation_carryover'] ?? '0') ?>" style="width:130px"></label>
                <label>… gilt für Jahr<input name="vacation_carryover_year" value="<?= e($edit['vacation_carryover_year'] ?? date('Y')) ?>" style="width:90px"></label>
            </div>
        </fieldset>
        <label><?= $edit ? 'Neues Passwort (leer = unverändert; muss beim nächsten Login geändert werden)' : 'Start-Passwort (min. 8 Zeichen; muss beim ersten Login geändert werden)' ?>
            <input type="password" name="password" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></label>
        <label class="check"><input type="checkbox" name="time_tracking" value="1" <?= !$edit || !array_key_exists('time_tracking', $edit) || (int)$edit['time_tracking'] === 1 ? 'checked' : '' ?>>
            Zeiterfassung aktiv <span class="muted">(Stunden, Dienstplan, Urlaub – für reine Verwaltungskonten wie den Administrator ausschalten)</span></label>
        <?php if ($edit): ?><label class="check"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktiv (kann sich anmelden)</label><?php endif; ?>
        <div class="row" style="justify-content:flex-start"><button><?= $edit ? 'Speichern' : 'Anlegen' ?></button><?php if ($edit): ?><a class="btn ghost" href="users.php">Abbrechen</a><?php endif; ?></div>
    </form>
</div>
<?php endif; ?>

<table>
    <tr><th>Nr.</th><th>Name</th><th>Rolle</th><th>Std./Woche</th><th>Arbeitstage (Stunden)</th><th>Urlaub (Rest/Anspruch)</th><th>Überstunden</th><th>Status</th><th></th></tr>
    <?php foreach ($users as $u):
        $ov = user_overview($u); $v = $ov['vacation']; ?>
        <tr class="<?= $u['active'] ? '' : 'weekend' ?>">
            <td><?= e($u['personnel_number']) ?></td><td><?= e($u['full_name']) ?></td><td><?= e($ov['role']) ?><?= $ov['time_tracking'] ? '' : ' <span class="tag">Verwaltung</span>' ?></td>
            <td><?= e($u['weekly_hours']) ?></td>
            <td><?= e(implode(' · ', array_map(fn($d, $h) => WEEKDAY_SHORT[$d] . ' ' . rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ','), array_keys($ov['day_hours']), $ov['day_hours']))) ?></td>
            <td><?= $num($v['remaining']) ?> / <?= $num($v['entitlement'] + $v['carryover']) ?></td>
            <td class="<?= $ov['overtime_seconds'] < 0 ? 'neg' : '' ?>"><?= ($ov['overtime_seconds'] > 0 ? '+' : '') . fmt_hm($ov['overtime_seconds']) ?> h</td>
            <td><?= $u['active'] ? 'Aktiv' : 'Deaktiviert' ?></td>
            <td class="nowrap"><?php if ($manage): ?><a href="users.php?edit=<?= (int)$u['id'] ?>">Bearbeiten</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if (can('overtime.manage')): ?>
<div class="card" style="max-width:760px">
    <h2 style="margin-top:0">Überstunden buchen</h2>
    <form method="post" class="row filter">
        <?= csrf_field() ?><input type="hidden" name="action" value="overtime">
        <label>Mitarbeiter <?= user_select('user_id', (int)($edit['id'] ?? 0)) ?></label>
        <label>Datum <input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
        <label>Stunden (+ gutschreiben, − auszahlen) <input name="hours" placeholder="-8" required style="width:150px"></label>
        <label>Notiz <input name="note" maxlength="255" placeholder="z. B. Auszahlung"></label>
        <button>Buchen</button>
    </form>
</div>
<?php endif; ?>
<?php page_footer();
