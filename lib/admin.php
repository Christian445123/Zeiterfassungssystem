<?php
declare(strict_types=1);

/**
 * Schreibende Fachfunktionen, gemeinsam für Webpanel und API.
 * Validierungs- und Rechtefehler werden als DomainException geworfen (Text ist für Benutzer bestimmt).
 */

function valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && date('Y-m-d', (int)strtotime($d)) === $d;
}

function valid_hhmm(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function require_perm(array $actor, string $perm): void
{
    if (!user_can($actor, $perm)) {
        throw new PermissionException('Dafür fehlt dir die Berechtigung.');
    }
}

// ------------------------------------------------------------ Zeiteinträge (manuell)

/**
 * Stunden eintragen/ändern. in: id (0 = neu), user_id (nur mit „Alle Stunden ändern“), date, start (HH:MM),
 * end (HH:MM; Ende <= Beginn = über Mitternacht), break_min, note, source (web|client)
 */
function entry_save(array $actor, array $in): int
{
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? entry_by_id($id) : null;
    if ($id && !$existing) {
        throw new DomainException('Eintrag nicht gefunden.');
    }
    $uid = $existing ? (int)$existing['user_id'] : (int)(($in['user_id'] ?? 0) ?: $actor['id']);
    if ($existing && !entry_editable($actor, $existing)) {
        throw new DomainException('Dieser Eintrag kann nicht mehr geändert werden.');
    }
    if ($uid !== (int)$actor['id']) {
        require_perm($actor, 'hours.edit_all');
    } else {
        require_perm($actor, 'hours.own');
    }
    $target = q_one('SELECT id FROM users WHERE id = ?', [$uid]);
    if (!$target) {
        throw new DomainException('Mitarbeiter nicht gefunden.');
    }

    $date = (string)($in['date'] ?? '');
    $st = (string)($in['start'] ?? '');
    $en = (string)($in['end'] ?? '');
    if (!valid_date($date) || !valid_hhmm($st) || !valid_hhmm($en)) {
        throw new DomainException('Bitte Datum sowie Beginn und Ende im Format HH:MM angeben.');
    }
    $start = strtotime("$date $st");
    $end = strtotime("$date $en");
    if ($end <= $start) {
        $end += 86400; // Nachtschicht
    }
    $brk = max(0, (int)($in['break_min'] ?? 0));
    if ($end - $start <= $brk * 60) {
        throw new DomainException('Die Pause ist so lang wie die gesamte Arbeitszeit – bitte Zeiten prüfen.');
    }
    if ($end - $start > 18 * 3600) {
        throw new DomainException('Ein einzelner Eintrag darf höchstens 18 Stunden lang sein.');
    }
    if (!user_can($actor, 'hours.edit_all') && $end > time()) {
        throw new DomainException('Das Ende liegt in der Zukunft – es können nur bereits geleistete Stunden eingetragen werden.');
    }
    assert_month_open($uid, $date);                               // neuer Monat darf nicht abgeschlossen sein
    if ($existing) {
        assert_month_open($uid, substr($existing['start_time'], 0, 10)); // auch der bisherige Monat nicht
    }
    $s = date('Y-m-d H:i:s', $start);
    $e = date('Y-m-d H:i:s', $end);
    if (q_one('SELECT id FROM time_entries WHERE user_id = ? AND id <> ? AND start_time < ? AND COALESCE(end_time, NOW()) > ?', [$uid, $id, $e, $s])) {
        throw new DomainException('Der Zeitraum überschneidet sich mit einem anderen Eintrag.');
    }
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);
    if ($existing) {
        q('UPDATE time_entries SET start_time=?, end_time=?, manual_break_min=?, note=? WHERE id=?', [$s, $e, $brk, $note, $id]);
        return $id;
    }
    $source = $uid !== (int)$actor['id'] ? 'manual' : 'web';
    q('INSERT INTO time_entries (user_id, start_time, end_time, manual_break_min, note, source) VALUES (?,?,?,?,?,?)',
        [$uid, $s, $e, $brk, $note, $source]);
    return (int)db()->lastInsertId();
}

function entry_delete(array $actor, int $id): void
{
    $e = entry_by_id($id);
    if (!$e) {
        return;
    }
    if (!entry_editable($actor, $e)) {
        throw new DomainException('Dieser Eintrag kann nicht mehr gelöscht werden.');
    }
    assert_month_open((int)$e['user_id'], substr($e['start_time'], 0, 10));
    q('DELETE FROM time_entries WHERE id = ?', [$id]);
}

// ------------------------------------------------------------ Abwesenheiten

/** in: type, date_from, date_to, hours (optional, Teiltag), note, user_id (nur mit „Abwesenheiten verwalten“). Verwalter-Einträge sind sofort genehmigt. */
function absence_create(array $actor, array $in): int
{
    $manage = user_can($actor, 'absences.manage');
    if (!$manage) {
        require_perm($actor, 'absences.request');
    }
    $type = (string)($in['type'] ?? '');
    $from = (string)($in['date_from'] ?? '');
    $to = (string)($in['date_to'] ?? '');
    if (!isset(ABSENCE_TYPES[$type]) || !valid_date($from) || !valid_date($to) || $to < $from) {
        throw new DomainException('Bitte gültige Art und einen gültigen Zeitraum angeben.');
    }
    $hours = null;
    if (isset($in['hours']) && trim((string)$in['hours']) !== '') {
        $hours = (float)str_replace(',', '.', (string)$in['hours']);
        if ($hours <= 0 || $hours > 24) {
            throw new DomainException('Stunden müssen zwischen 0 und 24 liegen (leer = ganzer Tag).');
        }
        if ($from !== $to) {
            throw new DomainException('Eine Teiltags-Abwesenheit (Stunden) gilt nur für einen einzelnen Tag.');
        }
    }
    $uid = $manage ? (int)(($in['user_id'] ?? 0) ?: $actor['id']) : (int)$actor['id'];
    if (!q_one('SELECT id FROM users WHERE id = ?', [$uid])) {
        throw new DomainException('Mitarbeiter nicht gefunden.');
    }
    q('INSERT INTO absences (user_id, type, date_from, date_to, hours, note, status) VALUES (?,?,?,?,?,?,?)',
        [$uid, $type, $from, $to, $hours, mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $manage ? 'approved' : 'pending']);
    return (int)db()->lastInsertId();
}

function absence_set_status(array $actor, int $id, string $status): void
{
    require_perm($actor, 'absences.manage');
    if (!isset(ABSENCE_STATUS[$status])) {
        throw new DomainException('Ungültiger Status.');
    }
    q('UPDATE absences SET status = ? WHERE id = ?', [$status, $id]);
}

function absence_delete(array $actor, int $id): void
{
    $a = q_one('SELECT * FROM absences WHERE id = ?', [$id]);
    if (!$a) {
        return;
    }
    $own = (int)$a['user_id'] === (int)$actor['id'] && $a['status'] === 'pending';
    if (!user_can($actor, 'absences.manage') && !$own) {
        throw new DomainException('Du darfst nur eigene, noch offene Anträge löschen.');
    }
    q('DELETE FROM absences WHERE id = ?', [$id]);
}

// ------------------------------------------------------------ Dienstplan (Recht: schedule.edit)

/** in: id (0 = neu), user_id, shift_date, start_time (HH:MM), end_time, break_min, note. Rückgabe: ['id','warning'] */
function shift_save(array $actor, array $in): array
{
    require_perm($actor, 'schedule.edit');
    $id = (int)($in['id'] ?? 0);
    $uid = (int)($in['user_id'] ?? 0);
    $date = (string)($in['shift_date'] ?? '');
    $st = (string)($in['start_time'] ?? '');
    $en = (string)($in['end_time'] ?? '');
    $brk = max(0, (int)($in['break_min'] ?? 0));
    if (!q_one('SELECT id FROM users WHERE id = ? AND active = 1', [$uid]) || !valid_date($date) || !valid_hhmm($st) || !valid_hhmm($en)) {
        throw new DomainException('Bitte Mitarbeiter, Datum und gültige Zeiten (HH:MM) angeben.');
    }
    if ($st === $en) {
        throw new DomainException('Beginn und Ende dürfen nicht gleich sein.');
    }
    if (shift_minutes($st, $en, $brk) <= 0) {
        throw new DomainException('Die Pause ist länger als die Schicht.');
    }
    $s1 = time_to_min($st);
    $e1 = time_to_min($en) <= $s1 ? time_to_min($en) + 1440 : time_to_min($en);
    foreach (q_all('SELECT * FROM shifts WHERE user_id = ? AND shift_date = ? AND id <> ?', [$uid, $date, $id]) as $o) {
        $s2 = time_to_min($o['start_time']);
        $e2 = time_to_min($o['end_time']) <= $s2 ? time_to_min($o['end_time']) + 1440 : time_to_min($o['end_time']);
        if ($s1 < $e2 && $s2 < $e1) {
            throw new DomainException('Überschneidung mit einer bestehenden Schicht dieses Mitarbeiters.');
        }
    }
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
    if ($id) {
        q('UPDATE shifts SET user_id=?, shift_date=?, start_time=?, end_time=?, break_min=?, note=? WHERE id=?',
            [$uid, $date, $st, $en, $brk, $note, $id]);
    } else {
        q('INSERT INTO shifts (user_id, shift_date, start_time, end_time, break_min, note) VALUES (?,?,?,?,?,?)',
            [$uid, $date, $st, $en, $brk, $note]);
        $id = (int)db()->lastInsertId();
    }
    // Hinweise (die Schicht wird trotzdem gespeichert – man kann immer von Hand eingreifen)
    $warnings = [];
    $bd = business_day($date);
    if (!$bd['open']) {
        $warnings[] = 'Der Betrieb ist an diesem Tag geschlossen (' . $bd['reason'] . ').';
    } elseif ($st < $bd['from'] || (time_to_min($en) > time_to_min($st) && $en > $bd['to'])) {
        $warnings[] = 'Die Schicht liegt außerhalb der Öffnungszeiten (' . $bd['from'] . '–' . $bd['to'] . ').';
    }
    if ($a = (absences_by_day($uid, $date, $date)[$date] ?? null)) {
        $warnings[] = 'Der Mitarbeiter hat an diesem Tag eine genehmigte Abwesenheit (' . ABSENCE_TYPES[$a['type']] . ').';
    }
    return ['id' => $id, 'warning' => $warnings ? 'Achtung: ' . implode(' ', $warnings) : null];
}

function shift_delete(array $actor, int $id): void
{
    require_perm($actor, 'schedule.edit');
    q('DELETE FROM shifts WHERE id = ?', [$id]);
}

/** Kopiert alle Schichten der Vorwoche in die (leere) Woche $ws–$we. Rückgabe: Anzahl. */
function shifts_copy_prev(array $actor, string $ws, string $we): int
{
    require_perm($actor, 'schedule.edit');
    if (q_one('SELECT id FROM shifts WHERE shift_date BETWEEN ? AND ? LIMIT 1', [$ws, $we])) {
        throw new DomainException('Diese Woche enthält schon Schichten – Kopieren nur in eine leere Woche.');
    }
    $n = q("INSERT INTO shifts (user_id, shift_date, start_time, end_time, break_min, note)
            SELECT s.user_id, DATE_ADD(s.shift_date, INTERVAL 7 DAY), s.start_time, s.end_time, s.break_min, s.note
            FROM shifts s JOIN users u ON u.id = s.user_id AND u.active = 1
            WHERE s.shift_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_SUB(?, INTERVAL 7 DAY)", [$ws, $we])->rowCount();
    if ($n === 0) {
        throw new DomainException('Die Vorwoche enthält keine Schichten.');
    }
    return $n;
}

// ------------------------------------------------------------ Mitarbeiter (Recht: users.manage)

function next_personnel_number(): string
{
    $max = (int)q_one("SELECT COALESCE(MAX(CAST(personnel_number AS UNSIGNED)), 999) m FROM users WHERE personnel_number REGEXP '^[0-9]+$'")['m'];
    return (string)($max + 1);
}

/**
 * in: id (0 = neu), personnel_number (bei neu leer = automatisch), full_name, role_id, weekly_hours, work_days (Array oder "1,2,3"),
 *     vacation_days ('' = automatisch 5 Wochen), vacation_carryover, vacation_carryover_year, start_date, active, password
 */
function user_save(array $actor, array $in): int
{
    require_perm($actor, 'users.manage');
    $id = (int)($in['id'] ?? 0);
    $name = trim((string)($in['full_name'] ?? ''));
    if ($name === '') {
        throw new DomainException('Name fehlt.');
    }
    $role = q_one('SELECT * FROM roles WHERE id = ?', [(int)($in['role_id'] ?? 0)]);
    if (!$role) {
        throw new DomainException('Bitte eine Rolle wählen.');
    }
    $legacyRole = trim((string)$role['permissions']) === '*' ? 'admin' : 'employee';
    // Arbeitszeitmodell: Stunden je Wochentag (day_hours: [1..7 => Stunden]); alternativ Wochenstunden + work_days (gleichmäßig)
    $dayHours = [];
    if (isset($in['day_hours']) && is_array($in['day_hours'])) {
        foreach ($in['day_hours'] as $d => $h) {
            $h = (float)str_replace(',', '.', (string)$h);
            if ((int)$d >= 1 && (int)$d <= 7 && $h > 0) {
                if ($h > 24) {
                    throw new DomainException('Pro Tag sind höchstens 24 Stunden möglich.');
                }
                $dayHours[(int)$d] = $h;
            }
        }
        ksort($dayHours);
    } else {
        $weekly = (float)str_replace(',', '.', (string)($in['weekly_hours'] ?? '40'));
        $wdIn = $in['work_days'] ?? [1, 2, 3, 4, 5];
        $wdIn = is_array($wdIn) ? $wdIn : explode(',', (string)$wdIn);
        $wdIn = array_values(array_unique(array_filter(array_map('intval', $wdIn), fn($x) => $x >= 1 && $x <= 7)));
        foreach ($wdIn as $d) {
            $dayHours[$d] = round($weekly / count($wdIn), 4);
        }
        ksort($dayHours);
    }
    if (!$dayHours) {
        throw new DomainException('Mindestens ein Arbeitstag mit Stunden ist nötig.');
    }
    $hours = round(array_sum($dayHours), 2);
    if ($hours > 80) {
        throw new DomainException('Mehr als 80 Wochenstunden sind nicht möglich.');
    }
    $wd = array_keys($dayHours);
    $dh = implode(',', array_map(fn($d, $h) => $d . ':' . $h, array_keys($dayHours), $dayHours));
    $vac = trim((string)($in['vacation_days'] ?? ''));
    $vac = $vac === '' ? null : max(0, min(100, (float)str_replace(',', '.', $vac)));
    $carry = max(0, min(100, (float)str_replace(',', '.', (string)($in['vacation_carryover'] ?? '0'))));
    $carryYear = (int)($in['vacation_carryover_year'] ?? 0) ?: (int)date('Y');
    $start = trim((string)($in['start_date'] ?? ''));
    if ($start !== '' && !valid_date($start)) {
        throw new DomainException('Ungültiges Eintrittsdatum.');
    }
    $start = $start === '' ? null : $start;
    $pass = (string)($in['password'] ?? '');
    if ($pass !== '' && strlen($pass) < 8) {
        throw new DomainException('Passwort: mindestens 8 Zeichen.');
    }
    $pn = trim((string)($in['personnel_number'] ?? ''));
    if ($pn !== '' && !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $pn)) {
        throw new DomainException('Personalnummer: nur Buchstaben, Ziffern und . _ - (max. 20 Zeichen).');
    }

    try {
        if (!$id) {
            if (strlen($pass) < 8) {
                throw new DomainException('Ein Start-Passwort (min. 8 Zeichen) ist Pflicht.');
            }
            $pn = $pn !== '' ? $pn : next_personnel_number();
            // Neues Passwort muss beim ersten Login geändert werden
            q('INSERT INTO users (username, personnel_number, password_hash, full_name, role, role_id, weekly_hours, work_days, day_hours,
                                  vacation_days_override, vacation_carryover, vacation_carryover_year, start_date, must_change_password)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
                [$pn, $pn, password_hash($pass, PASSWORD_DEFAULT), $name, $legacyRole, $role['id'], $hours, implode(',', $wd), $dh, $vac, $carry, $carryYear, $start]);
            return (int)db()->lastInsertId();
        }
        $active = !empty($in['active']) ? 1 : 0;
        if ($id === (int)$actor['id'] && (!$active || (int)$role['id'] !== (int)user_role($actor)['id'])) {
            throw new DomainException('Du kannst dich nicht selbst deaktivieren und dir nicht selbst eine andere Rolle geben.');
        }
        q('UPDATE users SET full_name=?, role=?, role_id=?, weekly_hours=?, work_days=?, day_hours=?, vacation_days_override=?, vacation_carryover=?,
                            vacation_carryover_year=?, start_date=?, active=? WHERE id=?',
            [$name, $legacyRole, $role['id'], $hours, implode(',', $wd), $dh, $vac, $carry, $carryYear, $start, $active, $id]);
        if ($pn !== '') {
            q('UPDATE users SET personnel_number = ? WHERE id = ?', [$pn, $id]);
        }
        if ($pass !== '') {
            q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
        return $id;
    } catch (PDOException $ex) {
        if ((string)$ex->getCode() === '23000') {
            throw new DomainException('Diese Personalnummer ist schon vergeben.');
        }
        throw $ex;
    }
}

/** Manuelle Überstundenbuchung (z. B. Auszahlung = negativ, Korrektur/Übertrag = positiv). */
function overtime_adjust(array $actor, int $uid, string $date, int $minutes, string $note): void
{
    require_perm($actor, 'overtime.manage');
    if (!q_one('SELECT id FROM users WHERE id = ?', [$uid])) {
        throw new DomainException('Mitarbeiter nicht gefunden.');
    }
    if (!valid_date($date) || $minutes === 0) {
        throw new DomainException('Bitte Datum und eine Stundenzahl ungleich 0 angeben.');
    }
    q('INSERT INTO overtime_adjustments (user_id, adj_date, minutes, note, created_by) VALUES (?,?,?,?,?)',
        [$uid, $date, $minutes, mb_substr(trim($note), 0, 255), $actor['id']]);
}

/** Benutzerzeile für Listen (Web + API): inkl. Rolle, Urlaub und Überstundenkonto. */
function user_overview(array $u): array
{
    $role = user_role($u);
    return [
        'id' => (int)$u['id'], 'personnel_number' => $u['personnel_number'] ?? null, 'username' => $u['username'],
        'name' => $u['full_name'], 'role_id' => (int)($u['role_id'] ?? 0), 'role' => $role['name'],
        'active' => (bool)$u['active'], 'weekly_hours' => (float)$u['weekly_hours'],
        'work_days' => user_workdays($u), 'day_hours' => user_day_hours($u),
        'vacation_days_override' => isset($u['vacation_days_override']) ? (float)$u['vacation_days_override'] : null,
        'vacation_carryover' => (float)($u['vacation_carryover'] ?? 0),
        'vacation_carryover_year' => isset($u['vacation_carryover_year']) ? (int)$u['vacation_carryover_year'] : null,
        'start_date' => !empty($u['start_date']) ? $u['start_date'] : null,
        'must_change_password' => !empty($u['must_change_password']),
        'vacation' => vacation_summary($u, (int)date('Y')), 'overtime_seconds' => overtime_balance($u)['seconds'],
    ];
}

// ------------------------------------------------------------ Rollen (Recht: roles.manage)

/** in: id (0 = neu), name, description, permissions (Array von Schlüsseln). Rolle „Vollzugriff“ bleibt unveränderlich. */
function role_save(array $actor, array $in): int
{
    require_perm($actor, 'roles.manage');
    $id = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 60) {
        throw new DomainException('Bitte einen Rollennamen (max. 60 Zeichen) angeben.');
    }
    $perms = $in['permissions'] ?? [];
    $perms = is_array($perms) ? $perms : explode(',', (string)$perms);
    $perms = array_values(array_intersect(array_map('trim', $perms), array_keys(PERMISSIONS)));
    $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 255);
    try {
        if ($id) {
            $r = q_one('SELECT * FROM roles WHERE id = ?', [$id]);
            if (!$r) {
                throw new DomainException('Rolle nicht gefunden.');
            }
            if (trim((string)$r['permissions']) === '*') {
                throw new DomainException('Die Rolle „Vollzugriff“ ist fest und kann nicht geändert werden.');
            }
            q('UPDATE roles SET name = ?, description = ?, permissions = ? WHERE id = ?', [$name, $desc, implode(',', $perms), $id]);
            return $id;
        }
        q('INSERT INTO roles (name, description, permissions) VALUES (?,?,?)', [$name, $desc, implode(',', $perms)]);
        return (int)db()->lastInsertId();
    } catch (PDOException $ex) {
        if ((string)$ex->getCode() === '23000') {
            throw new DomainException('Eine Rolle mit diesem Namen gibt es schon.');
        }
        throw $ex;
    }
}

function role_delete(array $actor, int $id): void
{
    require_perm($actor, 'roles.manage');
    $r = q_one('SELECT * FROM roles WHERE id = ?', [$id]);
    if (!$r) {
        return;
    }
    if ((int)$r['is_system']) {
        throw new DomainException('Systemrollen können nicht gelöscht werden.');
    }
    if (q_one('SELECT id FROM users WHERE role_id = ? LIMIT 1', [$id])) {
        throw new DomainException('Die Rolle ist noch Mitarbeitern zugewiesen – bitte zuerst umstellen.');
    }
    q('DELETE FROM roles WHERE id = ?', [$id]);
}

function roles_overview(): array
{
    $out = [];
    foreach (q_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.is_system DESC, r.name') as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'system' => (bool)$r['is_system'],
            'full' => trim((string)$r['permissions']) === '*', 'permissions' => role_permissions((string)$r['permissions']),
            'user_count' => (int)$r['user_count']];
    }
    return $out;
}
