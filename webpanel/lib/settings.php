<?php
declare(strict_types=1);

function setting_get(string $key, string $default = ''): string
{
    $r = q_one('SELECT sval FROM settings WHERE skey = ?', [$key]);
    return $r ? (string)$r['sval'] : $default;
}

function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)', [$key, $value]);
}
