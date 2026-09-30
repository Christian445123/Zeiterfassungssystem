# Zeiterfassungssystem

- `webpanel/` – PHP 8 + MySQL Webpanel und REST-API
- `client/` – C# (.NET 8, WinForms) Stempeluhr-Programm

## Webpanel einrichten
1. `webpanel/` auf den Webserver (PHP ≥ 8.1, MySQL/MariaDB, HTTPS empfohlen) laden.
2. Leere Datenbank anlegen, Zugang in `webpanel/.env` eintragen (Vorlage: `.env.example`, Zeitzone prüfen).
3. `install.php` im Browser öffnen → Admin anlegen. Angezeigt werden **Lizenzschlüssel** und **API-Key** (nur einmal!). Danach `install.php` löschen.
4. Login unter `index.php` (öffentliche Startseite mit Login). Weitere Mitarbeiter unter *Mitarbeiter*, Projekte unter *Projekte*.

## Lizenz- & API-Key-System
- **Lizenz** (Panel → Lizenzen & API): Kunde, max. Geräte, Ablaufdatum, aktiv/deaktiviert.
- **API-Key**: gehört zu einer Lizenz, wird nur als SHA-256-Hash gespeichert, kann gesperrt/gelöscht werden.
- **Geräte-Bindung**: Beim ersten Start aktiviert der Client mit API-Key + Lizenzschlüssel das Gerät (Geräte-ID = Hash der Windows-MachineGuid). Geräte lassen sich im Panel entfernen.
- Jede API-Anfrage prüft: API-Key gültig → Lizenz aktiv & nicht abgelaufen → Gerät aktiviert → Benutzer-Token gültig.

## Client bauen
```
cd client
dotnet publish -c Release -r win-x64 --self-contained false
```
Beim ersten Start: Server-URL (vorbelegt: `https://zeiterfassung.gamingcommunity.at`), API-Key, Lizenzschlüssel → danach Benutzer-Login.
Einstellungen liegen in `%AppData%\Zeiterfassung\settings.json` (API-Key/Token DPAPI-verschlüsselt).

## API (`api/index.php?route=…`)
Header: `X-Api-Key`, `X-Machine-Id`, `X-Auth-Token`

| Methode | Route | Zweck |
|---|---|---|
| POST | license/activate | Gerät aktivieren `{license_key, machine_name}` |
| GET | license/status | Lizenz prüfen |
| POST | auth/login / auth/logout | Anmeldung `{username,password}` → Token |
| GET | status | Stempelstatus, Heute/Woche |
| GET | projects | Projektliste |
| POST | clock/in `{project_id,note}` / clock/out | Kommen / Gehen |
| POST | break/start / break/end | Pause |
| GET | entries `?from&to` | Eigene Zeiteinträge |

## Funktionen
Kommen/Gehen/Pause (Web + Client), Projekte, Notizen, manuelle Korrekturen (Admin), Monatsauswertung mit Soll/Ist/Saldo und CSV-Export, Abwesenheiten (Urlaub/Krank, Antrag + Genehmigung), Live-Übersicht wer eingestempelt ist, Mitarbeiter- und Rollenverwaltung.

## Nicht enthalten / Hinweise
- Feiertage werden nicht automatisch berücksichtigt; kein Offline-Modus im Client (Stempeln braucht Verbindung).
- Der Code wurde nicht gegen einen laufenden PHP-/MySQL-Server getestet (auf dieser Maschine ist kein PHP installiert) – der C#-Client baut fehlerfrei.
