<?php
declare(strict_types=1);

/** Führt alle noch nicht angewendeten Dateien aus migrations/*.php aus. Gibt die Namen der ausgeführten zurück. */
function migrate(): array
{
    db()->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $applied = array_column(q_all('SELECT name FROM schema_migrations'), 'name');
    $files = glob(__DIR__ . '/../migrations/*.php') ?: [];
    sort($files);
    $ran = [];
    foreach ($files as $file) {
        $name = basename($file, '.php');
        if (in_array($name, $applied, true)) {
            continue;
        }
        foreach ((require $file) as $step) {
            is_callable($step) ? $step() : db()->exec($step);
        }
        q('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$name]);
        $ran[] = $name;
    }
    return $ran;
}

/** Einmal pro Request migrieren (für Einstiegspunkte, die die Update-Tabellen brauchen). */
function ensure_migrated(): array
{
    static $done = false;
    if ($done) {
        return [];
    }
    $done = true;
    return migrate();
}
