<?php
declare(strict_types=1);
// Einmalige Installation. Nach Erfolg wird installed.lock angelegt – install.php danach am besten löschen.
require __DIR__ . '/lib/bootstrap.php';

if (file_exists(__DIR__ . '/installed.lock')) {
    exit('Bereits installiert. Lösche installed.lock, um erneut zu installieren (Datenbank bleibt bestehen).');
}

$schema = [
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    weekly_hours DECIMAL(5,2) NOT NULL DEFAULT 40,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(30) NOT NULL UNIQUE,
    customer VARCHAR(100) NOT NULL,
    max_devices INT NOT NULL DEFAULT 5,
    expires_at DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS license_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    machine_id CHAR(64) NOT NULL,
    machine_name VARCHAR(100) NOT NULL DEFAULT '',
    activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NULL,
    UNIQUE KEY uq_dev (license_id, machine_id),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    key_prefix VARCHAR(12) NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME NULL,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES license_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    manual_break_min INT NOT NULL DEFAULT 0,
    note VARCHAR(500) NOT NULL DEFAULT '',
    source ENUM('web','client','manual') NOT NULL DEFAULT 'web',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_start (user_id, start_time),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS breaks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    FOREIGN KEY (entry_id) REFERENCES time_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS absences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('vacation','sick','other') NOT NULL DEFAULT 'vacation',
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

const DEFAULT_ADMIN_USER = 'admin';
const DEFAULT_ADMIN_PASS = 'ChangeMe123!';
$error = '';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer = trim($_POST['customer'] ?? '') ?: 'Meine Firma';
    {
        try {
            foreach (array_merge($schema, [SHIFTS_SQL]) as $sql) {
                db()->exec($sql);
            }
            migrate();
            // Standard-Login; das Passwort muss beim ersten Login geändert werden
            q('INSERT INTO users (username, password_hash, full_name, role, must_change_password) VALUES (?, ?, ?, ?, 1)',
                [DEFAULT_ADMIN_USER, password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT), 'Administrator', 'admin']);
            $licKey = random_license_key();
            q('INSERT INTO licenses (license_key, customer, max_devices) VALUES (?, ?, 10)', [$licKey, $customer]);
            $licId = (int)db()->lastInsertId();
            $apiKey = random_key('zk_');
            q('INSERT INTO api_keys (license_id, key_hash, key_prefix, label) VALUES (?, ?, ?, ?)',
                [$licId, hash('sha256', $apiKey), substr($apiKey, 0, 10), 'Standard']);
            file_put_contents(__DIR__ . '/installed.lock', date('c'));
            $result = ['license' => $licKey, 'api' => $apiKey];
        } catch (Throwable $ex) {
            $error = 'Fehler: ' . $ex->getMessage();
        }
    }
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><title>Installation</title>
<link rel="stylesheet" href="assets/style.css"></head><body><main style="max-width:480px">
<h1>Installation</h1>
<?php if ($result): ?>
    <div class="flash ok">Installation abgeschlossen. Diese Werte werden nur jetzt angezeigt – bitte notieren!</div>
    <p><b>Lizenzschlüssel:</b><br><code><?= e($result['license']) ?></code></p>
    <p><b>API-Key:</b><br><code><?= e($result['api']) ?></code></p>
    <p><b>Standard-Login:</b><br>Benutzername <code><?= e(DEFAULT_ADMIN_USER) ?></code> · Passwort <code><?= e(DEFAULT_ADMIN_PASS) ?></code><br>
        <span class="muted">Das Passwort muss beim ersten Login geändert werden. Melde dich am besten sofort an.</span></p>
    <p>Lösche jetzt <code>install.php</code> und <a href="index.php">melde dich an</a>.</p>
<?php else: ?>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <p>Zuerst DB-Zugang in <code>.env</code> eintragen (Datenbank muss existieren).</p>
    <form method="post" class="stack">
        <label>Firmenname (Lizenznehmer)<input name="customer"></label>
        <p class="muted">Es wird ein Administrator mit Standard-Login angelegt (Passwortwechsel beim ersten Login erforderlich).</p>
        <button>Installieren</button>
    </form>
<?php endif; ?>
</main></body></html>
