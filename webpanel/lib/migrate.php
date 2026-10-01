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
    env_sync_example(); // neue .env-Schlüssel aus .env.example übernehmen
    return migrate();
}

function db_has_column(string $table, string $column): bool
{
    $r = q_one('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    return (int)$r['c'] > 0;
}

/** Spalte nur anlegen, wenn sie fehlt (MySQL kennt kein ADD COLUMN IF NOT EXISTS). */
function db_add_column(string $table, string $column, string $definition): void
{
    if (!db_has_column($table, $column)) {
        db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function db_has_index(string $table, string $index): bool
{
    $r = q_one('SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]);
    return (int)$r['c'] > 0;
}

/**
 * Hängt neue Schlüssel aus .env.example an die .env an (samt Kommentar davor) – bestehende Werte bleiben unangetastet.
 * Schlüssel zählen als vorhanden, wenn sie in der .env aktiv (KEY=…) oder auskommentiert (#KEY=…) stehen.
 * Läuft nur, wenn .env.example neuer ist als .env; ohne Schreibrecht passiert nichts. Rückgabe: angehängte Schlüssel.
 */
function env_sync_example(): array
{
    $example = __DIR__ . '/../.env.example';
    $env = __DIR__ . '/../.env';
    if (!is_file($example) || !is_file($env) || !is_writable($env) || filemtime($example) < filemtime($env)) {
        return [];
    }
    $have = [];
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m)) {
            $have[$m[1]] = true;
        }
    }
    $add = [];
    $keys = [];
    $comments = [];
    foreach (file($example, FILE_IGNORE_NEW_LINES) as $line) {
        $line = rtrim($line, "\r");
        if (trim($line) === '') {
            $comments = [];
        } elseif (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m)) {
            if (!isset($have[$m[1]])) {
                $add[] = implode("\n", array_merge($comments, [$line]));
                $keys[] = $m[1];
                $have[$m[1]] = true;
            }
            $comments = [];
        } else {
            $comments[] = $line;
        }
    }
    if ($add) {
        $current = (string)file_get_contents($env);
        file_put_contents($env, rtrim($current) . "\n\n# --- automatisch aus .env.example ergänzt (" . date('d.m.Y') . ") ---\n" . implode("\n", $add) . "\n");
    } else {
        touch($env); // nichts zu ergänzen: Zeitstempel angleichen, damit nicht bei jedem Aufruf neu geprüft wird
    }
    return $keys;
}
