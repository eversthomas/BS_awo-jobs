<?php

namespace BsAwoJobs\Wp;

use BsAwoJobs\Core\Fetcher;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Führt manuellen Sync aus: JSON von API holen, in bsawo_jobs_current schreiben.
 */
class Cron
{
    /**
     * Führt Sync aus (nur manuell von SettingsPage).
     *
     * @param string $context 'cron' oder 'manual'
     * @return array{status: string, message: string, jobs_count: int}
     */
    public static function run_sync($context = 'manual')
    {
        $apiUrl = get_option(\BsAwoJobs\Wp\Admin\SettingsPage::OPTION_API_URL, BS_AWO_JOBS_DEFAULT_API_URL);

        $result = Fetcher::fetch_json_from_url($apiUrl);

        if ($result instanceof WP_Error) {
            \BsAwoJobs\Wp\Admin\SettingsPage::store_run(null, [], 'failed', $result->get_error_message());

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

        $runId = \BsAwoJobs\Wp\Admin\SettingsPage::store_run(null, $result, 'success', '');
        \BsAwoJobs\Wp\Admin\SettingsPage::store_jobs_current($runId, $result);

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
}
