# BS AWO Jobs

**Version 2.0**

WordPress-Plugin zur Anzeige der AWO-Stellenbörse: JSON-API synchronisieren und Stellen per Shortcode mit konfigurierbarem Design ausgeben.

## Was das Plugin macht

- **Backend (AWO Jobs):** JSON-API-URL speichern, Fachbereich-Quelle (API oder Mandantenfeld) wählen, optional automatische Synchronisierung per WP-Cron einstellen, manuell synchronisieren. Darunter erscheint eine tabellarische Liste aller aktuellen Stellenangebote.
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

1. **AWO Jobs** (Hauptmenü): JSON-API-URL eintragen (z. B. `https://www.awo-jobs.de/stellenboerse-XYZ.json`), Einstellungen speichern, dann **„Jetzt synchronisieren“** ausführen. Die Tabelle darunter zeigt alle aktuellen Stellen.
2. **Frontend** (Untermenü): Design und Anzeige-Optionen anpassen, Shortcodes auf der Seite nachlesen und in Seiten/Beiträge einfügen.
3. In einer Seite oder einem Beitrag den Shortcode einfügen, z. B. `[bs_awo_jobs]` oder `[bs_awo_jobs fachbereich="Pflege" layout="grid"]`.

## Automatische Synchronisierung (WP-Cron)

Das Plugin kann die Stellenanzeigen automatisch über den **WordPress-Cron (WP-Cron)** aktualisieren. WP-Cron ist kein echter Server-Cron, sondern wird in der Regel bei Seitenaufrufen ausgelöst. Auf Websites mit regelmäßigen Zugriffen ist das in der Praxis meist ausreichend.

- **Einstellung:** Unter **AWO Jobs → API & Sync** im Dropdown „Automatischer Sync“ z. B. **Alle 12 Stunden** oder **Täglich** wählen und Einstellungen speichern.
- **Empfehlung:** Synchronisierung auf **2× täglich** (alle 12 Stunden) stellen.
- **Wenig Zugriffe:** Wenn die Website nur selten besucht wird, kann WP-Cron seltener laufen. Dann den Sync regelmäßig manuell im Backend ausführen oder einen echten Server-Cron einrichten, der WP-Cron zuverlässig anstößt (z. B. `wp-cron.php` per Cron-Job aufrufen).
- Im Backend ist jederzeit sichtbar, wann der letzte Sync gelaufen ist und ob er erfolgreich war.

## Troubleshooting

| Problem | Mögliche Ursache | Lösung |
|--------|------------------|--------|
| **Sync schlägt fehl: „Fehler beim Abruf der API“** | API der Stellenbörse nicht erreichbar oder down | URL im Backend prüfen (HTTPS), ggf. vom Anbieter prüfen lassen. Temporär: alte Daten bleiben sichtbar. |
| **Automatischer Sync läuft nicht / nur bei Besuch** | WP-Cron wird nur bei Seitenaufrufen ausgelöst (Low-Traffic) | Sync manuell im Backend ausführen oder einen echten Server-Cron einrichten, der z. B. `https://deine-site.de/wp-cron.php?doing_wp_cron` regelmäßig aufruft. |
| **„Sync wird bereits ausgeführt …“ obwohl kein Sync läuft** | Lock-Transient hängt (z. B. nach Abbruch oder Crash) | Lock läuft nach 10 Minuten automatisch ab. Sofort-Lösung: Plugin kurz deaktivieren und wieder aktivieren (entfernt den Lock). |
| **„RENAME TABLE fehlgeschlagen (DB-Rechte?)“** | Datenbank-Benutzer hat keine Rechte für `RENAME TABLE` | Beim Hoster prüfen, ob der DB-User `ALTER`/`RENAME` auf die Tabellen hat. Bis dahin: aktuelle Daten bleiben unverändert; Sync schlägt fehl und meldet den Fehler im Backend. |

## Tests

Unit-Tests (PHPUnit) für z. B. Normalizer und Sync-Logik:

```bash
composer install
./vendor/bin/phpunit
```

## Übersetzungen

Die Text-Domain ist `bs-awo-jobs`. Im Ordner `languages/` liegt eine Vorlage `bs-awo-jobs.pot`. Für eine vollständige Aktualisierung der Übersetzungsvorlage (z. B. nach Code-Änderungen) kann [WP-CLI](https://wp-cli.org/) genutzt werden:

```bash
wp i18n make-pot wp-content/plugins/BS_awo-jobs wp-content/plugins/BS_awo-jobs/languages/bs-awo-jobs.pot --domain=bs-awo-jobs
```

Sprachdateien (z. B. `bs-awo-jobs-de_DE.po` / `.mo`) in denselben Ordner legen; WordPress lädt sie automatisch.

## Release-ZIP / Distribution

Für ein sauberes Release-ZIP (ohne `__MACOSX/`, `.DS_Store` usw.) wird `.gitattributes` mit `export-ignore` genutzt. Beim Archivieren mit `git archive` oder beim Export über GitHub Releases werden diese Dateien/Ordner automatisch ausgeschlossen.

## Deinstallation

Beim **Löschen** des Plugins (nicht nur Deaktivieren) unter Plugins werden alle zugehörigen Optionen, Transients und Datenbanktabellen automatisch entfernt.
