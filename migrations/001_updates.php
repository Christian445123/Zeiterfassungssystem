<?php
// Migrationen müssen idempotent sein (CREATE ... IF NOT EXISTS usw.).
return [
    "CREATE TABLE IF NOT EXISTS settings (
        skey VARCHAR(64) PRIMARY KEY,
        sval TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS client_releases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(20) NOT NULL UNIQUE,
        file_name VARCHAR(120) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        size_bytes BIGINT NOT NULL,
        notes TEXT NOT NULL,
        mandatory TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
