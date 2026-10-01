<?php
declare(strict_types=1);

/**
 * Rechte-System. Jeder Benutzer hat genau eine Rolle; eine Rolle ist eine Liste von Rechten.
 * Rolle „Vollzugriff“ (permissions = '*') darf alles – auch Rechte, die erst in künftigen Versionen dazukommen.
 */

const PERMISSIONS = [
    'hours.own'         => 'Eigene Stunden ansehen, eintragen und auswerten',
    'schedule.view'     => 'Dienstplan ansehen',
    'absences.request'  => 'Eigene Abwesenheiten/Urlaub beantragen',
    'hours.view_all'    => 'Stunden und Anwesenheit aller Mitarbeiter ansehen',
    'hours.edit_all'    => 'Stunden aller Mitarbeiter eintragen, ändern und löschen',
    'reports.view_all'  => 'Auswertungen aller Mitarbeiter ansehen',
    'schedule.edit'     => 'Dienstplan bearbeiten (Mitarbeiter einteilen)',
    'absences.manage'   => 'Abwesenheiten aller verwalten und genehmigen',
    'overtime.manage'   => 'Überstunden buchen (Auszahlung/Korrektur)',
    'months.close'      => 'Monate abschließen und wieder öffnen (alle Mitarbeiter)',
    'business.manage'   => 'Öffnungszeiten, Feiertage und Schließtage verwalten',
    'months.close_own'  => 'Eigene Monate abschließen',
    'users.view'        => 'Mitarbeiter ansehen',
    'users.manage'      => 'Mitarbeiter anlegen und bearbeiten',
    'roles.manage'      => 'Rollen und Rechte verwalten',
    'updates.manage'    => 'Updates verwalten',
];

/** Rechte einer Rolle (Liste von Schlüsseln). */
function role_permissions(string $stored): array
{
    if (trim($stored) === '*') {
        return array_keys(PERMISSIONS);
    }
    return array_values(array_intersect(array_map('trim', explode(',', $stored)), array_keys(PERMISSIONS)));
}

/** Rolle des Benutzers (Zeile aus roles). Ältere Datensätze ohne role_id: admin = Vollzugriff, sonst Mitarbeiter. */
function user_role(array $u): array
{
    static $cache = [];
    $rid = (int)($u['role_id'] ?? 0);
    if ($rid === 0) {
        $rid = -((($u['role'] ?? 'employee') === 'admin') ? 1 : 2);
    }
    if (!isset($cache[$rid])) {
        $row = $rid > 0 ? q_one('SELECT * FROM roles WHERE id = ?', [$rid]) : null;
        $cache[$rid] = $row ?? ($rid === -1
            ? ['id' => 0, 'name' => 'Vollzugriff', 'permissions' => '*', 'is_system' => 1]
            : ['id' => 0, 'name' => 'Mitarbeiter', 'permissions' => 'hours.own,schedule.view,absences.request', 'is_system' => 1]);
    }
    return $cache[$rid];
}

function user_permissions(array $u): array
{
    return role_permissions((string)user_role($u)['permissions']);
}

function user_can(array $u, string $perm): bool
{
    return in_array($perm, user_permissions($u), true);
}

function user_is_full(array $u): bool
{
    return trim((string)user_role($u)['permissions']) === '*';
}

/** Fehlendes Recht (Web: Meldung; API: HTTP 403). */
class PermissionException extends DomainException
{
}
