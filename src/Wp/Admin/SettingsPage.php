<?php

namespace BsAwoJobs\Wp\Admin;

use BsAwoJobs\Core\Fetcher;
use BsAwoJobs\Core\Normalizer;
use BsAwoJobs\Core\SchemaInspector;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class SettingsPage
{
    const OPTION_API_URL              = 'bs_awo_jobs_api_url';
    const OPTION_DEPARTMENT_SOURCE    = 'bs_awo_jobs_department_source';
    const OPTION_LAST_SCHEMA_REPORT   = 'bs_awo_jobs_last_schema_report';
    const OPTION_LAST_SYNC_MESSAGE    = 'bs_awo_jobs_last_sync_message';
    const OPTION_CRON_ENABLED         = 'bs_awo_jobs_cron_enabled';
    const OPTION_CRON_TIME            = 'bs_awo_jobs_cron_time';
    const NONCE_SAVE_SETTINGS         = 'bs_awo_jobs_save_settings';
    const NONCE_SYNC_NOW              = 'bs_awo_jobs_sync_now';
    const NONCE_FORCE_RESYNC          = 'bs_awo_jobs_force_resync';
    const NONCE_EXPORT_BACKUP         = 'bs_awo_jobs_export_backup';
    const NONCE_IMPORT_BACKUP         = 'bs_awo_jobs_import_backup';
    const BACKUP_FORMAT_VERSION       = 1;

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
        add_action('admin_post_bs_awo_jobs_force_resync', [self::class, 'handle_force_resync']);
        add_action('admin_post_bs_awo_jobs_export_backup', [self::class, 'handle_export_backup']);
        add_action('admin_post_bs_awo_jobs_import_backup', [self::class, 'handle_import_backup']);
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

        $apiUrl           = get_option(self::OPTION_API_URL, BS_AWO_JOBS_DEFAULT_API_URL);
        $departmentSource = get_option(self::OPTION_DEPARTMENT_SOURCE, 'api');
        $lastMsg          = get_option(self::OPTION_LAST_SYNC_MESSAGE, '');

        global $wpdb;
        $lastRun        = null;
        $activeJobs     = null;
        $successRuns    = null;
        $failedRuns     = null;
        $recentRuns     = [];
        $rawJsonSample  = null;

        if ($wpdb instanceof \wpdb) {
            $runsTable = $wpdb->prefix . 'bsawo_runs';
            $jobsTable = $wpdb->prefix . 'bsawo_jobs_current';

            $lastRun = $wpdb->get_row(
                "SELECT run_timestamp, status, jobs_count, error_message 
                 FROM {$runsTable}
                 ORDER BY run_timestamp DESC
                 LIMIT 1"
            );

            $activeJobs = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$jobsTable}"
            );

            $successRuns = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$runsTable} WHERE status = %s",
                    'success'
                )
            );

            $failedRuns = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$runsTable} WHERE status = %s",
                    'failed'
                )
            );

            $recentRuns = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT run_date, run_timestamp, status, jobs_count 
                     FROM {$runsTable}
                     ORDER BY run_timestamp DESC
                     LIMIT %d",
                    10
                )
            );

            $rawJsonSample = $wpdb->get_row(
                "SELECT job_id, raw_json FROM {$jobsTable} WHERE raw_json IS NOT NULL AND raw_json != '' LIMIT 1"
            );
        }

        $anomaly = get_option(\BsAwoJobs\Wp\Cron::OPTION_LAST_ANOMALY);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AWO Jobs – Einstellungen', 'bs-awo-jobs'); ?></h1>

            <?php if ($lastMsg) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($lastMsg); ?></p>
                </div>
            <?php endif; ?>

            <?php
            $importResult = isset($_GET['bs_awo_jobs_import']) ? sanitize_text_field(wp_unslash($_GET['bs_awo_jobs_import'])) : '';
            if ($importResult === 'ok') :
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Backup wurde erfolgreich importiert.', 'bs-awo-jobs'); ?></p>
                </div>
            <?php elseif ($importResult === 'invalid') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Die Datei ist kein gültiges Backup (JSON mit version und tables).', 'bs-awo-jobs'); ?></p>
                </div>
            <?php elseif ($importResult === 'no_file') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Es wurde keine Datei ausgewählt oder der Upload ist fehlgeschlagen.', 'bs-awo-jobs'); ?></p>
                </div>
            <?php elseif ($importResult === 'too_large') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Die Datei ist zu groß (max. 50 MB).', 'bs-awo-jobs'); ?></p>
                </div>
            <?php elseif ($importResult === 'invalid_type') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Ungültiger Dateityp. Nur JSON-Dateien (application/json oder text/plain) sind erlaubt.', 'bs-awo-jobs'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Aktueller Überblick', 'bs-awo-jobs'); ?></h2>
            <ul>
                <?php if ($activeJobs !== null) : ?>
                    <li>
                        <strong><?php echo esc_html__('Aktive Jobs', 'bs-awo-jobs'); ?>:</strong>
                        <?php echo esc_html(number_format_i18n($activeJobs)); ?>
                    </li>
                <?php endif; ?>
                <?php if ($successRuns !== null || $failedRuns !== null) : ?>
                    <li>
                        <strong><?php echo esc_html__('Syncs gesamt', 'bs-awo-jobs'); ?>:</strong>
                        <?php
                        $successLabel = sprintf(
                            /* translators: %d: Anzahl erfolgreicher Syncs */
                            __('%d erfolgreich', 'bs-awo-jobs'),
                            (int) $successRuns
                        );
                        $failedLabel  = sprintf(
                            /* translators: %d: Anzahl fehlgeschlagener Syncs */
                            __('%d fehlgeschlagen', 'bs-awo-jobs'),
                            (int) $failedRuns
                        );
                        echo esc_html($successLabel . ' / ' . $failedLabel);
                        ?>
                    </li>
                <?php endif; ?>
            </ul>

            <?php
            if ($rawJsonSample !== null && ! empty($rawJsonSample->raw_json)) :
                $raw = json_decode($rawJsonSample->raw_json, true);
                $rawKeys = is_array($raw) ? array_keys($raw) : [];
                $expected = ['DetailUrl', 'Einleitungstext', 'Infos', 'Qualifikation', 'Wirbieten'];
                $present  = array_filter($expected, function ($k) use ($raw) {
                    return isset($raw[$k]) && (string) $raw[$k] !== '';
                });
                ?>
                <h3 style="margin-top: 1.5em;"><?php echo esc_html__('raw_json Struktur-Check (Beispiel-Job)', 'bs-awo-jobs'); ?></h3>
                <p class="description"><?php echo esc_html__('Prüft, ob die in der DB gespeicherten API-Daten die Felder für Detail-Ansicht und „Jetzt bewerben“-Link enthalten.', 'bs-awo-jobs'); ?></p>
                <table class="widefat striped" style="max-width: 640px;">
                    <tbody>
                        <tr>
                            <td><strong><?php echo esc_html__('Beispiel job_id', 'bs-awo-jobs'); ?></strong></td>
                            <td><?php echo esc_html($rawJsonSample->job_id); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('Anzahl Keys in raw_json', 'bs-awo-jobs'); ?></strong></td>
                            <td><?php echo esc_html((string) count($rawKeys)); ?></td>
                        </tr>
                        <?php foreach ($expected as $key) : ?>
                        <tr>
                            <td><?php echo esc_html($key); ?></td>
                            <td>
                                <?php
                                $ok = isset($raw[$key]) && (string) $raw[$key] !== '';
                                if ($key === 'DetailUrl' && $ok) {
                                    $url = trim((string) $raw[$key]);
                                    $ok = preg_match('#^https?://#i', $url);
                                }
                                echo $ok ? '✓ ' . esc_html__('vorhanden', 'bs-awo-jobs') : '✗ ' . esc_html__('fehlt oder leer', 'bs-awo-jobs');
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><strong><?php echo esc_html__('Alle Keys (Auszug)', 'bs-awo-jobs'); ?></strong></td>
                            <td><code style="font-size: 0.9em;"><?php echo esc_html(implode(', ', array_slice($rawKeys, 0, 20)) . (count($rawKeys) > 20 ? ' …' : '')); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            <?php elseif ($activeJobs !== null && $activeJobs > 0) : ?>
                <p class="description" style="margin-top: 1.5em;"><?php echo esc_html__('raw_json Struktur-Check: Kein Job mit gespeichertem raw_json gefunden. Bitte einen Sync ausführen.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('API-Einstellungen', 'bs-awo-jobs'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_SAVE_SETTINGS); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_save_settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="bs_awo_jobs_api_url">
                                <?php echo esc_html__('API-URL', 'bs-awo-jobs'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="text"
                                class="regular-text"
                                id="bs_awo_jobs_api_url"
                                name="bs_awo_jobs_api_url"
                                value="<?php echo esc_attr($apiUrl); ?>"
                            />
                            <p class="description">
                                <?php
                                echo esc_html__(
                                    'Standard: https://www.awo-jobs.de/stellenboerse-wesel.json',
                                    'bs-awo-jobs'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html__('Fachbereich für Auswertungen', 'bs-awo-jobs'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input
                                        type="radio"
                                        name="bs_awo_jobs_department_source"
                                        value="api"
                                        <?php checked($departmentSource, 'api'); ?>
                                    />
                                    <?php echo esc_html__('API Fachbereich (Standard für die meisten Verbände)', 'bs-awo-jobs'); ?>
                                </label>
                                <br />
                                <label>
                                    <input
                                        type="radio"
                                        name="bs_awo_jobs_department_source"
                                        value="custom"
                                        <?php checked($departmentSource, 'custom'); ?>
                                    />
                                    <?php echo esc_html__('Mandantenfeld (empfohlen für AWO Wesel)', 'bs-awo-jobs'); ?>
                                </label>
                            </fieldset>
                            <p class="description">
                                <?php
                                echo esc_html__(
                                    'AWO Wesel nutzt das Mandantenfeld für die interne Fachbereichs-Zuordnung. Andere Verbände können bei Bedarf dasselbe Muster verwenden.',
                                    'bs-awo-jobs'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Einstellungen speichern', 'bs-awo-jobs')); ?>
            </form>

            <hr />

            <?php if ($lastRun) : ?>
                <h3><?php echo esc_html__('Sync-Status', 'bs-awo-jobs'); ?></h3>
                <ul>
                    <li>
                        <strong><?php echo esc_html__('Letzter Sync', 'bs-awo-jobs'); ?>:</strong>
                        <?php
                        $ts = strtotime($lastRun->run_timestamp);
                        echo esc_html(
                            sprintf(
                                '%s (%s)',
                                date_i18n('Y-m-d H:i', $ts),
                                human_time_diff($ts, current_time('timestamp')) . ' ' . __('ago', 'bs-awo-jobs')
                            )
                        );
                        echo ' – ';
                        if ($lastRun->status === 'success') {
                            echo esc_html(
                                sprintf(
                                    /* translators: %d: Anzahl Jobs */
                                    __('Erfolg (%d Jobs)', 'bs-awo-jobs'),
                                    (int) $lastRun->jobs_count
                                )
                            );
                        } else {
                            echo esc_html__('Fehlgeschlagen', 'bs-awo-jobs');
                            if (! empty($lastRun->error_message)) {
                                echo ': ' . esc_html($lastRun->error_message);
                            }
                        }
                        ?>
                    </li>
                </ul>
            <?php endif; ?>

            <?php if (! empty($recentRuns)) : ?>
                <h3><?php echo esc_html__('Letzte Sync-Läufe', 'bs-awo-jobs'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Datum', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Uhrzeit', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Status', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Jobs im Snapshot', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRuns as $run) : ?>
                            <?php
                            $tsRun = strtotime($run->run_timestamp);
                            ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('Y-m-d', $tsRun)); ?></td>
                                <td><?php echo esc_html(date_i18n('H:i', $tsRun)); ?></td>
                                <td>
                                    <?php
                                    if ($run->status === 'success') {
                                        echo esc_html__('Erfolg', 'bs-awo-jobs');
                                    } else {
                                        echo esc_html__('Fehlgeschlagen', 'bs-awo-jobs');
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(number_format_i18n((int) $run->jobs_count)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (is_array($anomaly) && ! empty($anomaly['date'])) : ?>
                <?php
                $anomalyTs = strtotime($anomaly['date']);
                if ($anomalyTs && $anomalyTs > (current_time('timestamp') - 7 * DAY_IN_SECONDS)) :
                    ?>
                    <div class="notice notice-warning">
                        <p>
                            <strong><?php echo esc_html__('⚠️ Anomalie erkannt', 'bs-awo-jobs'); ?></strong><br />
                            <?php echo esc_html($anomaly['details']); ?>
                        </p>
                        <p>
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: Link zum Events-Log */
                                    __('Siehe Details im <a href="%s">Events Log</a>.', 'bs-awo-jobs'),
                                    esc_url(
                                        add_query_arg(
                                            ['page' => 'bs-awo-jobs-events'],
                                            admin_url('admin.php')
                                        )
                                    )
                                )
                            );
                            ?>
                        </p>
                    </div>
                    <?php
                endif;
            endif;
            ?>

            <?php
            $collisions = get_option(\BsAwoJobs\Wp\Cron::OPTION_LAST_FACILITY_COLLISIONS);
            if (is_array($collisions) && ! empty($collisions['collisions'])) :
                $count = (int) ($collisions['count'] ?? 0);
                $list  = $collisions['collisions'] ?? [];
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php echo esc_html__('Facility-ID-Kollisionen erkannt', 'bs-awo-jobs'); ?></strong><br />
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: Anzahl Kollisionen */
                                _n(
                                    'Es wurde %d potenzielle Kollision erkannt: Mehrere unterschiedliche Einrichtungen teilen sich dieselbe facility_id.',
                                    'Es wurden %d potenzielle Kollisionen erkannt: Mehrere unterschiedliche Einrichtungen teilen sich dieselbe facility_id.',
                                    $count,
                                    'bs-awo-jobs'
                                ),
                                $count
                            )
                        );
                        ?>
                    </p>
                    <table class="widefat striped" style="max-width: 800px; margin-top: 0.5em;">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('facility_id', 'bs-awo-jobs'); ?></th>
                                <th><?php echo esc_html__('Varianten', 'bs-awo-jobs'); ?></th>
                                <th><?php echo esc_html__('Namen / Adressen', 'bs-awo-jobs'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list as $c) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($c['facility_id'] ?? ''); ?></code></td>
                                    <td><?php echo esc_html((string) ($c['variants'] ?? 0)); ?></td>
                                    <td><?php echo esc_html($c['names'] ?? ''); ?> — <?php echo esc_html($c['addresses'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr />

            <h2><?php echo esc_html__('Manueller Sync', 'bs-awo-jobs'); ?></h2>
            <p>
                <?php
                echo esc_html__(
                    'Führt einen sofortigen Abruf der AWO-Jobs durch, speichert den Snapshot und aktualisiert die aktuelle Job-Tabelle.',
                    'bs-awo-jobs'
                );
                ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="bs-awo-jobs-sync-form">
                <?php wp_nonce_field(self::NONCE_SYNC_NOW); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_sync_now" />
                <?php submit_button(__('Jetzt synchronisieren', 'bs-awo-jobs'), 'primary', 'submit', false); ?>
            </form>
            <script>
            (function(){
                var form = document.getElementById('bs-awo-jobs-sync-form');
                if (!form) return;
                form.addEventListener('submit', function(){
                    var btn = form.querySelector('input[type="submit"], button[type="submit"]');
                    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
                    var span = document.createElement('span');
                    span.className = 'bs-awo-jobs-sync-spinner';
                    span.style.marginLeft = '8px';
                    span.style.display = 'inline-block';
                    span.style.width = '18px';
                    span.style.height = '18px';
                    span.style.border = '2px solid #ccc';
                    span.style.borderTopColor = '#0073aa';
                    span.style.borderRadius = '50%';
                    span.style.animation = 'bs-awo-jobs-spin 0.8s linear infinite';
                    span.setAttribute('aria-hidden', 'true');
                    if (btn && btn.parentNode) btn.parentNode.appendChild(span);
                });
            })();
            </script>
            <style>@keyframes bs-awo-jobs-spin{to{transform:rotate(360deg);}}</style>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 1em;">
                <?php if (defined('BS_AWO_JOBS_ENABLE_FORCE_RESYNC') && BS_AWO_JOBS_ENABLE_FORCE_RESYNC) : ?>
                    <?php wp_nonce_field(self::NONCE_FORCE_RESYNC); ?>
                    <input type="hidden" name="action" value="bs_awo_jobs_force_resync" />
                    <?php submit_button(__('Force Full Resync', 'bs-awo-jobs'), 'secondary', 'submit', false); ?>
                    <p class="description">
                        <?php echo esc_html__('Nur für Entwicklungs-/Debugzwecke. Löscht Events und aktuelle Jobs.', 'bs-awo-jobs'); ?>
                    </p>
                <?php else : ?>
                    <button type="button" class="button-secondary" disabled="disabled">
                        <?php echo esc_html__('Force Full Resync (deaktiviert)', 'bs-awo-jobs'); ?>
                    </button>
                    <p class="description">
                        <?php
                        echo esc_html__(
                            'Dieser Befehl würde die Event-Historie löschen und ist im normalen Betrieb deaktiviert. ' .
                            'Bei Bedarf kann er im Code über die Konstante BS_AWO_JOBS_ENABLE_FORCE_RESYNC aktiviert werden.',
                            'bs-awo-jobs'
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </form>

            <hr />

            <h2><?php echo esc_html__('Daten sichern & wiederherstellen', 'bs-awo-jobs'); ?></h2>
            <p class="description">
                <?php
                echo esc_html__(
                    'Backup herunterladen sichert alle Plugin-Daten (Runs, Jobs, Events, Facilities). Bei einer Neuinstallation kannst du die Datei hier wieder importieren.',
                    'bs-awo-jobs'
                );
                ?>
            </p>
            <p>
                <?php
                $exportUrl = add_query_arg(
                    [
                        'action'   => 'bs_awo_jobs_export_backup',
                        '_wpnonce' => wp_create_nonce(self::NONCE_EXPORT_BACKUP),
                    ],
                    admin_url('admin-post.php')
                );
                ?>
                <a href="<?php echo esc_url($exportUrl); ?>" class="button button-secondary">
                    <?php echo esc_html__('Backup herunterladen (JSON)', 'bs-awo-jobs'); ?>
                </a>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-top: 1em;">
                <?php wp_nonce_field(self::NONCE_IMPORT_BACKUP); ?>
                <input type="hidden" name="action" value="bs_awo_jobs_import_backup" />
                <label for="bs_awo_jobs_backup_file"><?php echo esc_html__('Backup-Datei (JSON) hochladen', 'bs-awo-jobs'); ?></label>
                <input type="file" id="bs_awo_jobs_backup_file" name="bs_awo_jobs_backup_file" accept=".json,application/json" />
                <?php submit_button(__('Backup importieren', 'bs-awo-jobs'), 'secondary', 'submit', false); ?>
                <p class="description">
                    <?php echo esc_html__('Ersetzt alle aktuellen Plugin-Daten durch die importierten. Nur eine zuvor von diesem Plugin exportierte JSON-Datei verwenden.', 'bs-awo-jobs'); ?>
                </p>
            </form>

            <p>
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: %s: Link zur Schema-Inspector-Seite */
                        __('Nach einem Sync findest du den Schema-Inspector unter <a href="%s">AWO Jobs → Schema Inspector</a>.', 'bs-awo-jobs'),
                        esc_url(
                            add_query_arg(
                                ['page' => 'bs-awo-jobs-schema'],
                                admin_url('admin.php')
                            )
                        )
                    )
                );
                ?>
            </p>
            
            <?php
                // Plugin-Footer
                \BsAwoJobs\Wp\Admin\Footer::render();
                ?>
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

        if (class_exists('\BsAwoJobs\Wp\Cron')) {
            \BsAwoJobs\Wp\Cron::reschedule();
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
            $facilityAddress = '';
            $street          = isset($job['Strasse']) ? (string) $job['Strasse'] : '';
            $plz             = isset($job['PLZ']) ? (string) $job['PLZ'] : '';
            $ort             = isset($job['Ort']) ? (string) $job['Ort'] : '';
            if ($street || $plz || $ort) {
                $facilityAddress = trim($street . ', ' . $plz . ' ' . $ort, " ,");
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
                'job_id'            => $jobId,
                'facility_id'       => $facilityId,
                'facility_name'     => $facilityName,
                'facility_address'  => $facilityAddress,
                'department_api'    => $departmentApi,
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
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
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

        self::sync_facilities_from_jobs();

        // Filter-Optionen-Cache invalidieren (Frontend-Dropdowns).
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_custom');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api_id');
        delete_transient(\BsAwoJobs\Wp\Shortcodes\JobBoard::TRANSIENT_FILTER_OPTS_PREFIX . 'department_api');
    }

    /**
     * Füllt die optionale Tabelle bsawo_facilities aus bsawo_jobs_current (eine Zeile pro facility_id).
     *
     * @return void
     */
    public static function sync_facilities_from_jobs()
    {
        global $wpdb;

        if (! ($wpdb instanceof \wpdb)) {
            return;
        }

        $jobsTable = $wpdb->prefix . 'bsawo_jobs_current';
        $facTable  = $wpdb->prefix . 'bsawo_facilities';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $facTable));
        if ($exists !== $facTable) {
            return;
        }

        $wpdb->query(
            "INSERT INTO {$facTable} (facility_id, canonical_name, normalized_address)
             SELECT facility_id, MIN(facility_name), MIN(facility_address)
             FROM {$jobsTable}
             GROUP BY facility_id
             ON DUPLICATE KEY UPDATE
                canonical_name = VALUES(canonical_name),
                normalized_address = VALUES(normalized_address)"
        );
    }

    /**
     * Führt einen Force-Resync aus:
     * - Löscht Events und aktuelle Jobs
     * - Führt einen neuen Sync durch
     *
     * @return void
     */
    public static function handle_force_resync()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        if (! (defined('BS_AWO_JOBS_ENABLE_FORCE_RESYNC') && BS_AWO_JOBS_ENABLE_FORCE_RESYNC)) {
            wp_die(esc_html__('Force Full Resync ist im normalen Betrieb deaktiviert.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_FORCE_RESYNC);

        global $wpdb;

        $jobsTable   = $wpdb->prefix . 'bsawo_jobs_current';
        $eventsTable = $wpdb->prefix . 'bsawo_events';

        $wpdb->query("TRUNCATE TABLE `{$jobsTable}`");
        $wpdb->query("TRUNCATE TABLE `{$eventsTable}`");

        $result = \BsAwoJobs\Wp\Cron::run_sync('manual');

        update_option(
            self::OPTION_LAST_SYNC_MESSAGE,
            sprintf(
                /* translators: %s: Ergebnisnachricht */
                __('Force Resync: %s', 'bs-awo-jobs'),
                $result['message']
            )
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => BS_AWO_JOBS_MENU_SLUG],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Exportiert alle Plugin-Tabellen als JSON-Backup (Download).
     *
     * @return void
     */
    public static function handle_export_backup()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_EXPORT_BACKUP);

        global $wpdb;

        $prefix = $wpdb->prefix;
        $runsTable   = $prefix . 'bsawo_runs';
        $jobsTable   = $prefix . 'bsawo_jobs_current';
        $eventsTable = $prefix . 'bsawo_events';
        $facTable    = $prefix . 'bsawo_facilities';

        $runs    = [];
        $jobs    = [];
        $events  = [];
        $facilities = [];

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runsTable)) === $runsTable) {
            $rows = $wpdb->get_results("SELECT * FROM `{$runsTable}`", ARRAY_A);
            $runs = $rows ? $rows : [];
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobsTable)) === $jobsTable) {
            $rows = $wpdb->get_results("SELECT * FROM `{$jobsTable}`", ARRAY_A);
            $jobs = $rows ? $rows : [];
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $eventsTable)) === $eventsTable) {
            $rows = $wpdb->get_results("SELECT * FROM `{$eventsTable}`", ARRAY_A);
            $events = $rows ? $rows : [];
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $facTable)) === $facTable) {
            $rows = $wpdb->get_results("SELECT * FROM `{$facTable}`", ARRAY_A);
            $facilities = $rows ? $rows : [];
        }

        $data = [
            'version'     => self::BACKUP_FORMAT_VERSION,
            'exported_at' => current_time('c'),
            'tables'      => [
                'runs'      => $runs,
                'jobs_current' => $jobs,
                'events'    => $events,
                'facilities'=> $facilities,
            ],
        ];

        $filename = 'bs-awo-jobs-backup-' . gmdate('Y-m-d-His') . '.json';
        $json     = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($json));

        echo $json;
        exit;
    }

    /**
     * Importiert ein JSON-Backup und ersetzt die Plugin-Tabellen.
     *
     * @return void
     */
    public static function handle_import_backup()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_IMPORT_BACKUP);

        if (empty($_FILES['bs_awo_jobs_backup_file']['tmp_name']) || ! is_uploaded_file($_FILES['bs_awo_jobs_backup_file']['tmp_name'])) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => BS_AWO_JOBS_MENU_SLUG, 'bs_awo_jobs_import' => 'no_file'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $file = $_FILES['bs_awo_jobs_backup_file'];
        $max_size = 50 * 1024 * 1024; // 50 MB
        if (isset($file['size']) && (int) $file['size'] > $max_size) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => BS_AWO_JOBS_MENU_SLUG, 'bs_awo_jobs_import' => 'too_large'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $allowed_mimes = ['application/json', 'text/plain'];
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            if ($mime === '' || ! in_array($mime, $allowed_mimes, true)) {
                wp_safe_redirect(
                    add_query_arg(
                        ['page' => BS_AWO_JOBS_MENU_SLUG, 'bs_awo_jobs_import' => 'invalid_type'],
                        admin_url('admin.php')
                    )
                );
                exit;
            }
        }

        $raw = file_get_contents($file['tmp_name']);
        $data = json_decode($raw, true);

        if (! is_array($data) || empty($data['tables']) || ! isset($data['version'])) {
            wp_safe_redirect(
                add_query_arg(
                    ['page' => BS_AWO_JOBS_MENU_SLUG, 'bs_awo_jobs_import' => 'invalid'],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        global $wpdb;

        $prefix = $wpdb->prefix;
        $runsTable   = $prefix . 'bsawo_runs';
        $jobsTable   = $prefix . 'bsawo_jobs_current';
        $eventsTable = $prefix . 'bsawo_events';
        $facTable    = $prefix . 'bsawo_facilities';

        $tables = $data['tables'];

        $runsExists    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runsTable)) === $runsTable;
        $jobsExists    = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobsTable)) === $jobsTable;
        $eventsExists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $eventsTable)) === $eventsTable;
        $facExists     = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $facTable)) === $facTable;

        if ($eventsExists) {
            $wpdb->query("TRUNCATE TABLE `{$eventsTable}`");
        }
        if ($jobsExists) {
            $wpdb->query("TRUNCATE TABLE `{$jobsTable}`");
        }
        if ($facExists) {
            $wpdb->query("TRUNCATE TABLE `{$facTable}`");
        }
        if ($runsExists) {
            $wpdb->query("TRUNCATE TABLE `{$runsTable}`");
        }

        if ($runsExists && ! empty($tables['runs']) && is_array($tables['runs'])) {
            foreach ($tables['runs'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $wpdb->insert($runsTable, $row, null);
            }
        }

        if ($jobsExists && ! empty($tables['jobs_current']) && is_array($tables['jobs_current'])) {
            foreach ($tables['jobs_current'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                unset($row['id']);
                $wpdb->insert($jobsTable, $row, null);
            }
        }

        if ($eventsExists && ! empty($tables['events']) && is_array($tables['events'])) {
            foreach ($tables['events'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                unset($row['id']);
                $wpdb->insert($eventsTable, $row, null);
            }
        }

        if ($facExists && ! empty($tables['facilities']) && is_array($tables['facilities'])) {
            foreach ($tables['facilities'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                unset($row['id']);
                $wpdb->insert($facTable, $row, null);
            }
        }

        update_option(
            self::OPTION_LAST_SYNC_MESSAGE,
            __('Backup wurde erfolgreich importiert.', 'bs-awo-jobs')
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => BS_AWO_JOBS_MENU_SLUG, 'bs_awo_jobs_import' => 'ok'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}