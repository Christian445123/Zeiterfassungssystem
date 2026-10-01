<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('absences.request', 'absences.manage');
$manage = can('absences.manage');

if (is_post()) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        switch ($_POST['action'] ?? '') {
            case 'create':
                $new = absence_create($me, $_POST);
                flash($manage ? 'Abwesenheit eingetragen.' : 'Antrag gestellt.');
                break;
            case 'approved':
            case 'rejected':
                absence_set_status($me, $id, $_POST['action']);
                flash('Status geändert.');
                break;
            case 'delete':
                absence_delete($me, $id);
                flash('Gelöscht.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('absences.php');
}

$rows = $manage
    ? q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id ORDER BY a.date_from DESC LIMIT 300')
    : q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.date_from DESC', [$me['id']]);
$vac = vacation_summary($me, (int)date('Y'));
$num = fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

page_header('Abwesenheiten', 'abs');
?>
<?php if (user_tracks($me)): ?><div class="cards">
    <div class="card"><div class="lbl">Mein Urlaubsanspruch <?= (int)$vac['year'] ?></div><div class="val"><?= $num($vac['entitlement'] + $vac['carryover']) ?> <small>Tage</small></div>
        <div class="muted"><?= (float)cfg('vacation_weeks') ?> Wochen automatisch<?= $vac['carryover'] > 0 ? ' + ' . $num($vac['carryover']) . ' Übertrag' : '' ?></div></div>
    <div class="card"><div class="lbl">Genommen</div><div class="val"><?= $num($vac['taken']) ?></div><div class="muted"><?= $num($vac['pending']) ?> beantragt</div></div>
    <div class="card"><div class="lbl">Resturlaub</div><div class="val <?= $vac['remaining'] < 0 ? 'neg' : 'pos' ?>"><?= $num($vac['remaining']) ?></div></div>
    <div class="card"><div class="lbl">Krank / Arzt / ZA</div><div class="val"><?= $num($vac['sick_days']) ?> / <?= $num($vac['doctor_days']) ?> / <?= $num($vac['comp_days']) ?> <small>Tage</small></div></div>
</div><?php endif; ?>

<form method="post" class="row filter card">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <?php if ($manage): ?><label>Mitarbeiter <?= user_select('user_id', (int)$me['id']) ?></label><?php endif; ?>
    <label>Art <select name="type"><?php foreach (ABSENCE_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Von <input type="date" name="date_from" required></label>
    <label>Bis <input type="date" name="date_to" required></label>
    <label>Stunden (nur 1 Tag, leer = ganzer Tag) <input type="number" step="0.25" min="0.25" max="24" name="hours" style="width:110px"></label>
    <label>Notiz <input name="note" maxlength="255"></label>
    <button><?= $manage ? 'Eintragen' : 'Beantragen' ?></button>
</form>

<table>
    <tr><?php if ($manage): ?><th>Mitarbeiter</th><?php endif; ?><th>Art</th><th>Von</th><th>Bis</th><th>Std.</th><th>Notiz</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($manage): ?><td><?= e($r['full_name']) ?></td><?php endif; ?>
            <td><?= e(ABSENCE_TYPES[$r['type']] ?? $r['type']) ?></td>
            <td><?= e(fmt_d($r['date_from'])) ?></td><td><?= e(fmt_d($r['date_to'])) ?></td>
            <td><?= $r['hours'] !== null ? e($r['hours']) : 'ganztägig' ?></td>
            <td><?= e($r['note']) ?></td>
            <td><span class="tag <?= e($r['status']) ?>"><?= e(ABSENCE_STATUS[$r['status']]) ?></span></td>
            <td class="nowrap">
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <?php if ($manage && $r['status'] !== 'approved'): ?><button class="link" name="action" value="approved">Genehmigen</button><?php endif; ?>
                    <?php if ($manage && $r['status'] !== 'rejected'): ?><button class="link" name="action" value="rejected">Ablehnen</button><?php endif; ?>
                    <?php if ($manage || $r['status'] === 'pending'): ?><button class="link danger" name="action" value="delete" onclick="return confirm('Löschen?')">Löschen</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Keine Einträge.</td></tr><?php endif; ?>
</table>
<p class="muted">Urlaub zählt nur Arbeitstage laut Arbeitszeitmodell ohne Feiertage. Arzt kann ganztägig oder mit Stundenangabe eingetragen werden.</p>
<?php page_footer();
