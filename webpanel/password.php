<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';
try {
    ensure_migrated(); // Spalte must_change_password bei älteren Installationen nachziehen
} catch (Throwable $ex) {
    error_log('Migration: ' . $ex->getMessage());
}
$me = require_login();
$forced = !empty($me['must_change_password']);

if (is_post()) {
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($old, $me['password_hash'])) {
        usleep(500000);
        flash('Das aktuelle Passwort ist falsch.', 'err');
    } elseif (strlen($new) < 8) {
        flash('Das neue Passwort braucht mindestens 8 Zeichen.', 'err');
    } elseif ($new !== (string)($_POST['new2'] ?? '')) {
        flash('Die neuen Passwörter stimmen nicht überein.', 'err');
    } elseif ($new === $old) {
        flash('Das neue Passwort muss sich vom alten unterscheiden.', 'err');
    } else {
        q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        q('DELETE FROM api_tokens WHERE user_id = ?', [$me['id']]); // Desktop-Anmeldungen ungültig machen
        session_regenerate_id(true);
        flash('Passwort geändert.');
        redirect('dashboard.php');
    }
    redirect('password.php');
}

page_header('Passwort ändern');
?>
<?php if ($forced): ?><div class="flash err">Du verwendest ein Standard-Passwort. Bitte lege jetzt ein eigenes Passwort fest.</div><?php endif; ?>
<form method="post" class="stack" style="max-width:380px">
    <?= csrf_field() ?>
    <label>Aktuelles Passwort<input type="password" name="old" required autofocus></label>
    <label>Neues Passwort (min. 8 Zeichen)<input type="password" name="new" required minlength="8"></label>
    <label>Neues Passwort wiederholen<input type="password" name="new2" required minlength="8"></label>
    <button>Passwort ändern</button>
</form>
<?php page_footer();
