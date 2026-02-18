# BS AWO Jobs – AWO Jobs Analytics

## Überblick

**BS AWO Jobs** ist ein WordPress-Plugin, das Stellenangebote aus der AWO-Stellenbörse als JSON-Snapshot einliest, in eigenen Tabellen speichert und für Analyse sowie Frontend-Anzeige bereitstellt.

**Funktionsumfang:**

- **Backend:** Einstellungen (API-URL, Fachbereich-Quelle), manueller Sync, Schema Inspector, Event-Log (created/modified/offlined), Statistiken inkl. Fluktuationsanalyse, Backup (Export/Import), Frontend-Konfiguration (Design, Anzeige, Shortcode-Dokumentation).
- **Daten:** Eigene Tabellen `bsawo_runs`, `bsawo_jobs_current`, `bsawo_events`, `bsawo_facilities`; Run-Datenintegrität bei mehreren Syncs pro Tag (INSERT … ON DUPLICATE KEY UPDATE).
- **Frontend:** Shortcode `[bs_awo_jobs]` mit Filtern (Ort, Fachbereich, Beruf, Vertragsart), Layout (Liste/Kacheln), Pagination, Detailansicht; vordefinierte Shortcodes `[bs_awo_jobs_kita]`, `[bs_awo_jobs_pflege]`, `[bs_awo_jobs_verwaltung]`; AJAX-Filter (ohne voller Reload), Lade-Spinner; dynamische Styles aus Backend-Einstellungen.
- **Technik:** PSR-4-Autoloading, Cron für täglichen Sync, Transients für Filter-Optionen, i18n-ready (Text Domain: bs-awo-jobs).

## Systemvoraussetzungen

- **WordPress:** aktuelle Version (getestet mit 6.x)
- **PHP:** 7.4 oder höher
- **Datenbank:** MySQL 5.7.8+ oder MariaDB 10.2.7+ (für JSON-Spalten in `bsawo_events`)

Für den Einsatz in professionellen Umgebungen (z. B. Verbände) wird eine halbwegs aktuelle Infrastruktur vorausgesetzt; ältere Datenbankversionen (z. B. MySQL 5.6, MariaDB 10.1) werden nicht unterstützt und gelten aus Sicherheitsgründen als veraltet.

## Installation

1. Plugin-Ordner nach `wp-content/plugins/BS_awo-jobs/` legen.
2. Im Backend unter **Plugins → Installierte Plugins** das Plugin **„BS AWO Jobs“** aktivieren.
3. Bei Aktivierung werden die Tabellen `{prefix}bsawo_runs`, `{prefix}bsawo_jobs_current`, `{prefix}bsawo_events`, `{prefix}bsawo_facilities` angelegt.

## Nutzung

### Backend

- **AWO Jobs – Einstellungen:** API-URL (nur HTTPS), Fachbereich-Quelle (API vs. Mandantenfeld), **„Jetzt synchronisieren“** (manueller Sync), Backup Export/Import (JSON, mit MIME- und Größenprüfung).
- **Schema Inspector:** Feldanalyse, Eindeutigkeit Stellennummer, Fachbereich-/Stellenbezeichnung-IDs.
- **Events:** Event-Log (created, modified, offlined) mit Datum, Run, Job-ID.
- **Statistiken:** Überblick, Fluktuation (Zeitraum, Einrichtung, Fachbereich, Jobfamilie).
- **Frontend:** Konfiguration Design/Anzeige, Shortcode-Dokumentation.

### Frontend

- **`[bs_awo_jobs]`** – Stellenliste mit Filtern (Ort, Fachbereich, Beruf, Vertragsart), Layout-Umschalter (Liste/Kacheln), Pagination; Filter per AJAX (ohne voller Reload), Anker `#bs-awo-jobs` bei Reload.
- **`[bs_awo_jobs fachbereich="…" ort="…"]`** – Filter per Shortcode-Attribute; bei API-Fachbereich: Bezeichnung oder numerische ID.
- **`[bs_awo_jobs_kita]`, `[bs_awo_jobs_pflege]`, `[bs_awo_jobs_verwaltung]`** – vordefinierte Fachbereich-Shortcodes (Bezeichnung aus API/Mandantenfeld).

Dynamische Styles (Farben, Karten, Buttons) aus dem Backend werden auf allen Shortcode-Seiten ausgegeben.

## Sicherheit & Best Practices

- Admin-Aktionen prüfen `current_user_can('manage_options')` und Nonces (Einstellungen, Sync, Import, Export, Force Resync).
- Datenbank: `$wpdb->prepare()` bzw. Format-Arrays, einheitliches Escaping (Backticks bei TRUNCATE).
- Ausgaben: `esc_html()`, `esc_attr()`, `esc_url()`; API-URL nur HTTPS.
- Import: MIME-Prüfung (application/json, text/plain), max. 50 MB.

## Nächste Schritte (optional)

- Dokumentation (README/DEVLOG) weiterpflegen.
- Code-Qualität: Tests, Type-Hints, DocBlocks (Backlog).
