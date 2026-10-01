<?php
// Arbeitszeitmodell, Urlaub, Überstunden, neue Abwesenheitsarten
return [
    fn() => db_add_column('users', 'work_days', "VARCHAR(13) NOT NULL DEFAULT '1,2,3,4,5'"),
    fn() => db_add_column('users', 'day_hours', 'VARCHAR(60) NULL'),
    fn() => db_add_column('users', 'vacation_days_override', 'DECIMAL(5,1) NULL'),
    fn() => db_add_column('users', 'vacation_carryover', 'DECIMAL(5,1) NOT NULL DEFAULT 0'),
    fn() => db_add_column('users', 'vacation_carryover_year', 'INT NULL'),
    fn() => db_add_column('users', 'start_date', 'DATE NULL'),
    fn() => db_add_column('absences', 'hours', 'DECIMAL(4,2) NULL'),
    "ALTER TABLE absences MODIFY type ENUM('vacation','sick','doctor','comp','other') NOT NULL DEFAULT 'vacation'",
    "CREATE TABLE IF NOT EXISTS overtime_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        adj_date DATE NOT NULL,
        minutes INT NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_date (user_id, adj_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
