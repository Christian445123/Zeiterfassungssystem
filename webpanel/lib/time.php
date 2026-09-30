<?php
declare(strict_types=1);

/**
 * Zeiterfassungs-Logik – wird von Webpanel UND API gemeinsam genutzt.
 * Fehler die dem Benutzer angezeigt werden dürfen: DomainException.
 */

const ENTRY_SQL = "SELECT e.*, u.full_name, p.name AS project_name,
    GREATEST(0,
        TIMESTAMPDIFF(SECOND, e.start_time, COALESCE(e.end_time, NOW()))
        - COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND, b.start_time, COALESCE(b.end_time, NOW())))
                    FROM breaks b WHERE b.entry_id = e.id), 0)
        - e.manual_break_min * 60
    ) AS worked_sec,
    (COALESCE((SELECT SUM(TIMESTAMPDIFF(SECOND, b.start_time, COALESCE(b.end_time, NOW())))
               FROM breaks b WHERE b.entry_id = e.id), 0) + e.manual_break_min * 60) AS break_sec
    FROM time_entries e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN projects p ON p.id = e.project_id";

function open_entry(int $uid): ?array
{
    return q_one('SELECT * FROM time_entries WHERE user_id = ? AND end_time IS NULL ORDER BY id DESC LIMIT 1', [$uid]);
}

function open_break(int $entryId): ?array
{
    return q_one('SELECT * FROM breaks WHERE entry_id = ? AND end_time IS NULL ORDER BY id DESC LIMIT 1', [$entryId]);
}

function entry_by_id(int $id): ?array
{
    return q_one(ENTRY_SQL . ' WHERE e.id = ?', [$id]);
}

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

function clock_in(int $uid, ?int $projectId, string $note, string $source): array
{
    if (open_entry($uid)) {
        throw new DomainException('Du bist bereits eingestempelt.');
    }
    if ($projectId !== null && !q_one('SELECT id FROM projects WHERE id = ? AND active = 1', [$projectId])) {
        $projectId = null;
    }
    q('INSERT INTO time_entries (user_id, project_id, start_time, note, source) VALUES (?, ?, NOW(), ?, ?)',
        [$uid, $projectId, mb_substr(trim($note), 0, 500), $source]);
    return entry_by_id((int)db()->lastInsertId());
}

function clock_out(int $uid): array
{
    $e = open_entry($uid);
    if (!$e) {
        throw new DomainException('Du bist nicht eingestempelt.');
    }
    q('UPDATE breaks SET end_time = NOW() WHERE entry_id = ? AND end_time IS NULL', [$e['id']]);
    q('UPDATE time_entries SET end_time = NOW() WHERE id = ?', [$e['id']]);
    return entry_by_id((int)$e['id']);
}

function break_start(int $uid): void
{
    $e = open_entry($uid);
    if (!$e) {
        throw new DomainException('Du bist nicht eingestempelt.');
    }
    if (open_break((int)$e['id'])) {
        throw new DomainException('Pause läuft bereits.');
    }
    q('INSERT INTO breaks (entry_id, start_time) VALUES (?, NOW())', [$e['id']]);
}

function break_end(int $uid): void
{
    $e = open_entry($uid);
    $b = $e ? open_break((int)$e['id']) : null;
    if (!$b) {
        throw new DomainException('Es läuft keine Pause.');
    }
    q('UPDATE breaks SET end_time = NOW() WHERE id = ?', [$b['id']]);
}

function user_status(int $uid): array
{
    $u = q_one('SELECT weekly_hours FROM users WHERE id = ?', [$uid]);
    $e = open_entry($uid);
    $b = $e ? open_break((int)$e['id']) : null;
    $cur = $e ? entry_by_id((int)$e['id']) : null;
    [$ws, $we] = week_bounds();
    return [
        'clocked_in' => $e !== null,
        'on_break' => $b !== null,
        'entry' => $cur ? [
            'id' => (int)$cur['id'],
            'start' => $cur['start_time'],
            'project_id' => $cur['project_id'] !== null ? (int)$cur['project_id'] : null,
            'project_name' => $cur['project_name'],
            'note' => $cur['note'],
        ] : null,
        'break_start' => $b['start_time'] ?? null,
        'current_seconds' => $cur ? (int)$cur['worked_sec'] : 0,
        'today_seconds' => worked_between($uid, date('Y-m-d'), date('Y-m-d')),
        'week_seconds' => worked_between($uid, $ws, $we),
        'week_target_seconds' => (int)round((float)$u['weekly_hours'] * 3600),
        'server_time' => date('Y-m-d H:i:s'),
    ];
}

/** Abwesenheitstage (genehmigt) eines Users im Zeitraum: [ 'Y-m-d' => type ] */
function absence_days(int $uid, string $from, string $to): array
{
    $rows = q_all("SELECT * FROM absences WHERE user_id = ? AND status = 'approved' AND date_from <= ? AND date_to >= ?", [$uid, $to, $from]);
    $days = [];
    foreach ($rows as $r) {
        $d = max(strtotime($r['date_from']), strtotime($from));
        $end = min(strtotime($r['date_to']), strtotime($to));
        for (; $d <= $end; $d = strtotime('+1 day', $d)) {
            $days[date('Y-m-d', $d)] = $r['type'];
        }
    }
    return $days;
}

/** Dienstplan: Schichten. Wird von install.php und schedule.php (idempotent) genutzt. */
const SHIFTS_SQL = "CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_min INT NOT NULL DEFAULT 0,
    project_id INT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (shift_date, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

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
