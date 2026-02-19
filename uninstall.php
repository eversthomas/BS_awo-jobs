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

// Tabellen droppen (mit Prefix)
$tables = [
    $prefix . 'bsawo_runs',
    $prefix . 'bsawo_jobs_current',
    $prefix . 'bsawo_events',
    $prefix . 'bsawo_facilities',
    $prefix . 'bs_awo_stats',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table) . "`");
}
