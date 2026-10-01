<?php
declare(strict_types=1);

/**
 * Zeiteinträge (manuell erfasst) und Dienstplan-Grundfunktionen.
 * Es gibt kein Ein-/Ausstempeln: Mitarbeiter tragen Beginn, Ende und Pause von Hand ein.
 */

const ENTRY_SQL = "SELECT e.*, u.full_name,
    GREATEST(0, TIMESTAMPDIFF(SECOND, e.start_time, COALESCE(e.end_time, NOW())) - e.manual_break_min * 60) AS worked_sec,
    e.manual_break_min * 60 AS break_sec
    FROM time_entries e
    JOIN users u ON u.id = e.user_id";

function entry_by_id(int $id): ?array
{
    return q_one(ENTRY_SQL . ' WHERE e.id = ?', [$id]);
}

/** Einträge im Zeitraum (nach Beginn absteigend). $uid = null: alle Mitarbeiter. */
function entries_between(?int $uid, string $from, string $to): array
{
    $sql = ENTRY_SQL . ' WHERE e.start_time >= ? AND e.start_time < DATE_ADD(?, INTERVAL 1 DAY)';
    $params = [$from, $to];
    if ($uid !== null) {
        $sql .= ' AND e.user_id = ?';
        $params[] = $uid;
    }
    return q_all($sql . ' ORDER BY e.start_time DESC', $params);
}

function worked_between(int $uid, string $from, string $to): int
{
    $sum = 0;
    foreach (entries_between($uid, $from, $to) as $e) {
        $sum += (int)$e['worked_sec'];
    }
    return $sum;
}

function week_bounds(?string $ref = null): array
{
    $t = strtotime($ref ?? 'today');
    $monday = strtotime('monday this week', $t);
    return [date('Y-m-d', $monday), date('Y-m-d', strtotime('+6 days', $monday))];
}

/** Dürfen $actor diesen Eintrag ändern/löschen? Eigene Einträge nur innerhalb von ENTRY_EDIT_DAYS (Standard 31), „Alle Stunden ändern“ immer. */
function entry_editable(array $actor, array $entry): bool
{
    if (month_closing((int)$entry['user_id'], substr($entry['start_time'], 0, 7))) {
        return false; // Monat abgeschlossen
    }
    if (user_can($actor, 'hours.edit_all')) {
        return true;
    }
    if ((int)$entry['user_id'] !== (int)$actor['id'] || !user_can($actor, 'hours.own')) {
        return false;
    }
    $days = (int)cfg('entry_edit_days');
    return $days <= 0 || substr($entry['start_time'], 0, 10) >= date('Y-m-d', strtotime("-$days days"));
}

/** Kennzahlen für die Startseite (nur eigene Daten). */
function dashboard_data(array $u): array
{
    $uid = (int)$u['id'];
    $today = date('Y-m-d');
    $week = week_summary($u);
    $shifts = [];
    if (user_can($u, 'schedule.view')) {
        foreach (q_all('SELECT s.* FROM shifts s
                        WHERE s.user_id = ? AND s.shift_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                        ORDER BY s.shift_date, s.start_time', [$uid]) as $s) {
            $shifts[] = ['id' => (int)$s['id'], 'date' => $s['shift_date'], 'start' => substr($s['start_time'], 0, 5),
                'end' => substr($s['end_time'], 0, 5), 'minutes' => shift_minutes($s['start_time'], $s['end_time'], (int)$s['break_min']),
                'note' => $s['note']];
        }
    }
    $own = user_can($u, 'hours.own') && user_tracks($u);
    $month = null;
    if ($own) {
        $m = date('Y-m');
        $rep = report_data($u, $m);
        $month = ['month' => $m, 'worked' => $rep['worked'], 'target' => $rep['target'], 'closed' => month_closing($uid, $m) !== null];
    }
    return [
        'today_seconds' => $own ? worked_between($uid, $today, $today) : 0,
        'week' => $week,
        'month' => $month,
        'overtime_seconds' => $own ? overtime_balance($u)['seconds'] : 0,
        'vacation' => (user_can($u, 'absences.request') || user_can($u, 'absences.manage')) ? vacation_summary($u, (int)date('Y')) : null,
        'shifts' => $shifts,
    ];
}

function time_to_min(string $t): int
{
    $p = explode(':', $t);
    return (int)$p[0] * 60 + (int)($p[1] ?? 0);
}

/** Geplante Netto-Minuten einer Schicht; Ende <= Beginn = über Mitternacht. */
function shift_minutes(string $start, string $end, int $break): int
{
    $s = time_to_min($start);
    $e = time_to_min($end);
    if ($e <= $s) {
        $e += 1440;
    }
    return max(0, $e - $s - $break);
}
