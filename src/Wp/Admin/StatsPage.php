<?php

namespace BsAwoJobs\Wp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class StatsPage
{
    /**
     * Bootstrap.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * Registriert das Dashboard/Statistik-Submenü.
     *
     * @return void
     */
    public static function register_menu()
    {
        add_submenu_page(
            BS_AWO_JOBS_MENU_SLUG,
            __('Dashboard', 'bs-awo-jobs'),
            __('Dashboard', 'bs-awo-jobs'),
            'manage_options',
            'bs-awo-jobs-stats',
            [self::class, 'render_stats_page']
        );
    }

    /**
     * Enqueued Skripte/Styles für das Dashboard.
     *
     * @param string $hook_suffix
     * @return void
     */
    public static function enqueue_assets($hook_suffix)
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        if ($page !== 'bs-awo-jobs-stats') {
            return;
        }

        // Platzhalter für lokale Chart.js-Integration.
        // Erwartet wird eine lokale chart.umd.min.js Datei im Assets-Ordner.
        wp_register_script(
            'bs-awo-jobs-chartjs',
            BS_AWO_JOBS_PLUGIN_URL . 'assets/chart.umd.min.js',
            [],
            BS_AWO_JOBS_VERSION,
            true
        );

        wp_register_script(
            'bs-awo-jobs-dashboard-charts',
            BS_AWO_JOBS_PLUGIN_URL . 'assets/dashboard-charts.js',
            ['bs-awo-jobs-chartjs'],
            BS_AWO_JOBS_VERSION,
            true
        );

        wp_enqueue_script('bs-awo-jobs-dashboard-charts');
    }

    /**
     * Rendert das Statistik-Dashboard.
     *
     * @return void
     */
    public static function render_stats_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Du hast keine Berechtigung, diese Seite zu sehen.', 'bs-awo-jobs'));
        }

        global $wpdb;

        $jobsTable        = $wpdb->prefix . 'bsawo_jobs_current';
        $departmentSource = get_option(\BsAwoJobs\Wp\Admin\SettingsPage::OPTION_DEPARTMENT_SOURCE, 'api');

        // Tab aus URL für Persistenz bei Reload/Anwenden.
        $currentTab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'overview';
        if (! in_array($currentTab, ['overview', 'charts', 'fluktuation'], true)) {
            $currentTab = 'overview';
        }

        // Aktueller Zeitraum-Schieberegler (für spätere Zeitreisen über Events).
        $rangeDays = isset($_GET['range_days']) ? (int) $_GET['range_days'] : 90;
        if ($rangeDays < 7) {
            $rangeDays = 90;
        }

        // Grund-Kennzahlen.
        $totalJobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobsTable}");

        // Verteilung nach API-Fachbereich.
        $departmentsApi = $wpdb->get_results(
            "SELECT department_api_id, department_api, COUNT(*) AS jobs
             FROM {$jobsTable}
             WHERE department_api_id IS NOT NULL
               AND department_api_id <> ''
             GROUP BY department_api_id, department_api
             ORDER BY jobs DESC"
        );

        // Verteilung nach internem Fachbereich (Mandantnr/Einrichtungsnr).
        $departmentsCustom = $wpdb->get_results(
            "SELECT department_custom, COUNT(*) AS jobs
             FROM {$jobsTable}
             WHERE department_custom IS NOT NULL
               AND department_custom <> ''
             GROUP BY department_custom
             ORDER BY jobs DESC"
        );

        // Top-Einrichtungen nach Stellenanzahl.
        $facilities = $wpdb->get_results(
            "SELECT facility_id, facility_name, COUNT(*) AS jobs
             FROM {$jobsTable}
             GROUP BY facility_id, facility_name
             ORDER BY jobs DESC, facility_name ASC
             LIMIT 10"
        );

        // Daten für JS-Charts vorbereiten.
        $apiChartData = [
            'labels' => [],
            'values' => [],
        ];
        foreach ($departmentsApi as $row) {
            $apiChartData['labels'][] = $row->department_api ?: $row->department_api_id;
            $apiChartData['values'][] = (int) $row->jobs;
        }

        $customChartData = [
            'labels' => [],
            'values' => [],
        ];
        foreach ($departmentsCustom as $row) {
            $customChartData['labels'][] = $row->department_custom;
            $customChartData['values'][] = (int) $row->jobs;
        }

        $facilityChartData = [
            'labels' => [],
            'values' => [],
        ];
        foreach ($facilities as $row) {
            $facilityChartData['labels'][] = $row->facility_name ?: $row->facility_id;
            $facilityChartData['values'][] = (int) $row->jobs;
        }

        // Daten an das Dashboard-Skript übergeben.
        if (wp_script_is('bs-awo-jobs-dashboard-charts', 'enqueued')) {
            wp_localize_script(
                'bs-awo-jobs-dashboard-charts',
                'BsAwoJobsDashboardData',
                [
                    'totalJobs'        => $totalJobs,
                    'departmentSource' => $departmentSource,
                    'api'              => $apiChartData,
                    'custom'           => $customChartData,
                    'facility'         => $facilityChartData,
                ]
            );
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AWO Jobs – Dashboard', 'bs-awo-jobs'); ?></h1>

            <p class="description">
                <?php
                echo esc_html__(
                    'Dieses Dashboard zeigt den aktuellen Stand der aktiven Stellen. Zeitbasierte Auswertungen (Zeitreise) werden ergänzt, sobald genügend Verlaufsdaten vorliegen.',
                    'bs-awo-jobs'
                );
                ?>
            </p>
            <p class="description">
                <?php
                $sourceLabel = $departmentSource === 'custom'
                    ? __('Aktive Fachbereichs-Quelle für Auswertungen: Mandantenfeld', 'bs-awo-jobs')
                    : __('Aktive Fachbereichs-Quelle für Auswertungen: API Fachbereich', 'bs-awo-jobs');
                echo esc_html($sourceLabel);
                ?>
                —
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . BS_AWO_JOBS_MENU_SLUG)); ?>"><?php echo esc_html__('In Einstellungen ändern', 'bs-awo-jobs'); ?></a>
            </p>

            <div class="bs-awo-jobs-tabs" data-bs-awo-jobs-initial-tab="<?php echo esc_attr($currentTab); ?>">
                <button
                    type="button"
                    class="bs-awo-jobs-tab-button <?php echo $currentTab === 'overview' ? 'active' : ''; ?>"
                    data-bs-awo-jobs-tab="overview"
                >
                    <?php echo esc_html__('Übersicht', 'bs-awo-jobs'); ?>
                </button>
                <button
                    type="button"
                    class="bs-awo-jobs-tab-button <?php echo $currentTab === 'charts' ? 'active' : ''; ?>"
                    data-bs-awo-jobs-tab="charts"
                >
                    <?php echo esc_html__('Diagramme', 'bs-awo-jobs'); ?>
                </button>
                <button
                    type="button"
                    class="bs-awo-jobs-tab-button <?php echo $currentTab === 'fluktuation' ? 'active' : ''; ?>"
                    data-bs-awo-jobs-tab="fluktuation"
                >
                    <?php echo esc_html__('Fluktuation', 'bs-awo-jobs'); ?>
                </button>
            </div>

            <div class="bs-awo-jobs-tab-panel" data-bs-awo-jobs-panel="overview" <?php echo $currentTab !== 'overview' ? ' hidden' : ''; ?>>
                <div class="bs-awo-jobs-kpi-grid">
                <div class="bs-awo-jobs-kpi-box">
                    <div class="bs-awo-jobs-kpi-label">
                        <?php echo esc_html__('Aktive Jobs', 'bs-awo-jobs'); ?>
                    </div>
                    <div class="bs-awo-jobs-kpi-value">
                        <?php echo esc_html(number_format_i18n($totalJobs)); ?>
                    </div>
                </div>

                <div class="bs-awo-jobs-kpi-box">
                    <div class="bs-awo-jobs-kpi-label">
                        <?php
                        echo $departmentSource === 'custom'
                            ? esc_html__('Fachbereiche (aktiv: Mandantenfeld)', 'bs-awo-jobs')
                            : esc_html__('Fachbereiche (aktiv: API)', 'bs-awo-jobs');
                        ?>
                    </div>
                    <div class="bs-awo-jobs-kpi-value">
                        <?php
                        $activeDeptCount = $departmentSource === 'custom'
                            ? (is_countable($departmentsCustom) ? count($departmentsCustom) : 0)
                            : (is_countable($departmentsApi) ? count($departmentsApi) : 0);
                        echo esc_html(number_format_i18n($activeDeptCount));
                        ?>
                    </div>
                </div>

                <div class="bs-awo-jobs-kpi-box">
                    <div class="bs-awo-jobs-kpi-label">
                        <?php
                        echo $departmentSource === 'custom'
                            ? esc_html__('Fachbereiche (API, inaktiv)', 'bs-awo-jobs')
                            : esc_html__('Fachbereiche (Mandantenfeld, inaktiv)', 'bs-awo-jobs');
                        ?>
                    </div>
                    <div class="bs-awo-jobs-kpi-value">
                        <?php
                        $inactiveDeptCount = $departmentSource === 'custom'
                            ? (is_countable($departmentsApi) ? count($departmentsApi) : 0)
                            : (is_countable($departmentsCustom) ? count($departmentsCustom) : 0);
                        echo esc_html(number_format_i18n($inactiveDeptCount));
                        ?>
                    </div>
                </div>

                <div class="bs-awo-jobs-kpi-box">
                    <div class="bs-awo-jobs-kpi-label">
                        <?php echo esc_html__('Einrichtungen (Top 10)', 'bs-awo-jobs'); ?>
                    </div>
                    <div class="bs-awo-jobs-kpi-value">
                        <?php echo esc_html(number_format_i18n(is_countable($facilities) ? count($facilities) : 0)); ?>
                    </div>
                </div>
                </div>

                <hr />

                <h2><?php echo esc_html__('Zeitfenster (Vorbereitung)', 'bs-awo-jobs'); ?></h2>
                <form method="get" class="bs-awo-jobs-range-form">
                    <input type="hidden" name="page" value="bs-awo-jobs-stats" />
                    <input type="hidden" name="tab" value="overview" />
                    <label for="bs_awo_jobs_range_days">
                        <?php echo esc_html__('Zeitraum in Tagen (für zukünftige zeitbasierte Auswertungen)', 'bs-awo-jobs'); ?>
                    </label>
                    <input
                        type="range"
                        id="bs_awo_jobs_range_days"
                        name="range_days"
                        min="7"
                        max="365"
                        step="7"
                        value="<?php echo esc_attr($rangeDays); ?>"
                        oninput="document.getElementById('bs_awo_jobs_range_output').textContent = this.value;"
                    />
                    <span id="bs_awo_jobs_range_output"><?php echo esc_html($rangeDays); ?></span>
                    <?php submit_button(__('Aktualisieren', 'bs-awo-jobs'), 'secondary', '', false); ?>
                    <p class="description">
                        <?php echo esc_html__('Aktuell beeinflusst dieser Regler nur die Anzeige, nicht aber die Berechnung. Später werden hier Events über Zeiträume ausgewertet.', 'bs-awo-jobs'); ?>
                    </p>
                </form>

                <hr />

                <h2><?php echo esc_html__('Verteilung nach Fachbereich (API)', 'bs-awo-jobs'); ?></h2>
            <?php if (! empty($departmentsApi)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Fachbereich (API)', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Jobs', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Anteil', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departmentsApi as $row) : ?>
                        <?php
                        $jobs  = (int) $row->jobs;
                        $label = $row->department_api ?: $row->department_api_id;
                        $percent = $totalJobs > 0 ? round(($jobs / $totalJobs) * 100, 2) : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><?php echo esc_html(number_format_i18n($jobs)); ?></td>
                            <td>
                                <div class="bs-awo-jobs-bar-wrapper">
                                    <div
                                        class="bs-awo-jobs-bar"
                                        style="width: <?php echo esc_attr(min(100, $percent)); ?>%;"
                                    ></div>
                                    <span class="bs-awo-jobs-bar-label">
                                        <?php echo esc_html($percent . ' %'); ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('Keine Fachbereichsdaten (API) vorhanden.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Verteilung nach internem Fachbereich (Mandantenfeld)', 'bs-awo-jobs'); ?></h2>
            <p class="description">
                <?php
                echo esc_html__(
                    'Dieses Feld wird von AWO Wesel für interne Fachbereiche genutzt. Die Aussagekraft steigt, sobald das Mandantenfeld konsistent gepflegt ist.',
                    'bs-awo-jobs'
                );
                ?>
            </p>
            <?php if (! empty($departmentsCustom)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Interner Fachbereich (Mandantenfeld)', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Jobs', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Anteil', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departmentsCustom as $row) : ?>
                        <?php
                        $jobs    = (int) $row->jobs;
                        $label   = $row->department_custom;
                        $percent = $totalJobs > 0 ? round(($jobs / $totalJobs) * 100, 2) : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><?php echo esc_html(number_format_i18n($jobs)); ?></td>
                            <td>
                                <div class="bs-awo-jobs-bar-wrapper">
                                    <div
                                        class="bs-awo-jobs-bar bs-awo-jobs-bar-alt"
                                        style="width: <?php echo esc_attr(min(100, $percent)); ?>%;"
                                    ></div>
                                    <span class="bs-awo-jobs-bar-label">
                                        <?php echo esc_html($percent . ' %'); ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('Noch keine oder nur wenige Werte im Mandantenfeld vorhanden.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>

            <h2><?php echo esc_html__('Top-Einrichtungen nach Anzahl aktiver Jobs', 'bs-awo-jobs'); ?></h2>
            <?php if (! empty($facilities)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Jobs', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Anteil', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($facilities as $row) : ?>
                        <?php
                        $jobs    = (int) $row->jobs;
                        $label   = $row->facility_name ?: $row->facility_id;
                        $percent = $totalJobs > 0 ? round(($jobs / $totalJobs) * 100, 2) : 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><?php echo esc_html(number_format_i18n($jobs)); ?></td>
                            <td>
                                <div class="bs-awo-jobs-bar-wrapper">
                                    <div
                                        class="bs-awo-jobs-bar bs-awo-jobs-bar-facility"
                                        style="width: <?php echo esc_attr(min(100, $percent)); ?>%;"
                                    ></div>
                                    <span class="bs-awo-jobs-bar-label">
                                        <?php echo esc_html($percent . ' %'); ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('Keine Einrichtungen mit aktiven Jobs gefunden.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>
            </div>

            <div class="bs-awo-jobs-tab-panel" data-bs-awo-jobs-panel="charts"<?php echo $currentTab !== 'charts' ? ' hidden' : ''; ?>>
                <h2><?php echo esc_html__('Diagramme', 'bs-awo-jobs'); ?></h2>
                <p class="description">
                    <?php
                    echo esc_html__(
                        'Die Diagramme basieren auf den aktuell aktiven Jobs. Die Darstellung wird in zukünftigen Phasen um Zeitachsen (Events) erweitert.',
                        'bs-awo-jobs'
                    );
                    ?>
                </p>

                <h3><?php echo esc_html__('Fachbereiche (API)', 'bs-awo-jobs'); ?></h3>
                <canvas id="bs_awo_jobs_chart_api" width="400" height="200"></canvas>

                <h3><?php echo esc_html__('Interne Fachbereiche (Mandantenfeld)', 'bs-awo-jobs'); ?></h3>
                <canvas id="bs_awo_jobs_chart_custom" width="400" height="200"></canvas>

                <h3><?php echo esc_html__('Top-Einrichtungen', 'bs-awo-jobs'); ?></h3>
                <canvas id="bs_awo_jobs_chart_facility" width="400" height="240"></canvas>
            </div>

            <div class="bs-awo-jobs-tab-panel" data-bs-awo-jobs-panel="fluktuation"<?php echo $currentTab !== 'fluktuation' ? ' hidden' : ''; ?>>
                <p class="description"><?php echo esc_html__('Hier können später neue Funktionen ergänzt werden.', 'bs-awo-jobs'); ?></p>
            </div>
            
            <?php
                // Plugin-Footer
                \BsAwoJobs\Wp\Admin\Footer::render();
                ?>
        </div>
        <?php
    }
}