<?php

namespace BsAwoJobs\Core;

use BsAwoJobs\Infrastructure\JobsRepository;
use BsAwoJobs\Infrastructure\RunsRepository;
use BsAwoJobs\Wp\Admin\SettingsPage;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Orchestriert den Sync: API abrufen, Runs und Jobs persistieren.
 */
class SyncService
{
    const LOCK_TRANSIENT = 'bsawo_jobs_sync_lock';
    const LOCK_TTL_SEC   = 600; // 10 Minuten – Lock gilt als „stale“ danach

    /**
     * Führt einen vollständigen Sync aus (mit Lock gegen parallele Läufe).
     *
     * @param string $context 'cron' oder 'manual'
     * @return array{status: string, message: string, jobs_count: int}
     */
    public static function syncNow($context = 'manual')
    {
        $lockKey = self::LOCK_TRANSIENT;
        $lockVal = get_transient($lockKey);
        if ($lockVal !== false) {
            $lockedAt = is_numeric($lockVal) ? (int) $lockVal : 0;
            if (time() - $lockedAt < self::LOCK_TTL_SEC) {
                return [
                    'status'     => 'skipped',
                    'message'    => __('Sync wird bereits ausgeführt oder wurde kürzlich gestartet.', 'bs-awo-jobs'),
                    'jobs_count' => 0,
                ];
            }
        }

        set_transient($lockKey, (string) time(), self::LOCK_TTL_SEC);

        try {
            return self::doSync($context);
        } finally {
            delete_transient($lockKey);
        }
    }

    /**
     * Führt den eigentlichen Sync aus (ohne Lock – intern).
     *
     * @param string $context
     * @return array{status: string, message: string, jobs_count: int}
     */
    private static function doSync($context)
    {
        $start = microtime(true);
        $apiUrl = get_option(SettingsPage::OPTION_API_URL, BS_AWO_JOBS_DEFAULT_API_URL);

        $result = Fetcher::fetch_json_from_url($apiUrl);

        if ($result instanceof WP_Error) {
            RunsRepository::store(null, [], 'failed', $result->get_error_message());
            self::storeLastSyncMeta(0, 0, 'failed', $result->get_error_message());

            return [
                'status'     => 'failed',
                'message'    => sprintf(
                    /* translators: %s: Fehlernachricht */
                    __('Sync fehlgeschlagen: %s', 'bs-awo-jobs'),
                    $result->get_error_message()
                ),
                'jobs_count' => 0,
            ];
        }

        if (! is_array($result)) {
            $result = [];
        }

        // API kann Root-Array oder Wrapper-Objekt liefern (z. B. { "Stellen": [ ... ] }).
        $result = self::extractJobsArray($result);

        $runId = RunsRepository::store(null, $result, 'success', '');
        $stored = JobsRepository::storeJobsCurrent($runId, $result);

        if ($stored === false) {
            $renameError = get_option('bs_awo_jobs_last_sync_error', '');
            $msg = $renameError !== '' ? $renameError : __('RENAME TABLE fehlgeschlagen (DB-Rechte?).', 'bs-awo-jobs');
            RunsRepository::markRunFailed($msg);
            self::storeLastSyncMeta(0, 0, 'failed', $msg);
            return [
                'status'     => 'failed',
                'message'    => sprintf(
                    /* translators: %s: Fehlernachricht */
                    __('Sync fehlgeschlagen: %s', 'bs-awo-jobs'),
                    $msg
                ),
                'jobs_count' => 0,
            ];
        }

        $duration = (int) round(microtime(true) - $start);
        self::storeLastSyncMeta(count($result), $duration, 'success', '');

        return [
            'status'     => 'success',
            'message'    => sprintf(
                /* translators: %d: Anzahl Jobs */
                __('Sync erfolgreich. %d Jobs übernommen.', 'bs-awo-jobs'),
                count($result)
            ),
            'jobs_count'  => count($result),
        ];
    }

    /**
     * Extrahiert das Stellen-Array aus der API-Antwort.
     * Unterstützt sowohl Root-Array [ { Stellennummer, ... }, ... ] als auch
     * Wrapper-Objekt z. B. { "Stellen": [ ... ] }, { "data": [ ... ] }, { "jobs": [ ... ] }.
     *
     * @param array $data
     * @return array
     */
    private static function extractJobsArray(array $data)
    {
        if (empty($data)) {
            return [];
        }

        // Bereits numerisch indiziertes Array (Root = Liste der Stellen).
        if (array_keys($data) === range(0, count($data) - 1)) {
            return $data;
        }

        $wrapperKeys = ['Stellen', 'stellen', 'data', 'jobs', 'items'];
        foreach ($wrapperKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }

    /**
     * Speichert Metadaten des letzten Syncs für Admin-Anzeige (P2.5 Observability).
     *
     * @param int    $jobsCount
     * @param int    $durationSec
     * @param string $status
     * @param string $errorMessage
     * @return void
     */
    private static function storeLastSyncMeta($jobsCount, $durationSec, $status, $errorMessage)
    {
        update_option('bs_awo_jobs_last_sync_duration_sec', $durationSec, false);
        update_option('bs_awo_jobs_last_sync_status', $status, false);
        update_option('bs_awo_jobs_last_sync_error', $errorMessage, false);
        update_option('bs_awo_jobs_last_sync_at', current_time('mysql'), false);
    }
}
