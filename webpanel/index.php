<?php
declare(strict_types=1);
require __DIR__ . '/lib/web.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    $u = q_one('SELECT * FROM users WHERE username = ? AND active = 1', [trim($_POST['username'] ?? '')]);
    if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        redirect('dashboard.php');
    }
    usleep(700000);
    $error = 'Benutzername oder Passwort falsch.';
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login – <?= e(cfg('app_name')) ?></title><link rel="stylesheet" href="assets/style.css"></head><body>
<main style="max-width:380px;margin-top:80px">
<h1><?= e(cfg('app_name')) ?></h1>
<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Benutzername<input name="username" autofocus required></label>
    <label>Passwort<input type="password" name="password" required></label>
    <button>Anmelden</button>
</form>
</main></body></html>
