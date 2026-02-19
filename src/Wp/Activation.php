<?php

namespace BsAwoJobs\Wp;

use wpdb;

if (! defined('ABSPATH')) {
    exit;
}

class Activation
{
    /**
     * Führt Aktivierungslogik aus (Tabellen bsawo_runs, bsawo_jobs_current anlegen).
     * Bei Multisite-Netzwerkaktivierung wird diese Methode pro Blog aufgerufen;
     * Tabellen werden mit dem jeweiligen $wpdb->prefix angelegt.
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

        $runs_table        = $wpdb->prefix . 'bsawo_runs';
        $jobs_current_table = $wpdb->prefix . 'bsawo_jobs_current';

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
            plz_einsatzort VARCHAR(20),
            strasse_einsatzort VARCHAR(255),
            einsatzort TEXT,
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
            INDEX idx_jobfamily (jobfamily_id),
            INDEX idx_contract_type (contract_type),
            INDEX idx_einsatzort (einsatzort(100))
        ) {$charset_collate};";

        dbDelta($sql_runs);
        dbDelta($sql_jobs_current);

        self::ensure_jobs_staging_table();
        update_option('bs_awo_jobs_db_version', 3);
    }

    /**
     * Stellt sicher, dass die Staging-Tabelle für atomaren Swap existiert (gleiche Struktur wie bsawo_jobs_current).
     *
     * @return void
     */
    public static function ensure_jobs_staging_table()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $current = $wpdb->prefix . 'bsawo_jobs_current';
        $staging = $wpdb->prefix . 'bsawo_jobs_staging';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $current)) !== $current) {
            return;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $staging)) === $staging) {
            return;
        }

        $wpdb->query("CREATE TABLE `{$staging}` LIKE `{$current}`");
    }

    /**
     * Ob die Tabelle bsawo_jobs_current die Spalte einsatzort hat (Schema-Version >= 3).
     * Vermeidet SHOW COLUMNS auf dem Request-Pfad.
     *
     * @return bool
     */
    public static function has_einsatzort_column()
    {
        return (int) get_option('bs_awo_jobs_db_version', 1) >= 3;
    }

    /**
     * Führt Schema-Upgrades aus (z. B. raw_json in bsawo_jobs_current).
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

        self::ensure_raw_json_column();
        self::ensure_einsatzort_columns();
        self::ensure_jobs_staging_table();
        self::ensure_contract_type_index();

        if ($version < 2) {
            update_option('bs_awo_jobs_db_version', 2);
        }
        if ($version < 3) {
            update_option('bs_awo_jobs_db_version', 3);
        }
    }

    /**
     * Stellt sicher, dass die Tabelle bsawo_jobs_current die Spalte raw_json hat.
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
     * Stellt sicher, dass die Tabelle bsawo_jobs_current die Einsatzort-Spalten hat
     * (laut AWO-Schnittstelle: PLZ_Einsatzort, Einsatzort, Straße/Nr des Einsatzortes).
     *
     * @return void
     */
    public static function ensure_einsatzort_columns()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'bsawo_jobs_current';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $add = [];
        foreach (['plz_einsatzort' => 'VARCHAR(20)', 'strasse_einsatzort' => 'VARCHAR(255)', 'einsatzort' => 'TEXT'] as $col => $def) {
            $cols = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $col));
            if (empty($cols)) {
                $add[] = "ADD COLUMN `{$col}` {$def}";
            }
        }
        if ($add) {
            $wpdb->query("ALTER TABLE `{$table}` " . implode(', ', $add));
        }
    }

    /**
     * Stellt sicher, dass auf contract_type ein Index existiert (Filter-Performance).
     *
     * @return void
     */
    public static function ensure_contract_type_index()
    {
        global $wpdb;

        if (! ($wpdb instanceof wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'bsawo_jobs_current';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_contract_type'");
        if (! empty($indexes)) {
            return;
        }

        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX idx_contract_type (contract_type)");
    }
}
