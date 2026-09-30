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

## Updates (Client + Webpanel)
Alle Einstellungen: Panel → **Updates** (nur Admins).

**Modi** (jeweils Aus / Nur benachrichtigen / Automatisch installieren):
- *Webpanel* – im Panel einstellbar. „Automatisch“ prüft täglich; per Cron `0 4 * * * php /pfad/webpanel/cron_update.php`, ohne Cron beim Öffnen des Dashboards durch einen Admin.
- *Desktop-Client* – Vorgabe im Panel, jeder Client kann sie unter *Konto → Einstellungen…* überschreiben („Vorgabe des Servers“ ist Standard). **Pflicht-Updates** werden immer installiert. Der Client prüft beim Start und alle 6 Stunden.

**Neue Version veröffentlichen**
1. Version hochzählen: `<Version>` in `client/Zeiterfassung.csproj` und/oder `webpanel/VERSION`.
2. `powershell -File tools/build-release.ps1 -BaseUrl https://updates.example.com/zeiterfassung -Notes "Was ist neu"` → erzeugt in `dist/`:
   - `zeiterfassung-client-x.y.z.zip` → im Panel unter *Updates → Desktop-Anwendung* hochladen (Clients laden es über die API, nur mit gültiger Lizenz/API-Key/Gerät).
   - `webpanel-x.y.z.zip` + `manifest.json` → auf einen HTTPS-Server legen und die Manifest-URL als `PANEL_UPDATE_URL` in die `.env` eintragen. Alternativ das Panel-ZIP unter *Updates → Webpanel* direkt hochladen.

**Ablauf & Sicherheit**
- Panel: SHA-256 prüfen → automatisches Backup (letzte 5, mit „Wiederherstellen“) → Dateien ersetzen (`.env`, `storage/`, `install.php` bleiben unberührt) → Datenbank-Migrationen aus `webpanel/migrations/` laufen automatisch. Schlägt das Einspielen fehl, wird der alte Stand zurückgespielt.
- Client: Download → SHA-256 gegen Server-Wert prüfen → Updater-Kopie ersetzt Dateien nach dem Beenden, bei Fehler Rollback → Neustart. Die Zeit läuft serverseitig weiter, ein Neustart unterbricht sie nicht.
- Neue DB-Änderungen: Datei `webpanel/migrations/002_….php` mit einem Array idempotenter SQL-Statements anlegen.
- Voraussetzungen: Panel-Ordner für PHP beschreibbar, PHP-Erweiterung `zip`; Client muss in einem beschreibbaren Ordner liegen (nicht *Program Files*), z. B. `%LocalAppData%\Zeiterfassung`.
- Grenzen: Die Prüfsumme schützt vor beschädigten Downloads, nicht vor einem kompromittierten Server (keine Code-Signatur). Gelöschte Dateien früherer Versionen bleiben liegen. Bei nginx muss `storage/` selbst gesperrt werden (die `.htaccess` gilt nur für Apache).
