<?php
declare(strict_types=1);

/**
 * Betrieb: Öffnungszeiten, Sondertage (Feiertage überschreiben, Schließtage) und Monatsabschluss.
 * Schreibende Funktionen prüfen ihr Recht selbst (siehe rbac.php).
 */

// ------------------------------------------------------------ Öffnungszeiten & Sondertage (Recht: business.manage)

/** in: open[1..7] (gesetzt = geöffnet), from[1..7], to[1..7] (HH:MM) */
function business_save_hours(array $actor, array $in): void
{
    require_perm($actor, 'business.manage');
    $out = [];
    for ($d = 1; $d <= 7; $d++) {
        if (empty($in['open'][$d])) {
            $out[(string)$d] = null;
            continue;
        }
        $from = (string)($in['from'][$d] ?? '');
        $to = (string)($in['to'][$d] ?? '');
        if (!valid_hhmm($from) || !valid_hhmm($to) || $to <= $from) {
            throw new DomainException('Bitte für ' . WEEKDAY_SHORT[$d] . ' gültige Öffnungszeiten angeben (Beginn vor Ende, HH:MM).');
        }
        $out[(string)$d] = [$from, $to];
    }
    setting_set('opening_hours', json_encode($out));
    opening_hours(true);
}

/** in: date, kind (closed|open), name, open_from, open_to (nur bei „open“, leer = reguläre Zeiten) */
function special_day_save(array $actor, array $in): void
{
    require_perm($actor, 'business.manage');
    $date = (string)($in['date'] ?? '');
    $kind = (string)($in['kind'] ?? '');
    if (!valid_date($date) || !in_array($kind, ['closed', 'open'], true)) {
        throw new DomainException('Bitte ein gültiges Datum und die Art (geschlossen/geöffnet) angeben.');
    }
    $from = trim((string)($in['open_from'] ?? ''));
    $to = trim((string)($in['open_to'] ?? ''));
    if ($kind === 'open' && ($from !== '' || $to !== '') && (!valid_hhmm($from) || !valid_hhmm($to) || $to <= $from)) {
        throw new DomainException('Öffnungszeit: Beginn und Ende im Format HH:MM, Beginn vor Ende.');
    }
    q('INSERT INTO special_days (special_date, kind, name, open_from, open_to) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE kind = VALUES(kind), name = VALUES(name), open_from = VALUES(open_from), open_to = VALUES(open_to)',
        [$date, $kind, mb_substr(trim((string)($in['name'] ?? '')), 0, 100), $kind === 'open' && $from !== '' ? $from : null, $kind === 'open' && $to !== '' ? $to : null]);
    special_days_map(true);
}

function special_day_delete(array $actor, string $date): void
{
    require_perm($actor, 'business.manage');
    q('DELETE FROM special_days WHERE special_date = ?', [$date]);
    special_days_map(true);
}

// ------------------------------------------------------------ Monatsabschluss

function month_closing(int $uid, string $month): ?array
{
    return q_one('SELECT c.*, b.full_name AS closed_by_name FROM month_closings c LEFT JOIN users b ON b.id = c.closed_by WHERE c.user_id = ? AND c.month = ?', [$uid, $month]);
}

/** Wirft eine Fehlermeldung, wenn der Monat des Datums für den Mitarbeiter abgeschlossen ist. */
function assert_month_open(int $uid, string $date): void
{
    $m = substr($date, 0, 7);
    if (month_closing($uid, $m)) {
        throw new DomainException("Der Monat $m ist abgeschlossen – Änderungen sind erst nach dem Wiedereröffnen durch die Verwaltung möglich.");
    }
}

function month_can_close(array $actor, int $uid): bool
{
    return user_can($actor, 'months.close') || ($uid === (int)$actor['id'] && user_can($actor, 'months.close_own'));
}

/** Monat abschließen: speichert die Summen und sperrt Änderungen an den Stunden dieses Monats. */
function month_close(array $actor, int $uid, string $month): void
{
    if (!month_can_close($actor, $uid)) {
        throw new PermissionException('Dafür fehlt dir die Berechtigung.');
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        throw new DomainException('Ungültiger Monat.');
    }
    $last = date('Y-m-t', strtotime($month . '-01'));
    if ($last > date('Y-m-d')) {
        throw new DomainException('Ein Monat kann erst an seinem letzten Tag oder danach abgeschlossen werden.');
    }
    $u = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u) {
        throw new DomainException('Mitarbeiter nicht gefunden.');
    }
    if (month_closing($uid, $month)) {
        throw new DomainException('Der Monat ist bereits abgeschlossen.');
    }
    $rep = report_data($u, $month);
    q('INSERT INTO month_closings (user_id, month, worked_sec, target_sec, overtime_sec, closed_by) VALUES (?,?,?,?,?,?)',
        [$uid, $month, $rep['worked'], $rep['target'], overtime_balance($u, $last)['seconds'], $actor['id']]);
}

function month_reopen(array $actor, int $uid, string $month): void
{
    require_perm($actor, 'months.close');
    q('DELETE FROM month_closings WHERE user_id = ? AND month = ?', [$uid, $month]);
}

/** Monatsübersicht: je Mitarbeiter die automatisch summierten Stunden und der Abschlussstatus (ohne „Alle ansehen“ nur die eigene Zeile). */
function months_overview(string $month, array $viewer): array
{
    $all = user_can($viewer, 'hours.view_all') || user_can($viewer, 'reports.view_all') || user_can($viewer, 'months.close');
    $last = date('Y-m-t', strtotime($month . '-01'));
    $rows = [];
    foreach (q_all('SELECT * FROM users WHERE active = 1 AND time_tracking = 1 ORDER BY full_name') as $u) {
        if (!$all && (int)$u['id'] !== (int)$viewer['id']) {
            continue;
        }
        $rep = report_data($u, $month);
        $c = month_closing((int)$u['id'], $month);
        $rows[] = [
            'user_id' => (int)$u['id'], 'name' => $u['full_name'], 'personnel_number' => $u['personnel_number'] ?? '',
            'worked' => $rep['worked'], 'target' => $rep['target'], 'balance' => $rep['balance'],
            'overtime_end' => overtime_balance($u, $last)['seconds'],
            'closed' => $c !== null, 'closed_at' => $c['closed_at'] ?? null, 'closed_by' => $c['closed_by_name'] ?? null,
            'can_close' => month_can_close($viewer, (int)$u['id']) && $last <= date('Y-m-d'),
        ];
    }
    return $rows;
}

/** Betriebsübersicht für ein Jahr: Öffnungszeiten, Feiertage mit Status, eigene Sondertage (Webpanel-Seite „Betrieb“ und API). */
function business_overview(int $year): array
{
    $specials = special_days_map();
    $hol = holidays_for_year($year);
    ksort($hol);
    $holidays = [];
    foreach ($hol as $date => $name) {
        $sp = $specials[$date] ?? null;
        $open = $sp && $sp['kind'] === 'open';
        $holidays[] = ['date' => $date, 'name' => $name, 'weekday' => WEEKDAY_SHORT[(int)date('N', strtotime($date))], 'open' => $open,
            'open_from' => $open && $sp['open_from'] ? substr($sp['open_from'], 0, 5) : null, 'open_to' => $open && $sp['open_to'] ? substr($sp['open_to'], 0, 5) : null];
    }
    ksort($specials);
    return [
        'year' => $year,
        'opening_hours' => opening_hours(),
        'holidays' => $holidays,
        'special_days' => array_values(array_map(fn($d, $s) => ['date' => $d, 'kind' => $s['kind'], 'name' => $s['name'],
            'weekday' => WEEKDAY_SHORT[(int)date('N', strtotime($d))],
            'open_from' => $s['open_from'] ? substr($s['open_from'], 0, 5) : null, 'open_to' => $s['open_to'] ? substr($s['open_to'], 0, 5) : null],
            array_keys($specials), $specials)),
    ];
}
