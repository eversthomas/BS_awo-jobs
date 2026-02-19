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

        dbDelta($sql_runs);
        dbDelta($sql_jobs_current);

        update_option('bs_awo_jobs_db_version', 2);
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

        if ($version < 2) {
            update_option('bs_awo_jobs_db_version', 2);
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
}
