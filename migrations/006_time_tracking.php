<?php
// Verwaltungskonten ohne Zeiterfassung (z. B. der Administrator): keine Stunden, kein Soll, nicht im Dienstplan
return [
    fn() => db_add_column('users', 'time_tracking', 'TINYINT(1) NOT NULL DEFAULT 1'),
    // Der Standard-Administrator dient nur der Verwaltung (Einteilen, Rechte, Einstellungen)
    "UPDATE users SET time_tracking = 0 WHERE username = 'admin'",
];
