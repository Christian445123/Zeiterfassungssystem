<?php
declare(strict_types=1);
// Einmalige Installation: legt alle Tabellen an (migrations/) und den Standard-Administrator.
// Danach sperrt sich diese Datei selbst (installed.lock) – nicht löschen, wenn das Panel per Git aktualisiert wird.
require __DIR__ . '/lib/bootstrap.php';

if (file_exists(__DIR__ . '/installed.lock')) {
    exit('Bereits installiert. Zum erneuten Installieren installed.lock löschen (die Datenbank bleibt bestehen).');
}

const DEFAULT_ADMIN_USER = 'admin';
const DEFAULT_ADMIN_NUMBER = '1000';
const DEFAULT_ADMIN_PASS = 'ChangeMe123!';

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        migrate();
        if (q_one('SELECT id FROM users LIMIT 1')) {
            throw new RuntimeException('Es existieren bereits Benutzer – die Datenbank ist schon eingerichtet.');
        }
        $fullRole = (int)q_one("SELECT id FROM roles WHERE name = 'Vollzugriff'")['id'];
        // Standard-Login; das Passwort muss beim ersten Login geändert werden
        q('INSERT INTO users (username, personnel_number, password_hash, full_name, role, role_id, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [DEFAULT_ADMIN_USER, DEFAULT_ADMIN_NUMBER, password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT), 'Administrator', 'admin', $fullRole]);
        file_put_contents(__DIR__ . '/installed.lock', date('c'));
        $done = true;
    } catch (Throwable $ex) {
        $error = 'Fehler: ' . $ex->getMessage();
    }
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation</title>
<link rel="stylesheet" href="<?= e(css_url()) ?>"></head><body class="login-page"><main class="login-card">
<div class="login-logo">⏱</div>
<h1>Installation</h1>
<?php if ($done): ?>
    <div class="flash ok">Installation abgeschlossen.</div>
    <p><b>Standard-Login:</b><br>Personalnummer <code><?= e(DEFAULT_ADMIN_NUMBER) ?></code> · Passwort <code><?= e(DEFAULT_ADMIN_PASS) ?></code><br>
        <span class="muted">Das Passwort muss beim ersten Login geändert werden. Melde dich am besten sofort an.</span></p>
    <p>Nach der Installation sperrt sich <code>install.php</code> selbst (<code>installed.lock</code>). <a href="index.php">Jetzt anmelden</a>.</p>
<?php else: ?>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <p class="muted">Zuerst die Datenbank-Zugangsdaten in der <code>.env</code> eintragen (die Datenbank muss existieren). Es wird ein Administrator mit Standard-Login angelegt.</p>
    <form method="post" class="stack"><button class="big">Installieren</button></form>
<?php endif; ?>
</main></body></html>
