<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    ensure_migrated(); // nach einem Update zuerst Datenbank anpassen (z. B. Spalte Personalnummer)
    $login = trim((string)($_POST['login'] ?? ''));
    $u = q_one('SELECT * FROM users WHERE (personnel_number = ? OR username = ?) AND active = 1', [$login, $login]);
    if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        redirect('dashboard.php');
    }
    usleep(700000);
    $error = 'Personalnummer oder Passwort falsch.';
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login – <?= e(cfg('app_name')) ?></title><link rel="stylesheet" href="<?= e(css_url()) ?>"></head><body class="login-page">
<main class="login-card">
<div class="login-logo">⏱</div>
<h1><?= e(cfg('app_name')) ?></h1>
<p class="muted">Bitte mit deiner Personalnummer anmelden.</p>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Personalnummer<input name="login" autofocus required autocomplete="username" inputmode="text"></label>
    <label>Passwort<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="big">Anmelden</button>
</form>
</main></body></html>
