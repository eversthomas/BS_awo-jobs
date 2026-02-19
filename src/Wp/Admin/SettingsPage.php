<?php

namespace BsAwoJobs\Wp\Admin;

use BsAwoJobs\Core\Normalizer;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    const OPTION_API_URL           = 'bs_awo_jobs_api_url';
    const OPTION_DEPARTMENT_SOURCE = 'bs_awo_jobs_department_source';
    const OPTION_LAST_SYNC_MESSAGE = 'bs_awo_jobs_last_sync_message';
    const NONCE_SAVE_SETTINGS      = 'bs_awo_jobs_save_settings';
    const NONCE_SYNC_NOW           = 'bs_awo_jobs_sync_now';
    const NONCE_RESET              = 'bs_awo_jobs_reset_data';

    /**
     * Bootstrap.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_bs_awo_jobs_save_settings', [self::class, 'handle_save_settings']);
        add_action('admin_post_bs_awo_jobs_sync_now', [self::class, 'handle_sync_now']);
        add_action('admin_post_bs_awo_jobs_reset_data', [self::class, 'handle_reset_data']);
    }

    /**
     * Registriert das Hauptmenü und die Settings-Seite.
     *
     * @return void
     */
    public static function register_menu()
    {
        add_menu_page(
            __('AWO Jobs', 'bs-awo-jobs'),
            __('AWO Jobs', 'bs-awo-jobs'),
            'manage_options',
            BS_AWO_JOBS_MENU_SLUG,
            [self::class, 'render_settings_page'],
            'dashicons-groups',
            56
        );
    }

    /**
     * Rendert die Einstellungen.
     *
     * @return void
     */
    public static function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Du hast keine Berechtigung, diese Seite zu sehen.', 'bs-awo-jobs'));
        }

        \BsAwoJobs\Wp\Activation::ensure_einsatzort_columns();

        $apiUrl           = get_option(self::OPTION_API_URL, BS_AWO_JOBS_DEFAULT_API_URL);
        $departmentSource = get_option(self::OPTION_DEPARTMENT_SOURCE, 'api');
        $lastMsg          = get_option(self::OPTION_LAST_SYNC_MESSAGE, '');

        global $wpdb;
        $jobsTable  = $wpdb->prefix . 'bsawo_jobs_current';
        $runsTable  = $wpdb->prefix . 'bsawo_runs';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobsTable)) === $jobsTable;
        $runsExists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runsTable)) === $runsTable;

        $hasEinsatzortCol = false;
        if ($tableExists) {
            $hasEinsatzortCol = ! empty($wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `{$jobsTable}` LIKE %s", 'einsatzort')));
        }

        $activeJobs = 0;
        $lastRun    = null;
        if ($tableExists) {
            $activeJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable}");
        }
        if ($runsExists) {
            $lastRun = $wpdb->get_row(
                "SELECT run_timestamp, status, jobs_count, error_message FROM {$runsTable} ORDER BY run_timestamp DESC LIMIT 1"
            );
        }

        $perPage   = 50;
        $paged     = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset    = ($paged - 1) * $perPage;
        $jobs      = [];
        $totalJobs = 0;
        if ($tableExists) {
            $totalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable}");
            if ($hasEinsatzortCol) {
                $jobs = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT job_id, facility_name, facility_address, COALESCE(einsatzort,'') AS einsatzort, jobfamily_name, department_api, department_custom, contract_type, raw_json
                         FROM {$jobsTable}
                         ORDER BY facility_name ASC, jobfamily_name ASC
                         LIMIT %d OFFSET %d",
                        $perPage,
                        $offset
                    )
                );
            } else {
                $jobs = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT job_id, facility_name, facility_address, jobfamily_name, department_api, department_custom, contract_type, raw_json
                         FROM {$jobsTable}
                         ORDER BY facility_name ASC, jobfamily_name ASC
                         LIMIT %d OFFSET %d",
                        $perPage,
                        $offset
                    )
                );
            }
        }
        $totalPages = $totalJobs > 0 ? (int) ceil($totalJobs / $perPage) : 1;

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AWO Jobs', 'bs-awo-jobs'); ?></h1>

            <?php if ($lastMsg) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($lastMsg); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('API & Sync', 'bs-awo-jobs'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_SAVE_SETTINGS, '_wpnonce', true, true); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_save_settings" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="bs_awo_jobs_api_url"><?php echo esc_html__('JSON-API-URL', 'bs-awo-jobs'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="large-text" id="bs_awo_jobs_api_url" name="bs_awo_jobs_api_url" value="<?php echo esc_attr($apiUrl); ?>" />
                            <p class="description"><?php echo esc_html__('URL der Stellenbörse im JSON-Format (z. B. https://www.awo-jobs.de/stellenboerse-wesel.json).', 'bs-awo-jobs'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Fachbereich für Filter', 'bs-awo-jobs'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="bs_awo_jobs_department_source" value="api" <?php checked($departmentSource, 'api'); ?> /> <?php echo esc_html__('API-Fachbereich', 'bs-awo-jobs'); ?></label><br />
                                <label><input type="radio" name="bs_awo_jobs_department_source" value="custom" <?php checked($departmentSource, 'custom'); ?> /> <?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Einstellungen speichern', 'bs-awo-jobs')); ?>
            </form>

            <?php if ($totalJobs === 0) : ?>
                <p class="description"><?php echo esc_html__('Nach dem ersten Sync erscheinen hier die Stellen.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>
            <p>
                <strong><?php echo esc_html__('Aktive Stellen', 'bs-awo-jobs'); ?>:</strong> <?php echo esc_html(number_format_i18n($activeJobs)); ?>
                <?php if ($lastRun) : ?>
                    — <strong><?php echo esc_html__('Letzter Sync', 'bs-awo-jobs'); ?>:</strong>
                    <?php
                    $ts = strtotime($lastRun->run_timestamp);
                    echo esc_html(date_i18n('d.m.Y H:i', $ts));
                    if ($lastRun->status === 'success') {
                        echo ' (' . esc_html(sprintf(__('%d Jobs', 'bs-awo-jobs'), (int) $lastRun->jobs_count)) . ')';
                    } else {
                        echo ' — ' . esc_html__('Fehlgeschlagen', 'bs-awo-jobs');
                        if (! empty($lastRun->error_message)) {
                            echo ': ' . esc_html($lastRun->error_message);
                        }
                    }
                    ?>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bs-awo-jobs-sync-form">
                <?php wp_nonce_field(self::NONCE_SYNC_NOW, '_wpnonce', true, true); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_sync_now" />
                <p id="bs-awo-jobs-sync-hint" class="description" style="display:none; margin-bottom: 8px;" aria-live="polite"><?php echo esc_html__('Sync läuft… Bitte nicht schließen.', 'bs-awo-jobs'); ?></p>
                <?php submit_button(__('Jetzt synchronisieren', 'bs-awo-jobs'), 'primary', 'submit', false); ?>
            </form>
            <p class="description" style="margin-top: 12px;"><?php echo esc_html__('Mit „Stellen und Cache leeren“ werden alle gespeicherten Stellen und der Filter-Cache gelöscht. Anschließend „Jetzt synchronisieren“ ausführen für einen sauberen Neustart.', 'bs-awo-jobs'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bs-awo-jobs-reset-form" style="margin-top: 8px;">
                <?php wp_nonce_field(self::NONCE_RESET, '_wpnonce', true, true); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_reset_data" />
                <?php submit_button(__('Stellen und Cache leeren', 'bs-awo-jobs'), 'secondary', 'submit', false); ?>
            </form>
            <script>
            (function(){
                var form = document.getElementById('bs-awo-jobs-sync-form');
                if (!form) return;
                form.addEventListener('submit', function(){
                    var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                    var hint = document.getElementById('bs-awo-jobs-sync-hint');
                    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
                    if (hint) { hint.style.display = 'block'; }
                });
            })();
            </script>

            <hr />

            <h2><?php echo esc_html__('Aktuelle Stellenangebote', 'bs-awo-jobs'); ?></h2>
            <?php if (empty($jobs)) : ?>
                <p><?php echo esc_html__('Keine Stellen vorhanden. Bitte zuerst die API-URL speichern und „Jetzt synchronisieren“ ausführen.', 'bs-awo-jobs'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Job-ID', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Stellenbezeichnung', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Ort (Ansprechpartner)', 'bs-awo-jobs'); ?></th>
                            <?php if ($hasEinsatzortCol) : ?><th><?php echo esc_html__('Einsatzort', 'bs-awo-jobs'); ?></th><?php endif; ?>
                            <th><?php echo esc_html__('Fachbereich', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Vertragsart', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $row) : ?>
                            <?php
                            $title = '';
                            if (! empty($row->raw_json) && is_string($row->raw_json)) {
                                $raw = json_decode($row->raw_json, true);
                                if (is_array($raw) && ! empty($raw['Stellenbezeichnung'])) {
                                    $title = (string) $raw['Stellenbezeichnung'];
                                }
                            }
                            if ($title === '') {
                                $title = $row->jobfamily_name ? (string) $row->jobfamily_name : (string) $row->job_id;
                            }
                            $dept = $departmentSource === 'custom' ? (string) $row->department_custom : (string) $row->department_api;
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($row->job_id); ?></code></td>
                                <td><?php echo esc_html($title); ?></td>
                                <td><?php echo esc_html($row->facility_name ?: '—'); ?></td>
                                <td><?php echo esc_html($row->facility_address ?: '—'); ?></td>
                                <?php if ($hasEinsatzortCol) : ?><td><?php echo esc_html(! empty($row->einsatzort) ? $row->einsatzort : '—'); ?></td><?php endif; ?>
                                <td><?php echo esc_html($dept ?: '—'); ?></td>
                                <td><?php echo esc_html($row->contract_type ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($totalPages > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post(paginate_links([
                                'base'      => add_query_arg(['page' => BS_AWO_JOBS_MENU_SLUG, 'paged' => '%#%'], admin_url('admin.php')),
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $totalPages,
                                'prev_text' => __('&laquo; Zurück', 'bs-awo-jobs'),
                                'next_text' => __('Weiter &raquo;', 'bs-awo-jobs'),
                            ]));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php \BsAwoJobs\Wp\Admin\Footer::render(); ?>
        </div>
        <?php
    }

    /**
     * Speichert die API-URL.
     *
     * @return void
     */
    public static function handle_save_settings()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_SAVE_SETTINGS);

        $url = isset($_POST['bs_awo_jobs_api_url'])
            ? sanitize_text_field(wp_unslash($_POST['bs_awo_jobs_api_url']))
            : '';

        if ($url === '') {
            $url = BS_AWO_JOBS_DEFAULT_API_URL;
        }

        // Nur gültige HTTPS-URLs speichern.
        $safe_url = esc_url_raw(trim($url), ['https']);
        $url_error = ($url !== '' && ($safe_url === '' || $safe_url !== trim($url)));
        if (! $url_error) {
            $url_to_save = $url === '' ? BS_AWO_JOBS_DEFAULT_API_URL : $safe_url;
            update_option(self::OPTION_API_URL, $url_to_save);
        }

        if (isset($_POST['bs_awo_jobs_department_source'])) {
            $deptSource = sanitize_text_field(wp_unslash($_POST['bs_awo_jobs_department_source']));
            if (! in_array($deptSource, ['api', 'custom'], true)) {
                $deptSource = 'api';
            }
            update_option(self::OPTION_DEPARTMENT_SOURCE, $deptSource);
        }

        $save_msg = $url_error
            ? __('Die API-URL ist ungültig. Es sind nur HTTPS-URLs erlaubt. Die Einstellung wurde nicht übernommen.', 'bs-awo-jobs')
            : __('Einstellungen gespeichert.', 'bs-awo-jobs');
        update_option(self::OPTION_LAST_SYNC_MESSAGE, $save_msg);

        wp_safe_redirect(
            add_query_arg(
                ['page' => BS_AWO_JOBS_MENU_SLUG],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Führt den manuellen Sync durch:
     * - JSON holen
     * - Run speichern
     * - bsawo_jobs_current füllen
     * - Schema-Report aktualisieren
     *
     * @return void
     */
    public static function handle_sync_now()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_SYNC_NOW);

        $result = \BsAwoJobs\Wp\Cron::run_sync('manual');

        update_option(self::OPTION_LAST_SYNC_MESSAGE, $result['message']);

        wp_safe_redirect(
            add_query_arg(
                ['page' => BS_AWO_JOBS_MENU_SLUG],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Leert Stellen-Tabelle und Filter-Cache für einen sauberen Neustart.
     *
     * @return void
     */
    public static function handle_reset_data()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_RESET);

        global $wpdb;
        $jobsTable = $wpdb->prefix . 'bsawo_jobs_current';
        $runsTable = $wpdb->prefix . 'bsawo_runs';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobsTable)) === $jobsTable) {
            $wpdb->query("TRUNCATE TABLE `{$jobsTable}`");
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runsTable)) === $runsTable) {
            $wpdb->query("TRUNCATE TABLE `{$runsTable}`");
        }

        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_custom');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api_id');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api');

        update_option(self::OPTION_LAST_SYNC_MESSAGE, __('Stellen und Cache wurden geleert. Bitte „Jetzt synchronisieren“ ausführen.', 'bs-awo-jobs'));

        wp_safe_redirect(
            add_query_arg(
                ['page' => BS_AWO_JOBS_MENU_SLUG],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Speichert einen Run in bsawo_runs.
     *
     * @param string|null $dateOverride
     * @param array       $jobs
     * @param string      $status
     * @param string      $errorMessage
     * @return int Run-ID
     */
    public static function store_run($dateOverride, array $jobs, $status, $errorMessage)
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

        // UNIQUE KEY auf run_date: INSERT … ON DUPLICATE KEY UPDATE aktualisiert die Zeile,
        // run_id bleibt erhalten → Events der bisherigen Syncs am selben Tag verweisen weiterhin auf gültige Runs (Fluktuationsanalyse).
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

        // run_id zuverlässig ermitteln (bei UPDATE liefert insert_id 0)
        $runId = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE run_date = %s", $runDate));

        return $runId ? (int) $runId : 0;
    }

    /**
     * Schreibt die aktuelle Jobliste in bsawo_jobs_current.
     *
     * @param int   $runId
     * @param array $jobs
     * @return void
     */
    public static function store_jobs_current($runId, array $jobs)
    {
        global $wpdb;

        \BsAwoJobs\Wp\Activation::ensure_raw_json_column();
        \BsAwoJobs\Wp\Activation::ensure_einsatzort_columns();

        $table = $wpdb->prefix . 'bsawo_jobs_current';

        // Bestehende Einträge verwerfen – aktuelle Sicht neu aufbauen.
        $wpdb->query("TRUNCATE TABLE `{$table}`");

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            if (empty($job['Stellennummer'])) {
                // Ohne Stellennummer keine stabile ID → überspringen.
                continue;
            }

            $jobId = (string) $job['Stellennummer'];

            $facilityId = Normalizer::generate_facility_id($job);

            $facilityName    = isset($job['Einrichtung']) ? (string) $job['Einrichtung'] : '';
            // Ansprechpartner-Adresse (Strasse, PLZ, Ort) – laut AWO-Schnittstelle Kontaktadresse.
            $facilityAddress = '';
            $street          = isset($job['Strasse']) ? (string) $job['Strasse'] : '';
            $plz             = isset($job['PLZ']) ? (string) $job['PLZ'] : '';
            $ort             = isset($job['Ort']) ? (string) $job['Ort'] : '';
            if ($street || $plz || $ort) {
                $facilityAddress = trim($street . ', ' . $plz . ' ' . $ort, " ,");
            }

            // Einsatzort (tatsächlicher Arbeitsort) – laut AWO-Schnittstelle: PLZ_Einsatzort, Einsatzort, Straße/Nr des Einsatzortes.
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

            $departmentApi    = isset($job['Fachbereich']) ? (string) $job['Fachbereich'] : '';
            $departmentApiId  = '';
            if (isset($job['Fachbereich-IDs']) && is_array($job['Fachbereich-IDs']) && $job['Fachbereich-IDs']) {
                $keys              = array_keys($job['Fachbereich-IDs']);
                $departmentApiId   = (string) $keys[0];
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
                'department_api_id' => $departmentApiId,
                'department_custom' => $departmentCustom,
                'jobfamily_id'      => $jobfamilyId,
                'jobfamily_name'    => $jobfamilyName,
                'contract_type'     => $contractType,
                'employment_type'   => $employmentType,
                'work_time_model'   => $workTimeModel,
                'is_minijob'        => $isMinijob,
                'created_at'        => $createdAt,
                'modified_at'       => $modifiedAt,
                'published_at'      => $publishedAt,
                'expires_at'        => $expiresAt,
                'last_seen_run_id'  => $runId,
            ];

            $format = [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d',
            ];

            $wpdb->insert($table, $data, $format);
            // raw_json separat per UPDATE schreiben (zuverlässiger als LONGTEXT im INSERT)
            $wpdb->update(
                $table,
                ['raw_json' => $rawJson],
                ['job_id' => $jobId],
                ['%s'],
                ['%s']
            );
        }

        // Filter-Optionen-Cache invalidieren (Frontend-Dropdowns).
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_custom');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api_id');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api');
    }

}