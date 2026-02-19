<?php
/**
 * Wird ausgeführt, wenn das Plugin über WordPress gelöscht wird (nicht bei Deaktivierung).
 * Entfernt alle Optionen, Transients und Datenbanktabellen des Plugins.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// Optionen löschen
$options = [
    'bs_awo_jobs_api_url',
    'bs_awo_jobs_department_source',
    'bs_awo_jobs_last_sync_message',
    'bs_awo_jobs_cron_schedule',
    'bs_awo_jobs_last_sync_duration_sec',
    'bs_awo_jobs_last_sync_status',
    'bs_awo_jobs_last_sync_error',
    'bs_awo_jobs_sync_rename_failed',
    'bs_awo_jobs_frontend_design',
    'bs_awo_jobs_frontend_display',
    'bs_awo_jobs_db_version',
];

foreach ($options as $option) {
    delete_option($option);
}

// Transients für Filter-Dropdowns (JobBoard)
$transient_keys = [
    'bs_awo_jobs_filter_opts_v2_department_custom',
    'bs_awo_jobs_filter_opts_v2_department_api_id',
    'bs_awo_jobs_filter_opts_v2_department_api',
];

foreach ($transient_keys as $key) {
    delete_transient($key);
}

delete_transient('bsawo_jobs_sync_lock');

// Tabellen droppen (Whitelist-Suffixe, Prefix von WP – nur gültige Identifier)
$table_suffixes = [
    'bsawo_jobs_old',
    'bsawo_jobs_staging',
    'bsawo_jobs_current',
    'bsawo_runs',
    'bsawo_events',
    'bsawo_facilities',
    'bs_awo_stats',
];

foreach ($table_suffixes as $suffix) {
    if (preg_match('/^[a-zA-Z0-9_]+$/', $suffix) !== 1) {
        continue;
    }
    $table = $prefix . $suffix;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}
