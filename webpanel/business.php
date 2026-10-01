<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('business.manage');

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'hours':
                business_save_hours($me, $_POST);
                flash('Öffnungszeiten gespeichert.');
                break;
            case 'special_save':
                special_day_save($me, $_POST);
                flash('Sondertag gespeichert.');
                break;
            case 'holiday_open':
                // Feiertag trotzdem öffnen (reguläre Zeiten des Wochentags)
                special_day_save($me, ['date' => $_POST['date'] ?? '', 'kind' => 'open', 'name' => ($_POST['name'] ?? '') . ' (geöffnet)']);
                flash('Der Feiertag wurde als geöffnet markiert.');
                break;
            case 'special_delete':
                special_day_delete($me, (string)($_POST['date'] ?? ''));
                flash('Eintrag entfernt – es gilt wieder die Standardregel.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('business.php?year=' . (int)($_POST['year'] ?? date('Y')));
}

$year = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));
$oh = opening_hours();
$holidays = holidays_for_year($year);
ksort($holidays);
$specials = special_days_map();
ksort($specials);

page_header('Betrieb: Öffnungszeiten & Feiertage', 'business');
?>
<div class="card" style="max-width:640px">
    <h2 style="margin-top:0">Öffnungszeiten</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="hours"><input type="hidden" name="year" value="<?= $year ?>">
        <table class="plain">
            <?php foreach (WEEKDAY_SHORT as $d => $label): $h = $oh[$d]; ?>
                <tr>
                    <td style="width:60px"><b><?= e($label) ?></b></td>
                    <td><label class="check"><input type="checkbox" name="open[<?= $d ?>]" value="1" <?= $h ? 'checked' : '' ?>> geöffnet</label></td>
                    <td><input type="time" name="from[<?= $d ?>]" value="<?= e($h[0] ?? '07:00') ?>"></td>
                    <td>bis</td>
                    <td><input type="time" name="to[<?= $d ?>]" value="<?= e($h[1] ?? '18:30') ?>"></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <div class="muted">Standard: Mo–Fr 07:00–18:30, Sa 07:30–13:00, So geschlossen. An geschlossenen Tagen gibt es kein Soll – Stunden und Schichten lassen sich trotzdem jederzeit von Hand eintragen.</div>
        <div><button>Speichern</button></div>
    </form>
</div>

<h2>Feiertage <?= $year ?> (Österreich)</h2>
<div class="row filter">
    <a class="btn ghost" href="business.php?year=<?= $year - 1 ?>">‹ <?= $year - 1 ?></a>
    <a class="btn ghost" href="business.php?year=<?= $year + 1 ?>"><?= $year + 1 ?> ›</a>
</div>
<p class="muted">Gesetzliche Feiertage sind automatisch geschlossen. Soll an einem Feiertag trotzdem geöffnet sein, markiere ihn hier als „geöffnet“.</p>
<table style="max-width:720px">
    <tr><th>Datum</th><th>Feiertag</th><th>Betrieb</th><th></th></tr>
    <?php foreach ($holidays as $date => $name): $sp = $specials[$date] ?? null; $open = $sp && $sp['kind'] === 'open'; ?>
        <tr class="<?= $open ? '' : 'weekend' ?>">
            <td><?= e(WEEKDAY_SHORT[(int)date('N', strtotime($date))] . ' ' . fmt_d($date)) ?></td>
            <td><?= e($name) ?></td>
            <td><?= $open ? '<span class="tag approved">geöffnet' . ($sp['open_from'] ? ' ' . e(substr($sp['open_from'], 0, 5) . '–' . substr($sp['open_to'], 0, 5)) : '') . '</span>' : '<span class="tag">geschlossen</span>' ?></td>
            <td>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="name" value="<?= e($name) ?>">
                    <?php if ($open): ?><button class="link" name="action" value="special_delete">Wieder schließen</button>
                    <?php else: ?><button class="link" name="action" value="holiday_open">Trotzdem öffnen</button><?php endif; ?>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$holidays): ?><tr><td colspan="4" class="muted">Feiertage sind abgeschaltet (HOLIDAYS=NONE in der .env).</td></tr><?php endif; ?>
</table>

<h2>Eigene Sondertage</h2>
<p class="muted">Zusätzliche Schließtage (z. B. Betriebsurlaub, Brückentag, regionaler Feiertag) oder Sonderöffnungen (z. B. Sonntag vor Weihnachten).</p>
<form method="post" class="card row filter">
    <?= csrf_field() ?><input type="hidden" name="action" value="special_save"><input type="hidden" name="year" value="<?= $year ?>">
    <label>Datum <input type="date" name="date" required></label>
    <label>Art <select name="kind"><option value="closed">Betrieb geschlossen</option><option value="open">Betrieb geöffnet</option></select></label>
    <label>Bezeichnung <input name="name" maxlength="100" placeholder="z. B. Brückentag"></label>
    <label>Öffnung von <input type="time" name="open_from"></label>
    <label>bis <input type="time" name="open_to"></label>
    <button>Speichern</button>
</form>
<table style="max-width:720px">
    <tr><th>Datum</th><th>Art</th><th>Bezeichnung</th><th>Zeit</th><th></th></tr>
    <?php foreach ($specials as $date => $sp): ?>
        <tr>
            <td><?= e(WEEKDAY_SHORT[(int)date('N', strtotime($date))] . ' ' . fmt_d($date)) ?></td>
            <td><?= $sp['kind'] === 'closed' ? 'geschlossen' : 'geöffnet' ?></td>
            <td><?= e($sp['name']) ?></td>
            <td><?= $sp['open_from'] ? e(substr($sp['open_from'], 0, 5) . '–' . substr($sp['open_to'], 0, 5)) : '' ?></td>
            <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="special_delete"><input type="hidden" name="date" value="<?= e($date) ?>"><input type="hidden" name="year" value="<?= $year ?>"><button class="link danger">Entfernen</button></form></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$specials): ?><tr><td colspan="5" class="muted">Keine eigenen Sondertage.</td></tr><?php endif; ?>
</table>
<?php page_footer();
