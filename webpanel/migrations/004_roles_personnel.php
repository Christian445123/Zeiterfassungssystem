<?php
// Rollen & Rechte, Personalnummer
return [
    "CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL DEFAULT '',
        permissions TEXT NOT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Systemrollen: Vollzugriff ('*' = alle Rechte, auch künftige) und Mitarbeiter (nur eigene Stunden + Dienstplan)
    "INSERT IGNORE INTO roles (name, description, permissions, is_system) VALUES
        ('Vollzugriff', 'Voller Zugriff auf alle Funktionen', '*', 1),
        ('Mitarbeiter', 'Eigene Stunden eintragen und ansehen, Dienstplan ansehen, Urlaub beantragen', 'hours.own,schedule.view,absences.request', 1)",
    fn() => db_add_column('users', 'role_id', 'INT NULL'),
    fn() => db_add_column('users', 'personnel_number', 'VARCHAR(20) NULL'),
    "UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'Vollzugriff') WHERE role_id IS NULL AND role = 'admin'",
    "UPDATE users SET role_id = (SELECT id FROM roles WHERE name = 'Mitarbeiter') WHERE role_id IS NULL",
    // bestehende Benutzer bekommen fortlaufende Personalnummern (1000 + ID)
    "UPDATE users SET personnel_number = CAST(1000 + id AS CHAR) WHERE personnel_number IS NULL",
    function (): void {
        if (!db_has_index('users', 'uq_personnel')) {
            db()->exec('ALTER TABLE users ADD UNIQUE KEY uq_personnel (personnel_number)');
        }
    },
];
