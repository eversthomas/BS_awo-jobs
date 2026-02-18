<?php

namespace BsAwoJobs\Wp;

use BsAwoJobs\Core\DiffEngine;
use BsAwoJobs\Core\Fetcher;
use BsAwoJobs\Core\Normalizer;
use BsAwoJobs\Core\SchemaInspector;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Verwaltet Sync-Logik (nur manuell; automatischer Cron wurde entfernt).
 */
class Cron
{
    const OPTION_CRON_ENABLED            = 'bs_awo_jobs_cron_enabled';
    const OPTION_CRON_TIME               = 'bs_awo_jobs_cron_time';
    const OPTION_LAST_ANOMALY            = 'bs_awo_jobs_last_anomaly';
    const OPTION_LAST_FACILITY_COLLISIONS = 'bs_awo_jobs_last_facility_collisions';

    /**
     * Hooks registrieren (aktuell keine; Cron wurde entfernt).
     *
     * @return void
     */
    public static function init()
    {
        // Automatischer Sync (Cron) wurde entfernt – nur manueller Abgleich.
    }

    /**
     * Führt Sync aus (nur manuell von SettingsPage).
     *
     * @param string $context 'cron' oder 'manual'
     * @return array{status: string, message: string, jobs_count: int, events: array}
     */
    public static function run_sync($context = 'cron')
    {
        $apiUrl = get_option(\BsAwoJobs\Wp\Admin\SettingsPage::OPTION_API_URL, BS_AWO_JOBS_DEFAULT_API_URL);

        $result = Fetcher::fetch_json_from_url($apiUrl);

        if ($result instanceof WP_Error) {
            \BsAwoJobs\Wp\Admin\SettingsPage::store_run(null, [], 'failed', $result->get_error_message());

            return [
                'status'      => 'failed',
                'message'     => sprintf(
                    /* translators: %s: Fehlernachricht */
                    __('Sync fehlgeschlagen: %s', 'bs-awo-jobs'),
                    $result->get_error_message()
                ),
                'jobs_count'  => 0,
                'events'      => [
                    'created'  => [],
                    'modified' => [],
                    'offlined' => [],
                ],
            ];
        }

        if (! is_array($result)) {
            $result = [];
        }

        $previousSnapshot = self::get_last_successful_snapshot();
        $previousCount    = is_array($previousSnapshot) ? count($previousSnapshot) : 0;

        $runId = \BsAwoJobs\Wp\Admin\SettingsPage::store_run(null, $result, 'success', '');

        $events = [
            'created'  => [],
            'modified' => [],
            'offlined' => [],
        ];

        if (! empty($previousSnapshot)) {
            $engine = new DiffEngine();
            $events = $engine->compute_diff($previousSnapshot, $result, $runId);
            self::persist_events($events, $runId);
            self::check_for_anomalies($events, $previousCount);
        }

        // Jobs-Current aktualisieren.
        \BsAwoJobs\Wp\Admin\SettingsPage::store_jobs_current($runId, $result);

        // Facility-ID-Kollisionen prüfen und im Admin meldbar machen.
        self::detect_facility_collisions();

        // Schema-Report aktualisieren (für Schema Inspector).
        $report = SchemaInspector::analyze($result);
        update_option(\BsAwoJobs\Wp\Admin\SettingsPage::OPTION_LAST_SCHEMA_REPORT, $report, false);

        return [
            'status'      => 'success',
            'message'     => sprintf(
                /* translators: %d: Anzahl Jobs */
                __('Sync erfolgreich. %d Jobs übernommen.', 'bs-awo-jobs'),
                count($result)
            ),
            'jobs_count'  => count($result),
            'events'      => $events,
        ];
    }

    /**
     * Letzten erfolgreichen Snapshot aus bsawo_runs laden.
     *
     * @return array
     */
    private static function get_last_successful_snapshot()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_runs';

        $json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT snapshot_json FROM {$table}
                 WHERE status = %s
                 ORDER BY run_date DESC, run_timestamp DESC
                 LIMIT 1",
                'success'
            )
        );

        if (! $json) {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Persistiert Events in bsawo_events.
     *
     * @param array $events
     * @param int   $runId
     * @return void
     */
    private static function persist_events(array $events, $runId)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_events';
        $date  = current_time('Y-m-d');
        $now   = current_time('mysql');

        foreach (['created', 'modified', 'offlined'] as $type) {
            if (empty($events[$type]) || ! is_array($events[$type])) {
                continue;
            }

            foreach ($events[$type] as $event) {
                $prev = isset($event['previous_state']) ? $event['previous_state'] : null;
                $next = isset($event['new_state']) ? $event['new_state'] : null;

                $jobForMeta = is_array($next) ? $next : (is_array($prev) ? $prev : null);
                $facilityId = '';
                $jobfamilyId = '';
                $departmentApiId = '';
                $departmentCustom = '';
                if (is_array($jobForMeta)) {
                    $facilityId = Normalizer::generate_facility_id($jobForMeta);
                    if (isset($jobForMeta['Stellenbezeichnung-IDs']) && is_array($jobForMeta['Stellenbezeichnung-IDs']) && $jobForMeta['Stellenbezeichnung-IDs']) {
                        $keys = array_keys($jobForMeta['Stellenbezeichnung-IDs']);
                        $jobfamilyId = (string) $keys[0];
                    }
                    if (isset($jobForMeta['Fachbereich-IDs']) && is_array($jobForMeta['Fachbereich-IDs']) && $jobForMeta['Fachbereich-IDs']) {
                        $keys = array_keys($jobForMeta['Fachbereich-IDs']);
                        $departmentApiId = (string) $keys[0];
                    }
                    $departmentCustom = isset($jobForMeta['Mandantnr/Einrichtungsnr']) ? (string) $jobForMeta['Mandantnr/Einrichtungsnr'] : '';
                }

                $wpdb->insert(
                    $table,
                    [
                        'job_id'            => isset($event['job_id']) ? (string) $event['job_id'] : '',
                        'event_type'        => $type,
                        'event_date'        => $date,
                        'detected_at'       => $now,
                        'run_id'            => $runId,
                        'previous_state'    => $prev !== null ? wp_json_encode($prev) : null,
                        'new_state'         => $next !== null ? wp_json_encode($next) : null,
                        'facility_id'       => $facilityId,
                        'jobfamily_id'      => $jobfamilyId,
                        'department_api_id' => $departmentApiId,
                        'department_custom' => $departmentCustom,
                    ],
                    [
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%d',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ]
                );
            }
        }
    }

    /**
     * Prüft auf Anomalien (z.B. >50% verschwundene Jobs).
     *
     * @param array $events
     * @param int   $previousCount
     * @return void
     */
    private static function check_for_anomalies(array $events, $previousCount)
    {
        if ($previousCount <= 0) {
            return;
        }

        $offlinedCount = isset($events['offlined']) && is_array($events['offlined'])
            ? count($events['offlined'])
            : 0;

        if ($offlinedCount <= 0) {
            return;
        }

        $rate = $offlinedCount / $previousCount;

        if ($rate > 0.5) {
            $percent = round($rate * 100, 2);

            update_option(
                self::OPTION_LAST_ANOMALY,
                [
                    'date'    => current_time('mysql'),
                    'type'    => 'mass_disappearance',
                    'details' => sprintf(
                        /* translators: %s: Prozentwert */
                        __('%s%% der Jobs sind in einem Sync verschwunden.', 'bs-awo-jobs'),
                        $percent
                    ),
                ],
                false
            );
        }
    }

    /**
     * Prüft, ob mehrere unterschiedliche Einrichtungen auf dieselbe facility_id fallen.
     *
     * Erkennt potenzielle Hash-Kollisionen bzw. stark ähnliche Adressdatensätze und
     * speichert eine Zusammenfassung in einer Option, damit sie im Admin angezeigt werden kann.
     *
     * @return void
     */
    private static function detect_facility_collisions()
    {
        global $wpdb;

        if (! ($wpdb instanceof \wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'bsawo_jobs_current';

        // Falls Tabelle (noch) nicht existiert (z.B. frische Installation vor Aktivierung), abbrechen.
        $tableExists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        if ($tableExists !== $table) {
            return;
        }

        // Mehrere Varianten von Name+Adresse pro facility_id deuten auf potenzielle Kollisionen hin.
        $rows = $wpdb->get_results(
            "SELECT 
                facility_id,
                COUNT(DISTINCT CONCAT(COALESCE(facility_name, ''), ' | ', COALESCE(facility_address, ''))) AS variants,
                GROUP_CONCAT(DISTINCT facility_name ORDER BY facility_name SEPARATOR ' / ') AS facility_names,
                GROUP_CONCAT(DISTINCT facility_address ORDER BY facility_address SEPARATOR ' / ') AS facility_addresses
            FROM {$table}
            GROUP BY facility_id
            HAVING variants > 1
            ORDER BY variants DESC
            LIMIT 20"
        );

        if (empty($rows)) {
            // Keine Kollisionen mehr – alte Hinweise entfernen.
            delete_option(self::OPTION_LAST_FACILITY_COLLISIONS);

            return;
        }

        $collisions = [];

        foreach ($rows as $row) {
            $collisions[] = [
                'facility_id' => $row->facility_id,
                'variants'    => (int) $row->variants,
                'names'       => $row->facility_names,
                'addresses'   => $row->facility_addresses,
            ];
        }

        update_option(
            self::OPTION_LAST_FACILITY_COLLISIONS,
            [
                'date'       => current_time('mysql'),
                'count'      => count($collisions),
                'collisions' => $collisions,
            ],
            false
        );
    }

    /**
     * Entfernt ggf. noch geplante Cron-Events (Aufräumen nach Entfernung des automatischen Sync).
     *
     * @return void
     */
    public static function reschedule()
    {
        $timestamp = wp_next_scheduled('bs_awo_jobs_daily_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bs_awo_jobs_daily_sync');
        }
    }
}

