# Zeiterfassung

Webanwendung (PHP 8.1+, MySQL/MariaDB) für einen Betrieb mit mehreren Mitarbeitern, die an unterschiedlichen Tagen unterschiedlich lange arbeiten.

## Was kann sie?

- **Stunden manuell eintragen** (kein Ein-/Ausstempeln): Datum, Von, Bis, Pause, Notiz. Überschneidungen und Zukunft werden abgelehnt; an geschlossenen Tagen und Feiertagen kann man trotzdem jederzeit Zeiten eintragen.
- **Arbeitszeitmodell je Mitarbeiter:** Stunden pro Wochentag (z. B. Mo 8 h, Di 6 h, Do 4 h) → daraus ergeben sich Soll, Wochenstunden und Urlaubstage.
- **Plus-/Minusstunden:** Jeder Mitarbeiter sieht sein Stundenkonto (Ist − Soll seit Eintritt, plus Buchungen wie Auszahlungen). Zeitausgleich mindert es automatisch.
- **Urlaub:** 5 Wochen pro Jahr automatisch (= 5 × Arbeitstage pro Woche, immer in voller Höhe), pro Mitarbeiter überschreibbar, mit Resturlaub-Übertrag. Beantragen/Genehmigen, Krankenstand, Arzt (ganz- oder teiltägig), Zeitausgleich. **Urlaubsplaner** als Monatskalender.
- **Dienstplan:** Mitarbeiter je Woche in Schichten einteilen (Soll) – daneben die **Anwesenheit (Ist)** aus den eingetragenen Zeiten, mit Abweichungen (verspätet, zu kurz, nichts eingetragen, ungeplant).
- **Öffnungszeiten:** Mo–Fr 07:00–18:30, Sa 07:30–13:00, So geschlossen (unter *Betrieb* änderbar). **Österreichische Feiertage sind automatisch geschlossen**; einzelne Feiertage lassen sich als „geöffnet“ markieren, eigene Schließ- oder Öffnungstage (Betriebsurlaub, Brückentag …) selbst definieren.
- **Monatsabschluss:** Die Arbeitszeit des Monats wird laufend automatisch zusammengezählt. Ab dem letzten Tag lässt sich der Monat je Mitarbeiter (oder für alle) abschließen; danach sind die Stunden gesperrt, nur die Verwaltung kann ihn wieder öffnen.
- **Rollen & Rechte:** Jede Funktion ist ein einzelnes Recht. Die Rolle **Vollzugriff** darf alles (auch künftige Funktionen), die Rolle **Mitarbeiter** sieht nur die eigenen Stunden, den Dienstplan, den Urlaubsplaner und die eigenen Abwesenheiten. Eigene Rollen (z. B. „Schichtleiter“) frei zusammenstellbar.
- **Login mit Personalnummer.** Start-/Standardpasswörter müssen beim ersten Login geändert werden.
- **Auswertung:** Monatsübersicht je Tag mit Soll/Ist/Saldo, CSV-Export.

## Einrichtung

1. Repository auf dem Server klonen: `git clone https://github.com/Christian445123/Zeiterfassungssystem.git .` (in das Web-Verzeichnis; bei privatem Repo mit Deploy-Key oder Token). In der `.env` danach `GITHUB_REPO=Christian445123/Zeiterfassungssystem` eintragen.
2. Leere Datenbank anlegen, `.env.example` nach `.env` kopieren und ausfüllen (`DB_*`).
3. Im Browser `install.php` aufrufen → legt alle Tabellen an und den Standard-Login **Personalnummer `1000` / Passwort `ChangeMe123!`** (muss beim ersten Login geändert werden). Danach sperrt sich `install.php` selbst.
4. Unter *Mitarbeiter* die Mitarbeiter mit Arbeitszeitmodell anlegen, unter *Betrieb* die Öffnungszeiten prüfen.

Voraussetzungen: PHP ≥ 8.1 mit `pdo_mysql`, `mbstring`; HTTPS empfohlen. Die `.htaccess` sperrt `.env`, `.git` und `storage/` (Apache) – bei nginx diese Pfade selbst sperren.

## Konfiguration (`.env`)

| Schlüssel | Bedeutung |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Datenbank |
| `TIMEZONE`, `APP_NAME` | Zeitzone (Standard Europe/Vienna), Anzeigename |
| `VACATION_WEEKS` | Urlaubswochen pro Jahr (Standard 5) |
| `ENTRY_EDIT_DAYS` | So viele Tage dürfen Mitarbeiter eigene Einträge rückwirkend ändern (Standard 31) |
| `HOLIDAYS` | `AT` (Standard) oder `NONE` |
| `GITHUB_REPO`, `GITHUB_BRANCH`, `GITHUB_TOKEN` | Quelle für Updates (Token nur bei privatem Repository) |
| `DEPLOY_WEBHOOK_SECRET` | aktiviert den Auto-Update-Webhook |
| `APP_DEBUG` | `1` zeigt Fehlerdetails (nur zum Debuggen) |

## Updates aus GitHub

Unter **Updates** (Recht *Updates verwalten*): *Auf Updates prüfen* und *Jetzt aktualisieren*.

- Ist der Server ein Git-Checkout, wird `git pull --ff-only` ausgeführt (bricht bei lokalen Änderungen ab, ändert nichts). Ohne Git lädt das Panel das ZIP des Branches von GitHub, legt vorher ein Backup an (letzte 5, mit „Wiederherstellen“) und ersetzt die Dateien. `.env` und `storage/` bleiben unberührt.
- Neue Datenbank-Änderungen (`migrations/`) laufen danach automatisch.
- **Automatisch nach jedem Push:** `DEPLOY_WEBHOOK_SECRET` setzen und auf GitHub einen Webhook auf `https://<domain>/deploy-webhook.php` einrichten (Content type `application/json`, Event „push“, gleiches Secret).

## Entwicklung

- Neue Tabellen/Spalten: Datei `migrations/00N_name.php` mit einer Liste von SQL-Statements bzw. Closures anlegen (müssen idempotent sein).
- Geschäftslogik: `lib/hr.php` (Soll, Feiertage, Urlaub, Überstunden, Dienstplan), `lib/admin.php` und `lib/business.php` (schreibende Funktionen mit Rechteprüfung), `lib/rbac.php` (Rechte-Katalog).

## Hinweise

- Urlaubstage und Soll berücksichtigen nur Arbeitstage laut Arbeitszeitmodell; geschlossene Tage (Feiertage, Schließtage) verbrauchen weder Soll noch Urlaub.
- Das Stundenkonto zählt ab dem Eintrittsdatum (Standard: Datum der Anlage) – fehlende Eintragungen erscheinen als Minusstunden.
