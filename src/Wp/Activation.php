<?php

namespace BsAwoJobs\Wp;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

class Activation
{
    /**
     * Führt Aktivierungslogik aus (Tabellen anlegen).
     *
     * @return void
     */
    public static function activate()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $runs_table         = $wpdb->prefix . 'bsawo_runs';
        $jobs_current_table  = $wpdb->prefix . 'bsawo_jobs_current';
        $events_table       = $wpdb->prefix . 'bsawo_events';
        $facilities_table   = $wpdb->prefix . 'bsawo_facilities';
        $stats_table        = $wpdb->prefix . 'bs_awo_stats';

        $sql_runs = "CREATE TABLE {$runs_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_date DATE NOT NULL,
            run_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            snapshot_checksum CHAR(64) NOT NULL,
            snapshot_json LONGTEXT,
            jobs_count INT UNSIGNED,
            status ENUM('success', 'failed') DEFAULT 'success',
            error_message TEXT,
            UNIQUE KEY idx_run_date (run_date),
            INDEX idx_checksum (snapshot_checksum)
        ) {$charset_collate};";

        $sql_jobs_current = "CREATE TABLE {$jobs_current_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(50) NOT NULL UNIQUE,
            facility_id CHAR(16) NOT NULL,
            facility_name VARCHAR(255),
            facility_address TEXT,
            department_api VARCHAR(100),
            department_api_id VARCHAR(10),
            department_custom VARCHAR(100),
            jobfamily_id VARCHAR(10),
            jobfamily_name VARCHAR(255),
            contract_type VARCHAR(100),
            employment_type VARCHAR(50),
            work_time_model VARCHAR(100),
            is_minijob TINYINT(1) DEFAULT 0,
            created_at INT UNSIGNED,
            modified_at INT UNSIGNED,
            published_at INT UNSIGNED,
            expires_at INT UNSIGNED,
            raw_json LONGTEXT,
            last_seen_run_id BIGINT UNSIGNED,
            INDEX idx_facility (facility_id),
            INDEX idx_dept_api (department_api_id),
            INDEX idx_dept_custom (department_custom),
            INDEX idx_jobfamily (jobfamily_id)
        ) {$charset_collate};";

        $sql_events = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(50) NOT NULL,
            event_type ENUM('created', 'modified', 'offlined') NOT NULL,
            event_date DATE NOT NULL,
            detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            run_id BIGINT UNSIGNED,
            previous_state JSON,
            new_state JSON,
            facility_id CHAR(16) DEFAULT NULL,
            jobfamily_id VARCHAR(10) DEFAULT NULL,
            department_api_id VARCHAR(10) DEFAULT NULL,
            department_custom VARCHAR(100) DEFAULT NULL,
            INDEX idx_job_timeline (job_id, event_date),
            INDEX idx_event_type (event_type, event_date),
            INDEX idx_run (run_id),
            INDEX idx_events_fluktuation (facility_id, jobfamily_id, event_date)
        ) {$charset_collate};";

        $sql_facilities = "CREATE TABLE {$facilities_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            facility_id CHAR(16) NOT NULL,
            canonical_name VARCHAR(255),
            normalized_address TEXT,
            manual_override TINYINT(1) DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_facility_id (facility_id)
        ) {$charset_collate};";

        dbDelta($sql_runs);
        dbDelta($sql_jobs_current);
        dbDelta($sql_events);
        dbDelta($sql_facilities);

        $sql_stats = "CREATE TABLE {$stats_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            s_nr VARCHAR(190) NOT NULL,
            erstellt_am DATETIME NULL,
            start_date DATETIME NULL,
            stop_date DATETIME NULL,
            job_titel TEXT NULL,
            fachbereich_ext TEXT NULL,
            fachbereich_int TEXT NULL,
            vertragsart TEXT NULL,
            anstellungsart TEXT NULL,
            einrichtung TEXT NULL,
            ort TEXT NULL,
            ba_zeiteinteilung_raw TEXT NULL,
            stunden_pro_woche FLOAT NULL,
            stunden_quelle VARCHAR(32) NULL,
            vze_wert FLOAT DEFAULT 0,
            UNIQUE KEY idx_s_nr (s_nr)
        ) {$charset_collate};";

        dbDelta($sql_stats);

        // Neue Installationen starten mit Schema-Version 5 (inkl. Stunden-Spalten in bs_awo_stats).
        update_option('bs_awo_jobs_db_version', 5);
    }

    /**
     * Führt Schema-Upgrades aus (z. B. neue Spalten in bsawo_events).
     * Wird bei plugins_loaded aufgerufen.
     *
     * @return void
     */
    public static function maybe_upgrade()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $version = (int) get_option('bs_awo_jobs_db_version', 1);

        // Upgrade-Pfad von Version 1 -> 2 (bestehende Logik für bsawo_events).
        if ($version < 2) {
            $table = $wpdb->prefix . 'bsawo_events';
            $cols  = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
            if (! empty($cols)) {
                $colNames = [];
                foreach ($cols as $c) {
                    $colNames[] = $c->Field;
                }

                $add = [
                    'facility_id'       => 'ADD COLUMN facility_id CHAR(16) DEFAULT NULL',
                    'jobfamily_id'      => 'ADD COLUMN jobfamily_id VARCHAR(10) DEFAULT NULL',
                    'department_api_id' => 'ADD COLUMN department_api_id VARCHAR(10) DEFAULT NULL',
                    'department_custom'=> 'ADD COLUMN department_custom VARCHAR(100) DEFAULT NULL',
                ];

                foreach ($add as $name => $sql) {
                    if (! in_array($name, $colNames, true)) {
                        $wpdb->query("ALTER TABLE {$table} {$sql}");
                    }
                }
            }

            update_option('bs_awo_jobs_db_version', 2);
            $version = 2;
        }

        // Sicherstellen, dass bsawo_jobs_current die Spalte raw_json hat.
        self::ensure_raw_json_column();

        // Neue Stats-Tabelle für Fluktuations-Analysen ab Version 3.
        if ($version < 3) {
            self::ensure_stats_table();
            update_option('bs_awo_jobs_db_version', 3);
            $version = 3;
        }

        // Ab Version 4: sicherstellen, dass bs_awo_stats die Spalte ba_zeiteinteilung_raw enthält.
        if ($version < 4) {
            self::ensure_stats_table();
            update_option('bs_awo_jobs_db_version', 4);
            $version = 4;
        }

        // Ab Version 5: sicherstellen, dass bs_awo_stats die Stunden-Spalten enthält.
        if ($version < 5) {
            self::ensure_stats_table();
            update_option('bs_awo_jobs_db_version', 5);
        }
    }

    /**
     * Stellt sicher, dass die Tabelle bsawo_jobs_current die Spalte raw_json hat.
     * Nötig, wenn die Tabelle vor Einführung von raw_json angelegt wurde.
     *
     * @return void
     */
    public static function ensure_raw_json_column()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'bsawo_jobs_current';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'raw_json'");
        if (! empty($cols)) {
            return;
        }

        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN raw_json LONGTEXT");
    }

    /**
     * Stellt sicher, dass die Tabelle für Fluktuations-Statistiken existiert.
     *
     * @return void
     */
    public static function ensure_stats_table()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'bs_awo_stats';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql_stats = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            s_nr VARCHAR(190) NOT NULL,
            erstellt_am DATETIME NULL,
            start_date DATETIME NULL,
            stop_date DATETIME NULL,
            job_titel TEXT NULL,
            fachbereich_ext TEXT NULL,
            fachbereich_int TEXT NULL,
            vertragsart TEXT NULL,
            anstellungsart TEXT NULL,
            einrichtung TEXT NULL,
            ort TEXT NULL,
            ba_zeiteinteilung_raw TEXT NULL,
            stunden_pro_woche FLOAT NULL,
            stunden_quelle VARCHAR(32) NULL,
            vze_wert FLOAT DEFAULT 0,
            UNIQUE KEY idx_s_nr (s_nr)
        ) {$charset_collate};";

        // dbDelta legt die Tabelle an, falls sie fehlt, und ergänzt fehlende Spalten in bestehenden Tabellen.
        dbDelta($sql_stats);
    }
}

