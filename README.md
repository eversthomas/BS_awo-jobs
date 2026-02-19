# BS AWO Jobs

WordPress-Plugin zur Anzeige der AWO-Stellenbörse: JSON-API synchronisieren und Stellen per Shortcode mit konfigurierbarem Design ausgeben.

## Was das Plugin macht

- **Backend (AWO Jobs):** JSON-API-URL speichern, Fachbereich-Quelle (API oder Mandantenfeld) wählen, manuell synchronisieren. Darunter erscheint eine tabellarische Liste aller aktuellen Stellenangebote.
- **Backend (Frontend):** Design und Anzeige-Optionen (Farben, Layout, sichtbare Felder, Bewerbungslink) konfigurieren sowie Shortcode-Beispiele und -Dokumentation.
- **Frontend:** Shortcode `[bs_awo_jobs]` mit Filtern (Ort, Fachbereich, Beruf, Vertragsart), Layout Liste/Kacheln, Pagination, Detailansicht. Zusätzliche Shortcodes: `[bs_awo_jobs_kita]`, `[bs_awo_jobs_pflege]`, `[bs_awo_jobs_verwaltung]`. AJAX-Filter ohne kompletten Seiten-Reload. Dynamische Styles aus den Backend-Einstellungen.

## Systemvoraussetzungen

- WordPress (getestet mit 6.x)
- PHP 7.4+
- MySQL 5.7+ oder MariaDB 10.2+

## Installation

1. Plugin-Ordner nach `wp-content/plugins/BS_awo-jobs/` legen.
2. Unter **Plugins → Installierte Plugins** das Plugin **„BS AWO Jobs“** aktivieren.
3. Bei Aktivierung werden die Tabellen `{prefix}bsawo_runs` und `{prefix}bsawo_jobs_current` angelegt.

**Multisite:** Bei Netzwerkaktivierung wird die Aktivierung pro Blog ausgeführt; jede Site erhält ihre eigenen Tabellen mit dem jeweiligen Tabellenpräfix.

## Nutzung

1. **AWO Jobs** (Hauptmenü): JSON-API-URL eintragen (z. B. `https://www.awo-jobs.de/stellenboerse-wesel.json`), Einstellungen speichern, dann **„Jetzt synchronisieren“** ausführen. Die Tabelle darunter zeigt alle aktuellen Stellen.
2. **Frontend** (Untermenü): Design und Anzeige-Optionen anpassen, Shortcodes auf der Seite nachlesen und in Seiten/Beiträge einfügen.
3. In einer Seite oder einem Beitrag den Shortcode einfügen, z. B. `[bs_awo_jobs]` oder `[bs_awo_jobs fachbereich="Pflege" layout="grid"]`.

## Deinstallation

Beim **Löschen** des Plugins (nicht nur Deaktivieren) unter Plugins werden alle zugehörigen Optionen, Transients und Datenbanktabellen automatisch entfernt.
