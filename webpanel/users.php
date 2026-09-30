<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
$me = require_login(true);

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['full_name'] ?? ''));
    $hours = max(0, min(80, (float)str_replace(',', '.', (string)($_POST['weekly_hours'] ?? '40'))));
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'employee';
    $pass = (string)($_POST['password'] ?? '');

    try {
        if ($action === 'create') {
            $uname = trim((string)($_POST['username'] ?? ''));
            if ($uname === '' || $name === '' || strlen($pass) < 8) {
                throw new DomainException('Benutzername, Name und Passwort (min. 8 Zeichen) sind Pflicht.');
            }
            q('INSERT INTO users (username, password_hash, full_name, role, weekly_hours) VALUES (?,?,?,?,?)',
                [$uname, password_hash($pass, PASSWORD_DEFAULT), $name, $role, $hours]);
            flash('Mitarbeiter angelegt.');
        } elseif ($action === 'update') {
            if ($name === '') {
                throw new DomainException('Name fehlt.');
            }
            $active = isset($_POST['active']) ? 1 : 0;
            if ($id === (int)$me['id'] && (!$active || $role !== 'admin')) {
                throw new DomainException('Du kannst dich nicht selbst deaktivieren oder herabstufen.');
            }
            q('UPDATE users SET full_name=?, role=?, weekly_hours=?, active=? WHERE id=?', [$name, $role, $hours, $active, $id]);
            if ($pass !== '') {
                if (strlen($pass) < 8) {
                    throw new DomainException('Passwort min. 8 Zeichen.');
                }
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                q('DELETE FROM api_tokens WHERE user_id = ?', [$id]);
            }
            if (!$active) {
                q('DELETE FROM api_tokens WHERE user_id = ?', [$id]);
            }
            flash('Gespeichert.');
        }
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'Benutzername existiert bereits.' : 'Datenbankfehler.', 'err');
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('users.php');
}

$edit = isset($_GET['edit']) ? q_one('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']]) : null;
$users = q_all('SELECT * FROM users ORDER BY active DESC, full_name');
page_header('Mitarbeiter', 'users');
?>
<div class="card" style="max-width:640px">
    <h2><?= $edit ? 'Bearbeiten: ' . e($edit['username']) : 'Neuer Mitarbeiter' ?></h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <?php if (!$edit): ?><label>Benutzername<input name="username" required></label><?php endif; ?>
        <label>Name<input name="full_name" required value="<?= e($edit['full_name'] ?? '') ?>"></label>
        <label>Wochenstunden<input name="weekly_hours" value="<?= e($edit['weekly_hours'] ?? '40') ?>"></label>
        <label>Rolle<select name="role">
            <option value="employee">Mitarbeiter</option>
            <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option></select></label>
        <label><?= $edit ? 'Neues Passwort (leer = unverändert)' : 'Passwort (min. 8 Zeichen)' ?><input type="password" name="password" <?= $edit ? '' : 'required' ?>></label>
        <?php if ($edit): ?><label class="check"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> Aktiv</label><?php endif; ?>
        <div class="row"><button><?= $edit ? 'Speichern' : 'Anlegen' ?></button><?php if ($edit): ?><a class="btn" href="users.php">Abbrechen</a><?php endif; ?></div>
    </form>
</div>

<table>
    <tr><th>Benutzer</th><th>Name</th><th>Rolle</th><th>Std./Woche</th><th>Status</th><th></th></tr>
    <?php foreach ($users as $u): ?>
        <tr class="<?= $u['active'] ? '' : 'weekend' ?>">
            <td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td>
            <td><?= $u['role'] === 'admin' ? 'Administrator' : 'Mitarbeiter' ?></td>
            <td><?= e($u['weekly_hours']) ?></td><td><?= $u['active'] ? 'Aktiv' : 'Deaktiviert' ?></td>
            <td><a href="users.php?edit=<?= (int)$u['id'] ?>">Bearbeiten</a></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php page_footer();
