<?php
// Betriebszeiten/Sondertage und Monatsabschluss
return [
    "CREATE TABLE IF NOT EXISTS special_days (
        special_date DATE PRIMARY KEY,
        kind ENUM('closed','open') NOT NULL,
        name VARCHAR(100) NOT NULL DEFAULT '',
        open_from TIME NULL,
        open_to TIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS month_closings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        month CHAR(7) NOT NULL,
        worked_sec INT NOT NULL,
        target_sec INT NOT NULL,
        overtime_sec INT NOT NULL,
        closed_by INT NULL,
        closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_month (user_id, month),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
