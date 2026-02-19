<?php

namespace BsAwoJobs\Wp;

use BsAwoJobs\Core\SyncService;
use BsAwoJobs\Wp\Admin\SettingsPage;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Einstiegspunkt für Sync (manuell oder WP-Cron).
 */
class Cron
{
    const HOOK_SYNC_EVENT = 'bsawo_jobs_sync_event';

    /**
     * Führt Sync aus (manuell von SettingsPage oder per Cron).
     *
     * @param string $context 'cron' oder 'manual'
     * @return array{status: string, message: string, jobs_count: int}
     */
    public static function run_sync($context = 'manual')
    {
        return SyncService::syncNow($context);
    }

    /**
     * Wird von WP-Cron aufgerufen (ohne Parameter).
     */
    public static function run_sync_cron()
    {
        self::run_sync('cron');
    }

    /**
     * Stellt den Cron-Zeitplan gemäß Option ein (alle geplanten Events löschen, bei Bedarf neu anlegen).
     */
    public static function reschedule()
    {
        wp_clear_scheduled_hook(self::HOOK_SYNC_EVENT);

        $schedule = get_option(SettingsPage::OPTION_CRON_SCHEDULE, '');
        if ($schedule !== '' && in_array($schedule, ['hourly', 'twicedaily', 'daily'], true)) {
            wp_schedule_event(time(), $schedule, self::HOOK_SYNC_EVENT);
        }
    }
}
