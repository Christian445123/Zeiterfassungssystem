<?php
declare(strict_types=1);

/**
 * Angemeldete API-Routen für die Mobile Apps. Jede Route nutzt dieselben Fachfunktionen wie das Webpanel
 * (lib/hr.php, lib/admin.php, lib/business.php) und damit auch dieselben Rechteprüfungen (lib/rbac.php).
 * Fehler: DomainException → HTTP 409, PermissionException → HTTP 403.
 */

function api_user(array $u): array
{
    return [
        'id' => (int)$u['id'], 'name' => $u['full_name'], 'personnel_number' => $u['personnel_number'] ?? null,
        'role' => user_role($u)['name'], 'permissions' => user_permissions($u),
        'must_change_password' => !empty($u['must_change_password']), 'time_tracking' => user_tracks($u),
    ];
}

/** Mindestens eines der Rechte nötig. */
function api_need_any(array $me, string ...$perms): void
{
    foreach ($perms as $p) {
        if (user_can($me, $p)) {
            return;
        }
    }
    throw new PermissionException('Dafür fehlt dir die Berechtigung.');
}

function api_date(string $key, string $default): string
{
    $v = (string)($_GET[$key] ?? '');
    return valid_date($v) ? $v : $default;
}

function api_month(): string
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
}

function api_dispatch(string $method, string $route, array $b, array $me, string $tok): void
{
    $uid = (int)$me['id'];
    $today = date('Y-m-d');

    switch ($method . ' ' . $route) {
        // ------------------------------------------------ Konto
        case 'GET me':
            $d = dashboard_data($me);
            $recent = [];
            if (user_can($me, 'hours.own') && user_tracks($me)) {
                foreach (array_slice(entries_between($uid, date('Y-m-d', strtotime('-60 days')), $today), 0, 8) as $r) {
                    $recent[] = api_entry($me, $r);
                }
            }
            api_ok(['user' => api_user($me), 'dashboard' => $d, 'recent_entries' => $recent, 'server_date' => $today]);

        case 'POST auth/logout':
            q('DELETE FROM app_tokens WHERE token_hash = ?', [hash('sha256', $tok)]);
            api_ok();

        case 'POST auth/password':
            if (!password_verify((string)($b['old'] ?? ''), $me['password_hash'])) {
                usleep(500000);
                throw new DomainException('Das aktuelle Passwort ist falsch.');
            }
            $new = (string)($b['new'] ?? '');
            if (strlen($new) < 8) {
                throw new DomainException('Das neue Passwort braucht mindestens 8 Zeichen.');
            }
            if ($new === (string)$b['old']) {
                throw new DomainException('Das neue Passwort muss sich vom alten unterscheiden.');
            }
            q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            q('DELETE FROM app_tokens WHERE user_id = ? AND token_hash <> ?', [$uid, hash('sha256', $tok)]); // andere Geräte abmelden
            api_ok();

        case 'GET users/lookup':
            api_need_any($me, 'hours.edit_all', 'hours.view_all', 'reports.view_all', 'absences.manage', 'schedule.edit', 'users.view', 'users.manage', 'overtime.manage', 'months.close');
            api_ok(['users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['full_name'], 'personnel_number' => $u['personnel_number'], 'active' => (bool)$u['active']],
                q_all('SELECT id, full_name, personnel_number, active FROM users WHERE time_tracking = 1 ORDER BY full_name'))]);

        // ------------------------------------------------ Stunden
        case 'GET entries':
            $filter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $uid; // 0 = alle
            if ($filter !== $uid) {
                require_perm($me, 'hours.view_all');
            } else {
                api_need_any($me, 'hours.own', 'hours.view_all');
            }
            $rows = entries_between($filter ?: null, api_date('from', date('Y-m-01')), api_date('to', $today));
            api_ok(['entries' => array_map(fn($r) => api_entry($me, $r), $rows)]);

        case 'POST entries/save':
            api_ok(['id' => entry_save($me, $b + ['source' => 'app'])]);

        case 'POST entries/manual': // Arbeitszeit ODER Arzt/Krank/Urlaub/… (kind)
            api_ok(['message' => manual_entry($me, $b + ['source' => 'app'])]);

        case 'POST entries/delete':
            entry_delete($me, (int)($b['id'] ?? 0));
            api_ok();

        case 'GET report':
            $target = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $uid;
            if ($target !== $uid) {
                require_perm($me, 'reports.view_all');
            } else {
                api_need_any($me, 'hours.own', 'reports.view_all');
            }
            $user = q_one('SELECT * FROM users WHERE id = ?', [$target]);
            if (!$user) {
                throw new DomainException('Mitarbeiter nicht gefunden.');
            }
            $month = api_month();
            $rep = report_data($user, $month);
            if (!user_can($me, 'absences.request') && !user_can($me, 'absences.manage')) {
                $rep['vacation'] = null;
            }
            $closing = month_closing($target, $month);
            api_ok(['report' => $rep, 'employee' => ['id' => (int)$user['id'], 'name' => $user['full_name'], 'weekly_hours' => (float)$user['weekly_hours'],
                'work_days' => user_workdays($user), 'day_hours' => user_day_hours($user)],
                'closed' => $closing !== null, 'closed_at' => $closing['closed_at'] ?? null, 'closed_by' => $closing['closed_by_name'] ?? null]);

        // ------------------------------------------------ Monatsabschluss
        case 'GET months':
            api_need_any($me, 'hours.own', 'hours.view_all', 'reports.view_all', 'months.close', 'months.close_own');
            $month = api_month();
            api_ok(['month' => $month, 'rows' => months_overview($month, $me), 'can_close_all' => user_can($me, 'months.close')]);

        case 'POST months/close':
            month_close($me, (int)($b['user_id'] ?? 0), (string)($b['month'] ?? ''));
            api_ok();

        case 'POST months/reopen':
            month_reopen($me, (int)($b['user_id'] ?? 0), (string)($b['month'] ?? ''));
            api_ok();

        case 'POST months/close_all':
            require_perm($me, 'months.close');
            $n = 0;
            foreach (months_overview((string)($b['month'] ?? ''), $me) as $r) {
                if (!$r['closed'] && $r['can_close']) {
                    month_close($me, $r['user_id'], (string)$b['month']);
                    $n++;
                }
            }
            api_ok(['closed' => $n]);

        // ------------------------------------------------ Abwesenheiten & Urlaubsplaner
        case 'GET absences':
            api_need_any($me, 'absences.request', 'absences.manage');
            $manage = user_can($me, 'absences.manage');
            $rows = $manage
                ? q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id ORDER BY a.date_from DESC LIMIT 300')
                : q_all('SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.date_from DESC', [$uid]);
            api_ok([
                'absences' => array_map(fn($a) => ['id' => (int)$a['id'], 'user_id' => (int)$a['user_id'], 'user_name' => $a['full_name'], 'type' => $a['type'],
                    'date_from' => $a['date_from'], 'date_to' => $a['date_to'], 'hours' => $a['hours'] !== null ? (float)$a['hours'] : null,
                    'note' => $a['note'], 'status' => $a['status'],
                    'deletable' => $manage || ((int)$a['user_id'] === $uid && $a['status'] === 'pending')], $rows),
                'vacation' => user_tracks($me) ? vacation_summary($me, (int)($_GET['year'] ?? date('Y'))) : null,
                'types' => ABSENCE_TYPES, 'can_manage' => $manage,
            ]);

        case 'POST absences/create':
            api_ok(['id' => absence_create($me, $b)]);

        case 'POST absences/status':
            absence_set_status($me, (int)($b['id'] ?? 0), (string)($b['status'] ?? ''));
            api_ok();

        case 'POST absences/delete':
            absence_delete($me, (int)($b['id'] ?? 0));
            api_ok();

        case 'GET calendar':
            api_need_any($me, 'schedule.view', 'absences.request', 'absences.manage');
            api_ok(['calendar' => absence_calendar_data(api_month(), $me), 'types' => ABSENCE_TYPES]);

        // ------------------------------------------------ Dienstplan
        case 'GET schedule':
            require_perm($me, 'schedule.view');
            $data = schedule_week_data(api_date('week', $today), $me);
            $biz = [];
            foreach ($data['days'] as $d) {
                $biz[$d] = business_day($d);
            }
            api_ok(['schedule' => $data, 'business' => $biz, 'can_edit' => user_can($me, 'schedule.edit'), 'see_all_actual' => user_can($me, 'hours.view_all')]);

        case 'POST shifts/save':
            api_ok(shift_save($me, $b));

        case 'POST shifts/delete':
            shift_delete($me, (int)($b['id'] ?? 0));
            api_ok();

        case 'POST shifts/copy_week':
            [$ws, $we] = week_bounds(valid_date((string)($b['week'] ?? '')) ? $b['week'] : $today);
            api_ok(['copied' => shifts_copy_prev($me, $ws, $we)]);

        // ------------------------------------------------ Mitarbeiter, Überstunden, Rollen
        case 'GET users':
            api_need_any($me, 'users.view', 'users.manage');
            api_ok(['users' => array_map('user_overview', q_all('SELECT * FROM users ORDER BY active DESC, full_name')), 'can_manage' => user_can($me, 'users.manage')]);

        case 'POST users/save':
            api_ok(['id' => user_save($me, $b)]);

        case 'POST overtime/adjust':
            overtime_adjust($me, (int)($b['user_id'] ?? 0), (string)($b['date'] ?? ''),
                (int)round((float)str_replace(',', '.', (string)($b['hours'] ?? '0')) * 60), (string)($b['note'] ?? ''));
            api_ok();

        case 'GET roles':
            api_need_any($me, 'roles.manage', 'users.view', 'users.manage');
            api_ok(['roles' => roles_overview(), 'permissions' => PERMISSIONS, 'can_manage' => user_can($me, 'roles.manage')]);

        case 'POST roles/save':
            api_ok(['id' => role_save($me, $b)]);

        case 'POST roles/delete':
            role_delete($me, (int)($b['id'] ?? 0));
            api_ok();

        // ------------------------------------------------ Betrieb
        case 'GET business':
            require_perm($me, 'business.manage');
            api_ok(['business' => business_overview(max(2000, min(2100, (int)($_GET['year'] ?? date('Y')))))]);

        case 'POST business/hours':
            business_save_hours($me, ['open' => (array)($b['open'] ?? []), 'from' => (array)($b['from'] ?? []), 'to' => (array)($b['to'] ?? [])]);
            api_ok();

        case 'POST business/special_save':
            special_day_save($me, $b);
            api_ok();

        case 'POST business/special_delete':
            special_day_delete($me, (string)($b['date'] ?? ''));
            api_ok();

        // ------------------------------------------------ Updates des Webpanels
        case 'GET updates':
            require_perm($me, 'updates.manage');
            api_ok(['updates' => updates_overview()]);

        case 'POST updates/settings':
            updates_save_settings($me, (string)($b['panel_update_mode'] ?? ''));
            api_ok();

        case 'POST updates/panel_check':
            require_perm($me, 'updates.manage');
            try {
                api_ok(['latest' => panel_check_remote()]);
            } catch (RuntimeException $ex) {
                throw new DomainException($ex->getMessage());
            }

        case 'POST updates/panel_update':
            require_perm($me, 'updates.manage');
            api_ok(['result' => panel_update()]);

        case 'POST updates/panel_restore':
            require_perm($me, 'updates.manage');
            try {
                api_ok(['result' => panel_restore((string)($b['name'] ?? ''))]);
            } catch (RuntimeException $ex) {
                throw new DomainException($ex->getMessage());
            }
    }
}

/** Zeiteintrag für die App (Zeiten ohne Datenbank-Interna). */
function api_entry(array $me, array $r): array
{
    return [
        'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'user_name' => $r['full_name'],
        'date' => substr($r['start_time'], 0, 10), 'start' => substr($r['start_time'], 11, 5),
        'end' => $r['end_time'] ? substr($r['end_time'], 11, 5) : null,
        'break_min' => (int)$r['manual_break_min'], 'worked_seconds' => (int)$r['worked_sec'],
        'note' => $r['note'], 'editable' => entry_editable($me, $r),
    ];
}
