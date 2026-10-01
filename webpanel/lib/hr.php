<?php
declare(strict_types=1);

/**
 * Personal-Logik (Arbeitszeitmodell, Feiertage, Urlaub, Überstunden, Dienstplan-Gegenüberstellung).
 * Wird vom Webpanel UND von der API verwendet, damit beide dieselben Zahlen zeigen.
 */

const ABSENCE_TYPES = [
    'vacation' => 'Urlaub',
    'sick' => 'Krankenstand',
    'doctor' => 'Arzt',
    'comp' => 'Zeitausgleich',
    'other' => 'Sonstiges',
];
const ABSENCE_STATUS = ['pending' => 'Offen', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt'];
const ABSENCE_SHORT = ['vacation' => 'U', 'sick' => 'K', 'doctor' => 'A', 'comp' => 'Z', 'other' => 'S', 'away' => '–'];
const WEEKDAY_SHORT =[1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

// ------------------------------------------------------------ Feiertage (Österreich)

function easter_sunday(int $y): int
{
    $a = $y % 19;
    $b = intdiv($y, 100);
    $c = $y % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return mktime(0, 0, 0, $month, $day, $y);
}

/** ['Y-m-d' => Name]. HOLIDAYS=NONE in der .env schaltet Feiertage ab. */
function holidays_for_year(int $y): array
{
    static $cache = [];
    if (isset($cache[$y])) {
        return $cache[$y];
    }
    if (strtoupper((string)cfg('holidays')) === 'NONE') {
        return $cache[$y] = [];
    }
    $e = easter_sunday($y);
    $off = fn(int $d): string => date('Y-m-d', strtotime("+$d days", $e));
    return $cache[$y] = [
        "$y-01-01" => 'Neujahr', "$y-01-06" => 'Heilige Drei Könige', $off(1) => 'Ostermontag',
        "$y-05-01" => 'Staatsfeiertag', $off(39) => 'Christi Himmelfahrt', $off(50) => 'Pfingstmontag',
        $off(60) => 'Fronleichnam', "$y-08-15" => 'Mariä Himmelfahrt', "$y-10-26" => 'Nationalfeiertag',
        "$y-11-01" => 'Allerheiligen', "$y-12-08" => 'Mariä Empfängnis', "$y-12-25" => 'Christtag', "$y-12-26" => 'Stefanitag',
    ];
}

function holiday_name(string $date): ?string
{
    return holidays_for_year((int)substr($date, 0, 4))[$date] ?? null;
}

// ------------------------------------------------------------ Öffnungszeiten, Feiertage, Sondertage

const DEFAULT_OPENING = [
    1 => ['07:00', '18:30'], 2 => ['07:00', '18:30'], 3 => ['07:00', '18:30'], 4 => ['07:00', '18:30'], 5 => ['07:00', '18:30'],
    6 => ['07:30', '13:00'], 7 => null,
];

/** Öffnungszeiten je Wochentag [1..7 => [von, bis] | null]: Standard Mo–Fr 07:00–18:30, Sa 07:30–13:00, So geschlossen; unter „Betrieb“ änderbar. */
function opening_hours(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $stored = json_decode(setting_get('opening_hours', ''), true);
        $cache = [];
        for ($d = 1; $d <= 7; $d++) {
            $cache[$d] = is_array($stored) && array_key_exists((string)$d, $stored) ? $stored[(string)$d] : DEFAULT_OPENING[$d];
        }
    }
    return $cache;
}

/** Sondertage (manuell definiert): ['Y-m-d' => Zeile]. */
function special_days_map(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (q_all('SELECT * FROM special_days') as $r) {
            $cache[$r['special_date']] = $r;
        }
    }
    return $cache;
}

/**
 * Ist der Betrieb an diesem Tag geöffnet? Österreichische Feiertage sind automatisch geschlossen, außer sie wurden als
 * „geöffnet“ überschrieben; zusätzlich lassen sich eigene Schließ- und Öffnungstage definieren.
 * Rückgabe: open, from, to, reason (Grund bei geschlossen), label (nur Feiertage/Sondertage), special (closed|open|null)
 */
function business_day(string $date): array
{
    $dow = (int)date('N', strtotime($date));
    $hol = holiday_name($date);
    $sp = special_days_map()[$date] ?? null;
    $oh = opening_hours()[$dow] ?? null;
    if ($sp && $sp['kind'] === 'closed') {
        $why = $sp['name'] !== '' ? $sp['name'] : 'Betrieb geschlossen';
        return ['open' => false, 'from' => null, 'to' => null, 'reason' => $why, 'label' => $why, 'special' => 'closed'];
    }
    if ($sp && $sp['kind'] === 'open') {
        $why = $sp['name'] !== '' ? $sp['name'] : ($hol ? $hol . ' (geöffnet)' : 'Sonderöffnung');
        return ['open' => true, 'from' => $sp['open_from'] ? substr($sp['open_from'], 0, 5) : ($oh[0] ?? '07:00'),
            'to' => $sp['open_to'] ? substr($sp['open_to'], 0, 5) : ($oh[1] ?? '18:30'), 'reason' => null, 'label' => $why, 'special' => 'open'];
    }
    if ($hol !== null) {
        return ['open' => false, 'from' => null, 'to' => null, 'reason' => $hol, 'label' => $hol, 'special' => null];
    }
    if ($oh === null) {
        return ['open' => false, 'from' => null, 'to' => null, 'reason' => 'Ruhetag', 'label' => null, 'special' => null];
    }
    return ['open' => true, 'from' => $oh[0], 'to' => $oh[1], 'reason' => null, 'label' => null, 'special' => null];
}

// ------------------------------------------------------------ Arbeitszeitmodell

/** Soll-Stunden je Wochentag [1..7 => Stunden]: aus "day_hours" (z. B. "1:8,2:6,4:4"), sonst Wochenstunden gleichmäßig auf work_days. */
function user_day_hours(array $u): array
{
    $out = [];
    if (!empty($u['day_hours'])) {
        foreach (explode(',', (string)$u['day_hours']) as $part) {
            [$d, $h] = array_pad(explode(':', $part, 2), 2, '0');
            if ((int)$d >= 1 && (int)$d <= 7 && (float)$h > 0) {
                $out[(int)$d] = (float)$h;
            }
        }
        ksort($out);
        if ($out) {
            return $out;
        }
    }
    $days = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($u['work_days'] ?? '1,2,3,4,5'))),
        fn($x) => $x >= 1 && $x <= 7
    ))) ?: [1, 2, 3, 4, 5];
    sort($days);
    foreach ($days as $d) {
        $out[$d] = round((float)$u['weekly_hours'] / count($days), 4);
    }
    return $out;
}

/** ISO-Wochentage (1 = Mo … 7 = So), an denen der Mitarbeiter arbeitet. */
function user_workdays(array $u): array
{
    return array_keys(user_day_hours($u));
}

/** Soll in Sekunden für einen Wochentag (ohne Angabe: Durchschnitt je Arbeitstag). */
function user_daily_target(array $u, ?int $dow = null): int
{
    $h = user_day_hours($u);
    if ($dow !== null) {
        return (int)round(($h[$dow] ?? 0) * 3600);
    }
    return $h ? (int)round(array_sum($h) / count($h) * 3600) : 0;
}

function user_start_date(array $u): string
{
    return !empty($u['start_date']) ? (string)$u['start_date'] : substr((string)$u['created_at'], 0, 10);
}

/** Genehmigte (oder alle) Abwesenheiten je Tag: ['Y-m-d' => ['type','hours','status','id']] */
function absences_by_day(int $uid, string $from, string $to, bool $approvedOnly = true): array
{
    $sql = 'SELECT * FROM absences WHERE user_id = ? AND date_from <= ? AND date_to >= ?'
        . ($approvedOnly ? " AND status = 'approved'" : " AND status <> 'rejected'") . ' ORDER BY id';
    $days = [];
    foreach (q_all($sql, [$uid, $to, $from]) as $r) {
        $d = max(strtotime($r['date_from']), strtotime($from));
        $end = min(strtotime($r['date_to']), strtotime($to));
        for (; $d <= $end; $d = strtotime('+1 day', $d)) {
            $days[date('Y-m-d', $d)] ??= [
                'type' => $r['type'], 'hours' => $r['hours'] !== null ? (float)$r['hours'] : null,
                'status' => $r['status'], 'id' => (int)$r['id'],
            ];
        }
    }
    return $days;
}

/** Kompatibel: ['Y-m-d' => type] */
function absence_days(int $uid, string $from, string $to): array
{
    return array_map(fn($a) => $a['type'], absences_by_day($uid, $from, $to));
}

/**
 * Soll-Sekunden eines Tages. Kein Soll an: Nicht-Arbeitstagen, Feiertagen, vor Eintritt, bei Urlaub/Krank/Arzt/Sonstiges
 * (bei Teilzeit-Abwesenheit mit „hours“ nur um diese Stunden reduziert). Zeitausgleich lässt das Soll stehen –
 * dadurch sinkt der Überstundenkonto-Saldo automatisch.
 */
function day_target(array $u, string $date, ?array $abs): int
{
    if (!in_array((int)date('N', strtotime($date)), user_workdays($u), true)) {
        return 0;
    }
    if ($date < user_start_date($u) || !business_day($date)['open']) {
        return 0;
    }
    $t = user_daily_target($u, (int)date('N', strtotime($date)));
    if ($abs === null || $abs['type'] === 'comp') {
        return $t;
    }
    return $abs['hours'] !== null ? max(0, $t - (int)round($abs['hours'] * 3600)) : 0;
}

/** Tageszeilen eines Zeitraums inkl. Ist, Soll, Abwesenheit, Feiertag. Schlüssel: 'Y-m-d' */
function day_rows(array $u, string $from, string $to): array
{
    $uid = (int)$u['id'];
    $agg = [];
    foreach (entries_between($uid, $from, $to) as $r) {
        $d = substr($r['start_time'], 0, 10);
        $last = $r['end_time'] ?? date('Y-m-d H:i:s');
        $agg[$d]['worked'] = ($agg[$d]['worked'] ?? 0) + (int)$r['worked_sec'];
        $agg[$d]['break'] = ($agg[$d]['break'] ?? 0) + (int)$r['break_sec'];
        $agg[$d]['first'] = min($agg[$d]['first'] ?? $r['start_time'], $r['start_time']);
        $agg[$d]['last'] = max($agg[$d]['last'] ?? $last, $last);
    }
    $abs = absences_by_day($uid, $from, $to);
    $rows = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $dow = (int)date('N', $t);
        $a = $abs[$d] ?? null;
        $rows[$d] = [
            'date' => $d, 'dow' => $dow, 'weekday' => WEEKDAY_SHORT[$dow],
            'workday' => in_array($dow, user_workdays($u), true),
            'holiday' => business_day($d)['label'], 'closed' => !business_day($d)['open'],
            'first' => $agg[$d]['first'] ?? null, 'last' => $agg[$d]['last'] ?? null,
            'break' => $agg[$d]['break'] ?? 0, 'worked' => $agg[$d]['worked'] ?? 0,
            'target' => day_target($u, $d, $a),
            'absence' => $a,
        ];
    }
    return $rows;
}

function week_summary(array $u, ?string $ref = null): array
{
    [$ws, $we] = week_bounds($ref);
    $worked = $target = 0;
    foreach (day_rows($u, $ws, $we) as $r) {
        $worked += $r['worked'];
        $target += $r['target'];
    }
    return ['start' => $ws, 'end' => $we, 'worked' => $worked, 'target' => $target];
}

// ------------------------------------------------------------ Überstunden

/**
 * Überstundenkonto = Σ(Ist − Soll) seit Eintritt + manuelle Buchungen (Auszahlung/Korrektur).
 * Der heutige Tag zählt erst, sobald gearbeitet wurde (sonst wäre morgens alles im Minus).
 */
function overtime_balance(array $u, ?string $upTo = null): array
{
    $today = date('Y-m-d');
    $to = ($upTo === null || $upTo > $today) ? $today : $upTo;
    $from = user_start_date($u);
    $sum = 0;
    if ($from <= $to) {
        foreach (day_rows($u, $from, $to) as $r) {
            if ($r['date'] === $today && $r['worked'] === 0) {
                continue;
            }
            $sum += $r['worked'] - $r['target'];
        }
    }
    $adj = (int)q_one('SELECT COALESCE(SUM(minutes), 0) s FROM overtime_adjustments WHERE user_id = ? AND adj_date <= ?', [$u['id'], $upTo ?? '9999-12-31'])['s'] * 60;
    return ['work_seconds' => $sum, 'adjust_seconds' => $adj, 'seconds' => $sum + $adj, 'since' => $from];
}

// ------------------------------------------------------------ Urlaub

/** Urlaubsanspruch im Jahr: manuell festgelegt, sonst VACATION_WEEKS (Standard 5) × Arbeitstage/Woche; im Eintrittsjahr anteilig. */
function vacation_entitlement(array $u, int $year): float
{
    $start = user_start_date($u);
    $sy = (int)substr($start, 0, 4);
    if ($year < $sy) {
        return 0.0;
    }
    if (isset($u['vacation_days_override']) && $u['vacation_days_override'] !== '') {
        return (float)$u['vacation_days_override'];
    }
    $base = (float)cfg('vacation_weeks') * count(user_workdays($u));
    if ($year === $sy) {
        $months = 12 - ((int)substr($start, 5, 2) - 1);
        $base = ceil($base * $months / 12 * 2) / 2;
    }
    return $base;
}

/** Zählwert eines Abwesenheitstages (0–1): nur Arbeitstage, keine Feiertage; mit Stunden anteilig. */
function absence_units(array $u, string $date, ?float $hours): float
{
    if (!in_array((int)date('N', strtotime($date)), user_workdays($u), true) || !business_day($date)['open']) {
        return 0.0;
    }
    if ($hours === null) {
        return 1.0;
    }
    $daily = user_daily_target($u, (int)date('N', strtotime($date))) / 3600;
    return $daily > 0 ? min(1.0, $hours / $daily) : 0.0;
}

/** Urlaubs- und Abwesenheitsübersicht eines Jahres. */
function vacation_summary(array $u, int $year): array
{
    $from = "$year-01-01";
    $to = "$year-12-31";
    $t = [];
    foreach (q_all("SELECT * FROM absences WHERE user_id = ? AND status <> 'rejected' AND date_from <= ? AND date_to >= ?", [$u['id'], $to, $from]) as $r) {
        $d = max(strtotime($r['date_from']), strtotime($from));
        $end = min(strtotime($r['date_to']), strtotime($to));
        for (; $d <= $end; $d = strtotime('+1 day', $d)) {
            $key = $r['type'] . '_' . $r['status'];
            $t[$key] = ($t[$key] ?? 0.0) + absence_units($u, date('Y-m-d', $d), $r['hours'] !== null ? (float)$r['hours'] : null);
        }
    }
    $ent = vacation_entitlement($u, $year);
    $carry = ((int)($u['vacation_carryover_year'] ?? 0) === $year) ? (float)$u['vacation_carryover'] : 0.0;
    $taken = $t['vacation_approved'] ?? 0.0;
    $pending = $t['vacation_pending'] ?? 0.0;
    return [
        'year' => $year, 'entitlement' => $ent, 'carryover' => $carry, 'taken' => $taken, 'pending' => $pending,
        'remaining' => $ent + $carry - $taken, 'remaining_after_pending' => $ent + $carry - $taken - $pending,
        'sick_days' => $t['sick_approved'] ?? 0.0, 'doctor_days' => $t['doctor_approved'] ?? 0.0,
        'comp_days' => $t['comp_approved'] ?? 0.0, 'other_days' => $t['other_approved'] ?? 0.0,
    ];
}

// ------------------------------------------------------------ Monatsauswertung

function report_data(array $u, string $month): array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $rows = array_values(day_rows($u, $from, $to));
    $worked = array_sum(array_column($rows, 'worked'));
    $target = array_sum(array_column($rows, 'target'));
    return [
        'month' => $month, 'from' => $from, 'to' => $to, 'rows' => $rows,
        'worked' => $worked, 'target' => $target, 'balance' => $worked - $target,
        'overtime' => overtime_balance($u),
        'vacation' => vacation_summary($u, (int)substr($month, 0, 4)),
    ];
}

// ------------------------------------------------------------ Dienstplan: Soll (Plan) und Ist (Anwesenheit)

/**
 * Wochenübersicht für alle aktiven Mitarbeiter: pro Tag geplante Schichten (Soll) neben der tatsächlichen
 * Anwesenheit (Ist) aus den Zeiteinträgen, inkl. Abweichungsstatus.
 */
function schedule_week_data(string $ref = 'today', ?array $viewer = null): array
{
    [$ws, $we] = week_bounds($ref);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = date('Y-m-d', strtotime("$ws +$i days"));
    }
    $today = date('Y-m-d');

    $shifts = [];
    foreach (q_all('SELECT s.* FROM shifts s
                    WHERE s.shift_date BETWEEN ? AND ? ORDER BY s.shift_date, s.start_time', [$ws, $we]) as $s) {
        $shifts[$s['user_id']][$s['shift_date']][] = [
            'id' => (int)$s['id'], 'start' => substr($s['start_time'], 0, 5), 'end' => substr($s['end_time'], 0, 5),
            'break_min' => (int)$s['break_min'], 'minutes' => shift_minutes($s['start_time'], $s['end_time'], (int)$s['break_min']),
            'note' => $s['note'],
        ];
    }
    $entries = [];
    foreach (array_reverse(entries_between(null, $ws, $we)) as $r) {
        $entries[$r['user_id']][substr($r['start_time'], 0, 10)][] = $r;
    }

    $users = [];
    $dayPlan = $dayIst = $dayPlanned = $dayPresent = array_fill_keys($days, 0);
    foreach (q_all('SELECT * FROM users WHERE active = 1 ORDER BY full_name') as $u) {
        $uid = (int)$u['id'];
        // Anwesenheit (Ist) und Soll sieht jeder nur fuer sich selbst, ausser mit dem Recht "hours.view_all"
        $showActual = $viewer === null || user_can($viewer, 'hours.view_all') || ($uid === (int)$viewer['id'] && user_can($viewer, 'hours.own'));
        $abs = absences_by_day($uid, $ws, $we);
        $cells = [];
        $plan = $soll = $ist = 0;
        foreach ($days as $d) {
            $sh = $shifts[$uid][$d] ?? [];
            $planMin = array_sum(array_column($sh, 'minutes'));
            $es = [];
            $workedMin = 0;
            foreach ($showActual ? ($entries[$uid][$d] ?? []) : [] as $e) {
                $m = intdiv((int)$e['worked_sec'], 60);
                $workedMin += $m;
                $es[] = ['id' => (int)$e['id'], 'start' => substr($e['start_time'], 11, 5),
                    'end' => $e['end_time'] ? substr($e['end_time'], 11, 5) : null, 'worked_min' => $m];
            }
            $first = $es[0]['start'] ?? null;
            $lastE = $es ? end($es) : null;
            $a = $abs[$d] ?? null;

            // Abweichungsstatus (Ist gegen Plan)
            $status = '';
            $late = 0;
            if ($d > $today) {
                $status = $planMin ? 'future' : '';
            } elseif (!$showActual) {
                $status = '';
            } elseif ($planMin > 0) {
                $planStart = time_to_min($sh[0]['start']);
                if ($workedMin === 0) {
                    $status = $a ? 'excused' : ($d < $today ? 'missing' : 'pending');
                } else {
                    $late = max(0, time_to_min($first) - $planStart);
                    $status = $late > 10 ? 'late' : ($workedMin < $planMin - 15 && $d < $today ? 'short' : 'ok');
                }
            } elseif ($workedMin > 0) {
                $status = 'unplanned';
            }

            $cells[$d] = [
                'shifts' => $sh, 'plan_min' => $planMin,
                'actual' => ['entries' => $es, 'worked_min' => $workedMin, 'first' => $first,
                    'last' => $lastE ? ($lastE['end'] ?? 'läuft') : null],
                'absence' => $a ? ['type' => $a['type'], 'hours' => $a['hours']] : null,
                'holiday' => business_day($d)['label'], 'closed' => !business_day($d)['open'], 'status' => $status, 'late_min' => $late,
                'target_min' => intdiv(day_target($u, $d, $a), 60),
            ];
            $plan += $planMin;
            $soll += $cells[$d]['target_min'];
            $ist += $workedMin;
            $dayPlan[$d] += $planMin;
            $dayIst[$d] += $workedMin;
            $dayPlanned[$d] += $sh ? 1 : 0;
            $dayPresent[$d] += $workedMin > 0 ? 1 : 0;
        }
        $users[] = ['id' => $uid, 'name' => $u['full_name'], 'weekly_hours' => $showActual ? (float)$u['weekly_hours'] : null, 'show_actual' => $showActual,
            'plan_min' => $plan, 'soll_min' => $showActual ? $soll : null, 'ist_min' => $showActual ? $ist : null, 'cells' => $cells];
    }
    return [
        'week_start' => $ws, 'week_end' => $we, 'kw' => (int)date('W', strtotime($ws)), 'days' => $days,
        'users' => $users,
        'day_plan_min' => $dayPlan, 'day_ist_min' => $dayIst, 'day_planned' => $dayPlanned, 'day_present' => $dayPresent,
    ];
}

// ------------------------------------------------------------ Urlaubsplaner (Abwesenheitskalender)

/**
 * Monatskalender: wer ist an welchem Tag abwesend (genehmigt und beantragt). Krankenstand/Arzt/Sonstiges
 * sehen andere nur als "abwesend" – die genaue Art nur der Mitarbeiter selbst und wer Abwesenheiten verwalten darf.
 */
function absence_calendar_data(string $month, array $viewer): array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $days[$d] = ['date' => $d, 'dow' => (int)date('N', $t), 'weekday' => WEEKDAY_SHORT[(int)date('N', $t)],
            'holiday' => business_day($d)['label'], 'off' => 0, 'working' => 0];
    }
    $manage = user_can($viewer, 'absences.manage');
    $users = [];
    foreach (q_all('SELECT * FROM users WHERE active = 1 ORDER BY full_name') as $u) {
        $uid = (int)$u['id'];
        $full = $manage || $uid === (int)$viewer['id'];
        $abs = absences_by_day($uid, $from, $to, false);
        $cells = [];
        foreach ($days as $d => $info) {
            $works = in_array($info['dow'], user_workdays($u), true) && business_day($d)['open'] && $d >= user_start_date($u);
            $a = $abs[$d] ?? null;
            $cell = ['works' => $works, 'absence' => null];
            if ($a) {
                $type = $full ? $a['type'] : ($a['type'] === 'vacation' ? 'vacation' : 'away');
                $cell['absence'] = ['type' => $type, 'status' => $a['status'], 'hours' => $full ? $a['hours'] : null];
                if ($works && $a['hours'] === null) {
                    $days[$d]['off']++;
                }
            }
            if ($works) {
                $days[$d]['working']++;
            }
            $cells[$d] = $cell;
        }
        $users[] = ['id' => $uid, 'name' => $u['full_name'], 'cells' => $cells];
    }
    return ['month' => $month, 'days' => array_values($days), 'users' => $users];
}
