<?php

namespace BsAwoJobs\Wp\Admin;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

if (! defined('ABSPATH')) {
    exit;
}

class StatsPage
{
    /**
     * Option-Key für VZE-Mapping (BA-Zeiteinteilung → Dezimalwert).
     */
    const OPTION_VZE_MAPPING = 'bs_awo_jobs_vze_mapping';

    /**
     * Nonce für den Fluktuations-Upload.
     */
    const NONCE_IMPORT_STATS = 'bs_awo_jobs_import_stats';

    /**
     * Nonce für das Speichern des VZE-Mappings.
     */
    const NONCE_SAVE_VZE_MAPPING = 'bs_awo_jobs_save_vze_mapping';

    /**
     * Bootstrap.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_bs_awo_jobs_import_stats', [self::class, 'handle_import_stats']);
        add_action('admin_post_bs_awo_jobs_save_vze_mapping', [self::class, 'handle_save_vze_mapping']);
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
        $statsTable       = $wpdb->prefix . 'bs_awo_stats';
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

        // Basis-Kennzahl für Fluktuations-Upload.
        $statsCount = null;
        $statsTableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $statsTable)) === $statsTable;
        if ($statsTableExists) {
            $statsCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$statsTable}");
        }

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
                <?php
                $importStatus = isset($_GET['bs_awo_stats_import']) ? sanitize_text_field(wp_unslash($_GET['bs_awo_stats_import'])) : '';
                $inserted     = isset($_GET['inserted']) ? (int) $_GET['inserted'] : 0;
                $updated      = isset($_GET['updated']) ? (int) $_GET['updated'] : 0;

                if ($importStatus === 'ok') :
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p>
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: 1: inserted rows, 2: updated rows */
                                    __('Excel-Import erfolgreich: %1$d neue Zeilen, %2$d aktualisierte Zeilen.', 'bs-awo-jobs'),
                                    $inserted,
                                    $updated
                                )
                            );
                            ?>
                        </p>
                    </div>
                <?php elseif ($importStatus === 'no_file') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Es wurde keine Datei ausgewählt oder der Upload ist fehlgeschlagen.', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php elseif ($importStatus === 'too_large') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Die Datei ist zu groß (max. 10 MB).', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php elseif ($importStatus === 'invalid_type') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Ungültiger Dateityp. Es sind nur .xlsx-Dateien erlaubt.', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php elseif ($importStatus === 'missing_header') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Die Kopfzeile der Excel-Datei enthält nicht alle erforderlichen Spalten (mindestens „S-Nr“).', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php elseif ($importStatus === 'parse_error') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Die Excel-Datei konnte nicht gelesen werden. Bitte Struktur und Inhalt prüfen.', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php elseif ($importStatus === 'no_table') : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Die Zieltabelle für Fluktuations-Statistiken (bs_awo_stats) existiert nicht.', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php endif; ?>

                <h2><?php echo esc_html__('Fluktuation – Excel-Import', 'bs-awo-jobs'); ?></h2>
                <p class="description">
                    <?php
                    echo esc_html__(
                        'Lade hier den Excel-Export aus der AWO-Stellenbörse hoch. Die Daten werden in die Tabelle bs_awo_stats geschrieben (S-Nr als eindeutiger Schlüssel).',
                        'bs-awo-jobs'
                    );
                    ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-top: 1em;">
                    <?php wp_nonce_field(self::NONCE_IMPORT_STATS); ?>
                    <input type="hidden" name="action" value="bs_awo_jobs_import_stats" />

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="bs_awo_jobs_stats_file">
                                    <?php echo esc_html__('Excel-Datei (.xlsx)', 'bs-awo-jobs'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="file"
                                    id="bs_awo_jobs_stats_file"
                                    name="bs_awo_jobs_stats_file"
                                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                />
                                <p class="description">
                                    <?php
                                    echo esc_html__(
                                        'Erwartete Spalten (Kopfzeile): S-Nr, Erstellt am, Start, Stop, Titel, Fachbereich, Internes Kürzel, Einrichtung, Straße/Nr, PLZ, Einsatzort, Vertragsart, Anstellungsart.',
                                        'bs-awo-jobs'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Excel importieren', 'bs-awo-jobs'), 'primary'); ?>
                </form>

                <?php if ($statsTableExists) : ?>
                    <hr />
                    <h3><?php echo esc_html__('Aktueller Stand der Fluktuations-Daten', 'bs-awo-jobs'); ?></h3>
                    <p>
                        <strong><?php echo esc_html__('Einträge in bs_awo_stats', 'bs-awo-jobs'); ?>:</strong>
                        <?php echo esc_html(number_format_i18n((int) $statsCount)); ?>
                    </p>
                    <p class="description">
                        <?php echo esc_html__('Für Detailprüfungen kannst du die Tabelle bs_awo_stats direkt in der Datenbank ansehen.', 'bs-awo-jobs'); ?>
                    </p>
                <?php endif; ?>

                <hr />

                <?php
                // VZE-Mapping-Status (Option).
                $vzeMapping = get_option(self::OPTION_VZE_MAPPING, []);
                if (! is_array($vzeMapping)) {
                    $vzeMapping = [];
                }

                $vzeStatus = isset($_GET['bs_awo_vze_mapping']) ? sanitize_text_field(wp_unslash($_GET['bs_awo_vze_mapping'])) : '';

                if ($vzeStatus === 'ok') :
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php echo esc_html__('VZE-Mapping wurde gespeichert.', 'bs-awo-jobs'); ?></p>
                    </div>
                <?php endif; ?>

                <h3><?php echo esc_html__('VZE-Mapping (BA-Zeiteinteilung → Dezimalwert)', 'bs-awo-jobs'); ?></h3>
                <p class="description">
                    <?php
                    echo esc_html__(
                        'Trage hier die Rohwerte aus der Spalte „BA Zeiteinteilung“ ein (z. B. „Vollzeit“, „Teilzeit - flexibel“) und weise ihnen einen VZE-Wert zu (z. B. 1.0, 0.5).',
                        'bs-awo-jobs'
                    );
                    ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 1em; max-width: 640px;">
                    <?php wp_nonce_field(self::NONCE_SAVE_VZE_MAPPING); ?>
                    <input type="hidden" name="action" value="bs_awo_jobs_save_vze_mapping" />

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('BA Zeiteinteilung (Rohwert)', 'bs-awo-jobs'); ?></th>
                                <th><?php echo esc_html__('VZE-Wert (Dezimalzahl)', 'bs-awo-jobs'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rows = $vzeMapping;
                            // Mindestens drei Zeilen anzeigen.
                            $minRows = 3;
                            $currentCount = is_countable($rows) ? count($rows) : 0;
                            if ($currentCount < $minRows) {
                                $rows = $rows + array_fill(0, $minRows - $currentCount, ['label' => '', 'value' => '']);
                            }
                            foreach ($rows as $entry) :
                                $label = is_array($entry) && isset($entry['label']) ? (string) $entry['label'] : '';
                                $value = is_array($entry) && isset($entry['value']) ? (string) $entry['value'] : '';
                                ?>
                                <tr>
                                    <td>
                                        <input
                                            type="text"
                                            name="bs_awo_jobs_vze_label[]"
                                            value="<?php echo esc_attr($label); ?>"
                                            class="regular-text"
                                        />
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            name="bs_awo_jobs_vze_value[]"
                                            value="<?php echo esc_attr($value); ?>"
                                            class="small-text"
                                        />
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php submit_button(__('VZE-Mapping speichern', 'bs-awo-jobs'), 'secondary', 'submit', false, ['style' => 'margin-top: 1em;']); ?>
                    <p class="description">
                        <?php echo esc_html__('Nach dem Speichern wird das Mapping beim nächsten Excel-Import für die Berechnung von vze_wert verwendet.', 'bs-awo-jobs'); ?>
                    </p>
                </form>
            </div>
            
            <?php
                // Plugin-Footer
                \BsAwoJobs\Wp\Admin\Footer::render();
                ?>
        </div>
        <?php
    }

    /**
     * Verarbeitet den Excel-Upload für Fluktuations-Statistiken.
     *
     * Erwartet eine .xlsx-Datei mit Kopfzeile (erste Zeile) und den relevanten Spalten.
     *
     * @return void
     */
    public static function handle_import_stats()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_IMPORT_STATS);

        if (empty($_FILES['bs_awo_jobs_stats_file']['tmp_name']) || ! is_uploaded_file($_FILES['bs_awo_jobs_stats_file']['tmp_name'])) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'no_file',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $file = $_FILES['bs_awo_jobs_stats_file'];

        // Größenlimit (10 MB), um versehentliche Riesen-Uploads zu vermeiden.
        $maxSize = 10 * 1024 * 1024;
        if (isset($file['size']) && (int) $file['size'] > $maxSize) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'too_large',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        // Nur .xlsx erlauben (MIME + Dateiendung).
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream', // einige PHP-Setups melden XLSX generisch als octet-stream
        ];

        $tmpName = $file['tmp_name'];
        $mime    = '';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
        }

        $extension = '';
        if (isset($file['name']) && is_string($file['name'])) {
            $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        }

        if ($extension !== 'xlsx' || ($mime !== '' && ! in_array($mime, $allowedMimes, true))) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'invalid_type',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        if (! class_exists(IOFactory::class)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'parse_error',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'bs_awo_stats';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists !== $table) {
            // Fallback: versuchen, die Tabelle über den Activation-Helper zu erzeugen.
            if (class_exists('\BsAwoJobs\Wp\Activation')) {
                \BsAwoJobs\Wp\Activation::ensure_stats_table();
                $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            }
        }

        if ($exists !== $table) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'no_table',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        try {
            $spreadsheet = IOFactory::load($tmpName);
            $sheet       = $spreadsheet->getActiveSheet();
        } catch (\Throwable $e) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'parse_error',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $highestRow    = (int) $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColIdx = (int) Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 2) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'missing_header',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        // Kopfzeile (erste Zeile) einlesen und auf bekannte Spalten mappen.
        $headerMap = [];
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $rawHeader = (string) self::get_cell_value($sheet, $col, 1);
            $normalized = self::normalize_header_label($rawHeader);
            if ($normalized === '') {
                continue;
            }
            $mapped = self::map_header_to_field($normalized);
            if ($mapped !== null) {
                $headerMap[$mapped] = $col;
            }
        }

        // Mindestens S-Nr für s_nr muss vorhanden sein.
        if (! isset($headerMap['s_nr'])) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'                => 'bs-awo-jobs-stats',
                        'tab'                 => 'fluktuation',
                        'bs_awo_stats_import' => 'missing_header',
                    ],
                    admin_url('admin.php')
                )
            );
            exit;
        }

        $inserted = 0;
        $updated  = 0;

        for ($row = 2; $row <= $highestRow; $row++) {
            $sNr = '';
            if (isset($headerMap['s_nr'])) {
                $sNr = trim((string) self::get_cell_value($sheet, $headerMap['s_nr'], $row));
            }

            if ($sNr === '') {
                continue;
            }

            $erstelltAm  = isset($headerMap['erstellt_am'])
                ? self::normalize_excel_datetime(self::get_cell_value($sheet, $headerMap['erstellt_am'], $row))
                : null;
            $startDate   = isset($headerMap['start_date'])
                ? self::normalize_excel_datetime(self::get_cell_value($sheet, $headerMap['start_date'], $row))
                : null;
            $stopDate    = isset($headerMap['stop_date'])
                ? self::normalize_excel_datetime(self::get_cell_value($sheet, $headerMap['stop_date'], $row))
                : null;
            $baRaw       = isset($headerMap['ba_zeiteinteilung_raw'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['ba_zeiteinteilung_raw'], $row))
                : '';
            $titel       = isset($headerMap['job_titel'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['job_titel'], $row))
                : '';
            $fachExt     = isset($headerMap['fachbereich_ext'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['fachbereich_ext'], $row))
                : '';
            $fachInt     = isset($headerMap['fachbereich_int'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['fachbereich_int'], $row))
                : '';
            $vertragsart = isset($headerMap['vertragsart'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['vertragsart'], $row))
                : '';
            $anstellungsart = isset($headerMap['anstellungsart'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['anstellungsart'], $row))
                : '';
            $einrichtung = isset($headerMap['einrichtung'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['einrichtung'], $row))
                : '';

            // Adressbestandteile ggf. zu einem Ort-String zusammenführen.
            $strasse = isset($headerMap['strasse'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['strasse'], $row))
                : '';
            $plz     = isset($headerMap['plz'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['plz'], $row))
                : '';
            $einsatzort = isset($headerMap['einsatzort'])
                ? self::normalize_string_cell(self::get_cell_value($sheet, $headerMap['einsatzort'], $row))
                : '';

            $ortParts = [];
            if ($strasse !== '') {
                $ortParts[] = $strasse;
            }
            $plzOrt = trim($plz . ' ' . $einsatzort);
            if ($plzOrt !== '') {
                $ortParts[] = $plzOrt;
            }
            $ort = implode(', ', $ortParts);

            $sql = "
                INSERT INTO {$table}
                    (s_nr, erstellt_am, start_date, stop_date, job_titel, fachbereich_ext, fachbereich_int, vertragsart, anstellungsart, einrichtung, ort, ba_zeiteinteilung_raw, vze_wert)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %f)
                ON DUPLICATE KEY UPDATE
                    erstellt_am = VALUES(erstellt_am),
                    start_date = VALUES(start_date),
                    stop_date = VALUES(stop_date),
                    job_titel = VALUES(job_titel),
                    fachbereich_ext = VALUES(fachbereich_ext),
                    fachbereich_int = VALUES(fachbereich_int),
                    vertragsart = VALUES(vertragsart),
                    anstellungsart = VALUES(anstellungsart),
                    einrichtung = VALUES(einrichtung),
                    ort = VALUES(ort),
                    ba_zeiteinteilung_raw = VALUES(ba_zeiteinteilung_raw)
            ";

            $prepared = $wpdb->prepare(
                $sql,
                $sNr,
                $erstelltAm,
                $startDate,
                $stopDate,
                $titel,
                $fachExt,
                $fachInt,
                $vertragsart,
                $anstellungsart,
                $einrichtung,
                $ort,
                $baRaw,
                0.0 // vze_wert wird in Phase 3 befüllt
            );

            $wpdb->query($prepared);

            $rowsAffected = (int) $wpdb->rows_affected;
            if ($rowsAffected === 1) {
                $inserted++;
            } elseif ($rowsAffected === 2) {
                $updated++;
            }
        }

        // Nach dem Import VZE-Werte gemäß Mapping berechnen.
        self::recalculate_vze_values();

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                => 'bs-awo-jobs-stats',
                    'tab'                 => 'fluktuation',
                    'bs_awo_stats_import' => 'ok',
                    'inserted'            => $inserted,
                    'updated'             => $updated,
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Speichert das VZE-Mapping aus dem Formular.
     *
     * @return void
     */
    public static function handle_save_vze_mapping()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_SAVE_VZE_MAPPING);

        $labels = isset($_POST['bs_awo_jobs_vze_label']) && is_array($_POST['bs_awo_jobs_vze_label'])
            ? array_map('wp_unslash', $_POST['bs_awo_jobs_vze_label'])
            : [];
        $values = isset($_POST['bs_awo_jobs_vze_value']) && is_array($_POST['bs_awo_jobs_vze_value'])
            ? array_map('wp_unslash', $_POST['bs_awo_jobs_vze_value'])
            : [];

        $mapping = [];
        $count   = max(count($labels), count($values));

        for ($i = 0; $i < $count; $i++) {
            $label = isset($labels[$i]) ? sanitize_text_field($labels[$i]) : '';
            $value = isset($values[$i]) ? trim((string) $values[$i]) : '';

            if ($label === '' || $value === '') {
                continue;
            }

            // Dezimaltrennzeichen flexibel: Komma oder Punkt akzeptieren.
            $normalizedValue = str_replace(',', '.', $value);
            if (! is_numeric($normalizedValue)) {
                continue;
            }

            $floatValue = (float) $normalizedValue;
            $mapping[] = [
                'label' => $label,
                'value' => $floatValue,
            ];
        }

        update_option(self::OPTION_VZE_MAPPING, $mapping);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'               => 'bs-awo-jobs-stats',
                    'tab'                => 'fluktuation',
                    'bs_awo_vze_mapping' => 'ok',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Berechnet vze_wert in bs_awo_stats basierend auf dem gespeicherten Mapping.
     *
     * @return void
     */
    private static function recalculate_vze_values()
    {
        global $wpdb;

        $mapping = get_option(self::OPTION_VZE_MAPPING, []);
        if (! is_array($mapping) || empty($mapping)) {
            return;
        }

        $table = $wpdb->prefix . 'bs_awo_stats';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        foreach ($mapping as $entry) {
            if (! is_array($entry) || empty($entry['label'])) {
                continue;
            }

            $label = (string) $entry['label'];
            $value = isset($entry['value']) ? (float) $entry['value'] : 0.0;

            // Exakter Match auf den Rohwert aus der Excel-Spalte.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET vze_wert = %f
                     WHERE ba_zeiteinteilung_raw = %s",
                    $value,
                    $label
                )
            );
        }
    }

    /**
     * Liefert den Zellwert für eine Spalte/Zeile über ein Spaltenindex-Mapping.
     * Verwendet Coordinate::stringFromColumnIndex(), um ältere PhpSpreadsheet-Versionen
     * ohne getCellByColumnAndRow() zu unterstützen.
     *
     * @param mixed $sheet
     * @param int   $columnIndex 1-basierter Spaltenindex
     * @param int   $rowIndex    1-basierter Zeilenindex
     * @return mixed
     */
    private static function get_cell_value($sheet, $columnIndex, $rowIndex)
    {
        $columnIndex = (int) $columnIndex;
        $rowIndex    = (int) $rowIndex;

        if ($columnIndex < 1 || $rowIndex < 1) {
            return null;
        }

        $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
        $coordinate   = $columnLetter . $rowIndex;

        try {
            return $sheet->getCell($coordinate)->getValue();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalisiert Headerlabels aus der Excel-Kopfzeile.
     *
     * @param string $label
     * @return string
     */
    private static function normalize_header_label($label)
    {
        $label = (string) $label;
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        $label = mb_strtolower($label, 'UTF-8');
        $label = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $label);
        $label = preg_replace('/\s+/', ' ', $label);

        return (string) $label;
    }

    /**
     * Mappt ein normalisiertes Headerlabel auf ein internes Feld.
     *
     * @param string $normalized
     * @return string|null
     */
    private static function map_header_to_field($normalized)
    {
        $map = [
            's-nr'               => 's_nr',
            'snr'                => 's_nr',
            's nr'               => 's_nr',
            'stellennummer'      => 's_nr',
            'erstellt am'        => 'erstellt_am',
            'erstelldatum'       => 'erstellt_am',
            'start'              => 'start_date',
            'startdatum'         => 'start_date',
            'start datum'        => 'start_date',
            'stop'               => 'stop_date',
            'stopdatum'          => 'stop_date',
            'stop datum'         => 'stop_date',
            'titel'              => 'job_titel',
            'title'              => 'job_titel',
            'stellenbezeichnung' => 'job_titel',
            'fachbereich'        => 'fachbereich_ext',
            'internes kuerzel'   => 'fachbereich_int',
            'internes kürzel'    => 'fachbereich_int',
            'internes kuerz'     => 'fachbereich_int',
            'einrichtung'        => 'einrichtung',
            'strasse/nr'         => 'strasse',
            'straße/nr'          => 'strasse',
            'strasse nr'         => 'strasse',
            'straße nr'          => 'strasse',
            'strasse'            => 'strasse',
            'straße'             => 'strasse',
            'plz'                => 'plz',
            'einsatzort'         => 'einsatzort',
            'ort'                => 'einsatzort',
            'ba zeiteinteilung'  => 'ba_zeiteinteilung_raw',
            'ba-zeiteinteilung'  => 'ba_zeiteinteilung_raw',
            'ba zeiteinteilung (rohform)' => 'ba_zeiteinteilung_raw',
            'vertragsart'        => 'vertragsart',
            'anstellungsart'     => 'anstellungsart',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return null;
    }

    /**
     * Normalisiert einen Zellwert als Datum/Zeit und gibt ein MySQL-DATETIME-Format zurück.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function normalize_excel_datetime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Numerischer Excel-Serienwert.
        if (is_numeric($value)) {
            try {
                $dt = SpreadsheetDate::excelToDateTimeObject($value);
                if ($dt instanceof \DateTimeInterface) {
                    return $dt->format('Y-m-d H:i:s');
                }
            } catch (\Throwable $e) {
                // Fallback auf unten.
            }
        }

        // Bereits ein DateTime-Objekt?
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        // String-Parsen.
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        $ts = strtotime($str);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Normalisiert einen Zellwert als String (getrimmt).
     *
     * @param mixed $value
     * @return string
     */
    private static function normalize_string_cell($value)
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}