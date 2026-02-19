<?php

namespace BsAwoJobs\Infrastructure;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persistenz für Sync-Runs (bsawo_runs).
 */
class RunsRepository
{
    /**
     * Speichert einen Run in bsawo_runs.
     *
     * @param string|null $dateOverride
     * @param array       $jobs
     * @param string      $status
     * @param string      $errorMessage
     * @return int Run-ID
     */
    public static function store($dateOverride, array $jobs, $status, $errorMessage)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_runs';

        $runDate   = $dateOverride ? $dateOverride : current_time('Y-m-d');
        $timestamp = current_time('mysql');

        $json      = wp_json_encode($jobs);
        $checksum  = hash('sha256', (string) $json);
        $jobsCount = count($jobs);

        $data = [
            'run_date'          => $runDate,
            'run_timestamp'     => $timestamp,
            'snapshot_checksum' => $checksum,
            'snapshot_json'     => $json,
            'jobs_count'        => $jobsCount,
            'status'            => $status,
            'error_message'     => $errorMessage,
        ];

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (run_date, run_timestamp, snapshot_checksum, snapshot_json, jobs_count, status, error_message)
                 VALUES (%s, %s, %s, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    run_timestamp = VALUES(run_timestamp),
                    snapshot_checksum = VALUES(snapshot_checksum),
                    snapshot_json = VALUES(snapshot_json),
                    jobs_count = VALUES(jobs_count),
                    status = VALUES(status),
                    error_message = VALUES(error_message)",
                $data['run_date'],
                $data['run_timestamp'],
                $data['snapshot_checksum'],
                $data['snapshot_json'],
                $data['jobs_count'],
                $data['status'],
                $data['error_message']
            )
        );

        $runId = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE run_date = %s", $runDate));

        return $runId ? (int) $runId : 0;
    }

    /**
     * Leert die Runs-Tabelle.
     *
     * @return void
     */
    public static function truncate()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bsawo_runs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }
    }
}
