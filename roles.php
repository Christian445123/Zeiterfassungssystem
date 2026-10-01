<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login('roles.manage');

if (is_post()) {
    try {
        switch ($_POST['action'] ?? '') {
            case 'save':
                role_save($me, ['id' => $_POST['id'] ?? 0, 'name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '',
                    'permissions' => (array)($_POST['permissions'] ?? [])]);
                flash('Rolle gespeichert.');
                break;
            case 'delete':
                role_delete($me, (int)$_POST['id']);
                flash('Rolle gelöscht.');
                break;
        }
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('roles.php');
}

$roles = roles_overview();
$edit = null;
foreach ($roles as $r) {
    if (isset($_GET['edit']) && $r['id'] === (int)$_GET['edit']) {
        $edit = $r;
    }
}
$groups = [
    'Eigene Daten' => ['hours.own', 'schedule.view', 'absences.request', 'months.close_own'],
    'Alle Mitarbeiter' => ['hours.view_all', 'hours.edit_all', 'reports.view_all', 'absences.manage', 'overtime.manage', 'months.close', 'schedule.edit'],
    'Verwaltung' => ['users.view', 'users.manage', 'roles.manage', 'business.manage', 'updates.manage'],
];

page_header('Rollen & Rechte', 'roles');
?>
<div class="card" style="max-width:820px">
    <h2 style="margin-top:0"><?= $edit ? 'Rolle bearbeiten: ' . e($edit['name']) : 'Neue Rolle' ?></h2>
    <?php if ($edit && $edit['full']): ?>
        <p>Die Rolle <b>Vollzugriff</b> hat immer alle Rechte (auch künftige) und ist fest. Sie kann nicht geändert oder gelöscht werden.</p>
    <?php else: ?>
    <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="row" style="justify-content:flex-start">
            <label>Name<input name="name" required maxlength="60" value="<?= e($edit['name'] ?? '') ?>"></label>
            <label>Beschreibung<input name="description" maxlength="255" value="<?= e($edit['description'] ?? '') ?>" style="width:360px"></label>
        </div>
        <?php foreach ($groups as $title => $keys): ?>
            <fieldset class="fs"><legend><?= e($title) ?></legend>
                <?php foreach ($keys as $k): ?>
                    <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($k) ?>" <?= $edit && in_array($k, $edit['permissions'], true) ? 'checked' : (!$edit && in_array($k, ['hours.own', 'schedule.view', 'absences.request', 'months.close_own'], true) ? 'checked' : '') ?>>
                        <?= e(PERMISSIONS[$k]) ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        <div class="row" style="justify-content:flex-start"><button><?= $edit ? 'Speichern' : 'Rolle anlegen' ?></button><?php if ($edit): ?><a class="btn ghost" href="roles.php">Abbrechen</a><?php endif; ?></div>
    </form>
    <?php endif; ?>
</div>

<table>
    <tr><th>Rolle</th><th>Beschreibung</th><th>Rechte</th><th>Mitarbeiter</th><th></th></tr>
    <?php foreach ($roles as $r): ?>
        <tr>
            <td><b><?= e($r['name']) ?></b><?= $r['system'] ? ' <span class="tag">System</span>' : '' ?></td>
            <td><?= e($r['description']) ?></td>
            <td><?= $r['full'] ? '<b>Alle Rechte</b>' : e(implode(' · ', array_map(fn($k) => PERMISSIONS[$k], $r['permissions'])) ?: '–') ?></td>
            <td><?= (int)$r['user_count'] ?></td>
            <td class="nowrap">
                <?php if (!$r['full']): ?><a href="roles.php?edit=<?= (int)$r['id'] ?>">Bearbeiten</a><?php endif; ?>
                <?php if (!$r['system']): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Rolle löschen?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link danger">Löschen</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php page_footer();
