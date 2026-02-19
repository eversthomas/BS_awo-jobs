<?php

namespace BsAwoJobs\Infrastructure;

use BsAwoJobs\Core\Normalizer;
use BsAwoJobs\Wp\Shortcodes\JobBoard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persistenz für aktuelle Jobs (bsawo_jobs_current).
 */
class JobsRepository
{
    /**
     * Schreibt die aktuelle Jobliste in bsawo_jobs_current.
     * Staging + Swap: Schreiben in bsawo_jobs_staging, dann atomarer Tausch – Frontend sieht keine leere Tabelle.
     * Ein Job = ein INSERT inkl. raw_json (kein Folge-UPDATE).
     * Bei RENAME-Fehler: Live-Daten unangetastet, Sync als failed markieren, false zurückgeben (kein Fallback-Downtime).
     *
     * @param int   $runId
     * @param array $jobs
     * @return bool true bei Erfolg, false wenn RENAME TABLE fehlschlägt (DB-Rechte)
     */
    public static function storeJobsCurrent($runId, array $jobs)
    {
        global $wpdb;

        \BsAwoJobs\Wp\Activation::ensure_raw_json_column();
        \BsAwoJobs\Wp\Activation::ensure_einsatzort_columns();
        \BsAwoJobs\Wp\Activation::ensure_jobs_staging_table();

        $currentTable = $wpdb->prefix . 'bsawo_jobs_current';
        $stagingTable = $wpdb->prefix . 'bsawo_jobs_staging';
        $table        = $stagingTable;

        $wpdb->query("TRUNCATE TABLE `{$table}`");

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            if (empty($job['Stellennummer'])) {
                continue;
            }

            $jobId = (string) $job['Stellennummer'];
            $facilityId = Normalizer::generate_facility_id($job);

            $facilityName    = isset($job['Einrichtung']) ? (string) $job['Einrichtung'] : '';
            $facilityAddress = '';
            $street          = isset($job['Strasse']) ? (string) $job['Strasse'] : '';
            $plz             = isset($job['PLZ']) ? (string) $job['PLZ'] : '';
            $ort             = isset($job['Ort']) ? (string) $job['Ort'] : '';
            if ($street || $plz || $ort) {
                $facilityAddress = trim($street . ', ' . $plz . ' ' . $ort, " ,");
            }

            $plzEinsatzort    = isset($job['PLZ_Einsatzort']) ? (string) $job['PLZ_Einsatzort'] : (isset($job['plz_einsatzort']) ? (string) $job['plz_einsatzort'] : '');
            $einsatzort      = isset($job['Einsatzort']) ? trim((string) $job['Einsatzort']) : (isset($job['einsatzort']) ? trim((string) $job['einsatzort']) : '');
            $strasseEinsatzort = '';
            foreach (['Straße/Nr des Einsatzortes', 'Strasse/Nr des Einsatzortes'] as $key) {
                if (isset($job[$key]) && (string) $job[$key] !== '') {
                    $strasseEinsatzort = trim((string) $job[$key]);
                    break;
                }
            }
            if ($strasseEinsatzort === '') {
                foreach (array_keys($job) as $key) {
                    if (is_string($key) && (stripos($key, 'Einsatzortes') !== false || stripos($key, 'Nr des') !== false)) {
                        $strasseEinsatzort = trim((string) $job[$key]);
                        break;
                    }
                }
            }

            $departmentApi   = isset($job['Fachbereich']) ? (string) $job['Fachbereich'] : '';
            $departmentApiId = '';
            if (isset($job['Fachbereich-IDs']) && is_array($job['Fachbereich-IDs']) && $job['Fachbereich-IDs']) {
                $keys          = array_keys($job['Fachbereich-IDs']);
                $departmentApiId = (string) $keys[0];
            }

            $departmentCustom = isset($job['Mandantnr/Einrichtungsnr']) ? (string) $job['Mandantnr/Einrichtungsnr'] : '';

            $jobfamilyId   = '';
            $jobfamilyName = '';
            if (isset($job['Stellenbezeichnung-IDs']) && is_array($job['Stellenbezeichnung-IDs']) && $job['Stellenbezeichnung-IDs']) {
                $keys          = array_keys($job['Stellenbezeichnung-IDs']);
                $firstKey      = (string) $keys[0];
                $jobfamilyId   = $firstKey;
                $jobfamilyName = (string) $job['Stellenbezeichnung-IDs'][$firstKey];
            }

            $contractType   = isset($job['Vertragsart']) ? (string) $job['Vertragsart'] : '';
            $employmentType = isset($job['Anstellungsart']) ? (string) $job['Anstellungsart'] : '';
            $workTimeModel  = isset($job['Zeitmodell']) ? (string) $job['Zeitmodell'] : '';
            $isMinijob      = isset($job['IsMinijob']) ? (int) $job['IsMinijob'] : 0;

            $createdAt   = isset($job['Anlagedatum']) ? (int) $job['Anlagedatum'] : 0;
            $modifiedAt  = isset($job['Aenderungsdatum']) ? (int) $job['Aenderungsdatum'] : 0;
            $publishedAt = isset($job['Startdatum']) ? (int) $job['Startdatum'] : 0;
            $expiresAt   = isset($job['Stopdatum']) ? (int) $job['Stopdatum'] : 0;

            $rawJson = wp_json_encode($job);

            $data = [
                'job_id'             => $jobId,
                'facility_id'        => $facilityId,
                'facility_name'      => $facilityName,
                'facility_address'   => $facilityAddress,
                'plz_einsatzort'     => $plzEinsatzort,
                'strasse_einsatzort' => $strasseEinsatzort,
                'einsatzort'         => $einsatzort,
                'department_api'     => $departmentApi,
                'department_api_id'  => $departmentApiId,
                'department_custom'  => $departmentCustom,
                'jobfamily_id'       => $jobfamilyId,
                'jobfamily_name'     => $jobfamilyName,
                'contract_type'      => $contractType,
                'employment_type'    => $employmentType,
                'work_time_model'     => $workTimeModel,
                'is_minijob'         => $isMinijob,
                'created_at'         => $createdAt,
                'modified_at'        => $modifiedAt,
                'published_at'       => $publishedAt,
                'expires_at'         => $expiresAt,
                'last_seen_run_id'   => $runId,
                'raw_json'           => $rawJson,
            ];

            $format = [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d',
                '%s',
            ];

            $wpdb->insert($table, $data, $format);
        }

        // Atomarer Swap: Staging wird zur aktuellen Tabelle, Frontend sieht keine leere Phase.
        // Bei Fehler: Live-Tabelle unangetastet lassen, Sync als failed markieren (kein TRUNCATE+INSERT-Fallback).
        $oldTable = $wpdb->prefix . 'bsawo_jobs_old';
        $renamed = $wpdb->query("RENAME TABLE `{$currentTable}` TO `{$oldTable}`, `{$stagingTable}` TO `{$currentTable}`");
        if ($renamed === false || $wpdb->last_error !== '') {
            $msg = __('RENAME TABLE fehlgeschlagen (DB-Rechte?). Aktuelle Daten unverändert.', 'bs-awo-jobs');
            update_option('bs_awo_jobs_last_sync_error', $msg, false);
            update_option('bs_awo_jobs_last_sync_status', 'failed', false);
            update_option('bs_awo_jobs_sync_rename_failed', time(), false);
            return false;
        }

        $wpdb->query("DROP TABLE IF EXISTS `{$oldTable}`");
        \BsAwoJobs\Wp\Activation::ensure_jobs_staging_table();

        self::invalidateFilterCache();
        return true;
    }

    /**
     * Leert die Jobs-Tabelle und invalidierte den Filter-Cache.
     *
     * @return void
     */
    public static function truncate()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bsawo_jobs_current';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }
        self::invalidateFilterCache();
    }

    /**
     * Filter-Optionen-Transients löschen.
     *
     * @return void
     */
    public static function invalidateFilterCache()
    {
        $prefix = JobBoard::TRANSIENT_FILTER_OPTS_PREFIX;
        delete_transient($prefix . 'department_custom');
        delete_transient($prefix . 'department_api_id');
        delete_transient($prefix . 'department_api');
    }
}
