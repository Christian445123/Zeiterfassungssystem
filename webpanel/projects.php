<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
require_login(true);

if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' && trim((string)($_POST['name'] ?? '')) !== '') {
        q('INSERT INTO projects (name) VALUES (?)', [mb_substr(trim($_POST['name']), 0, 100)]);
        flash('Projekt angelegt.');
    } elseif ($action === 'toggle') {
        q('UPDATE projects SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
    }
    redirect('projects.php');
}

$rows = q_all('SELECT p.*, (SELECT COUNT(*) FROM time_entries e WHERE e.project_id = p.id) AS uses FROM projects p ORDER BY active DESC, name');
page_header('Projekte', 'projects');
?>
<form method="post" class="row filter">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Neues Projekt <input name="name" maxlength="100" required></label><button>Anlegen</button>
</form>
<table style="max-width:600px">
    <tr><th>Name</th><th>Einträge</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $p): ?>
        <tr><td><?= e($p['name']) ?></td><td><?= (int)$p['uses'] ?></td><td><?= $p['active'] ? 'Aktiv' : 'Inaktiv' ?></td>
            <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="link"><?= $p['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Noch keine Projekte.</td></tr><?php endif; ?>
</table>
<?php page_footer();
