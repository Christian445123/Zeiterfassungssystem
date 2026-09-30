<?php
return [
    // MySQL kennt kein "ADD COLUMN IF NOT EXISTS" -> vorher prüfen (idempotent)
    function (): void {
        $has = q_one("SELECT COUNT(*) c FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'");
        if (!(int)$has['c']) {
            db()->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        }
    },
];
