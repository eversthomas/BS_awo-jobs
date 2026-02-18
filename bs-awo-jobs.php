<?php
/**
 * Plugin Name:       BS AWO Jobs
 * Plugin URI:        https://example.com/
 * Description:       Analytik-Backend für AWO-Stellenanzeigen (JSON-Snapshots, Schema-Inspector, manuelle Syncs).
 * Version:           0.1.0
 * Author:            Tom Evers
 * Text Domain:       bs-awo-jobs
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

// Plugin Konstanten.
define('BS_AWO_JOBS_VERSION', '0.1.0');
define('BS_AWO_JOBS_PLUGIN_FILE', __FILE__);
define('BS_AWO_JOBS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BS_AWO_JOBS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BS_AWO_JOBS_TEXTDOMAIN', 'bs-awo-jobs');
define('BS_AWO_JOBS_MENU_SLUG', 'bs-awo-jobs');
define('BS_AWO_JOBS_DEFAULT_API_URL', 'https://www.awo-jobs.de/stellenboerse-wesel.json');

// Optionaler Composer-Autoloader (z. B. für PhpSpreadsheet).
// Wird nur geladen, wenn im Plugin-Verzeichnis ein vendor/autoload.php existiert.
$bs_awo_jobs_vendor_autoload = BS_AWO_JOBS_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($bs_awo_jobs_vendor_autoload)) {
    require_once $bs_awo_jobs_vendor_autoload;
}

/**
 * AJAX-Handler für Frontend-Filter: setzt $_GET aus POST und rendert JobBoard (ohne Reload).
 */
function bs_awo_jobs_ajax_filter()
{
    check_ajax_referer('bs_awo_jobs_filter');

    $_GET['ort']         = isset($_POST['ort']) ? sanitize_text_field(wp_unslash($_POST['ort'])) : '';
    $_GET['fachbereich'] = isset($_POST['fachbereich']) ? sanitize_text_field(wp_unslash($_POST['fachbereich'])) : '';
    $_GET['jobfamily']   = isset($_POST['jobfamily']) ? sanitize_text_field(wp_unslash($_POST['jobfamily'])) : '';
    $_GET['vertragsart'] = isset($_POST['vertragsart']) ? sanitize_text_field(wp_unslash($_POST['vertragsart'])) : '';
    $_GET['layout']      = isset($_POST['layout']) ? sanitize_text_field(wp_unslash($_POST['layout'])) : '';
    $_GET['paged']       = isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1;

    $base_url = '';
    if (! empty($_POST['base_url']) && is_string($_POST['base_url'])) {
        $base_url = esc_url_raw(wp_unslash($_POST['base_url']), ['http', 'https']);
    }
    $base_url = $base_url !== '' ? $base_url : null;

    $atts = [
        'limit'       => isset($_POST['limit']) ? max(1, min(100, (int) $_POST['limit'])) : 10,
        'layout'      => isset($_POST['layout']) ? sanitize_text_field(wp_unslash($_POST['layout'])) : 'list',
        'ort'         => $_GET['ort'],
        'fachbereich' => $_GET['fachbereich'],
        'jobfamily'   => $_GET['jobfamily'],
        'vertragsart' => $_GET['vertragsart'],
    ];
    if ($base_url !== null) {
        $atts['_base_url'] = $base_url;
    }
    echo \BsAwoJobs\Wp\Shortcodes\JobBoard::render($atts);
    wp_die();
}

// PSR-4 Autoloader für Namespaces BsAwoJobs\Core\* und BsAwoJobs\Wp\*.
spl_autoload_register(
    function ($class) {
        if (strpos($class, 'BsAwoJobs\\') !== 0) {
            return;
        }

        $relative = substr($class, strlen('BsAwoJobs\\'));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        $file = BS_AWO_JOBS_PLUGIN_DIR . 'src/' . $relativePath;

        if (file_exists($file)) {
            require_once $file;
        }
    }
);

// Aktivierungshook: Datenbanktabellen anlegen.
register_activation_hook(
    __FILE__,
    function () {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        if (! class_exists('\BsAwoJobs\Wp\Activation')) {
            // Autoloader sollte dies eigentlich abdecken, falls Datei existiert.
        }

        if (class_exists('\BsAwoJobs\Wp\Activation')) {
            \BsAwoJobs\Wp\Activation::activate();
        }
    }
);

// Initialisierung.
add_action(
    'plugins_loaded',
    function () {
        // Übersetzungen laden (Domain: bs-awo-jobs, Ordner: languages/).
        load_plugin_textdomain(
            'bs-awo-jobs',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        // Dynamische Frontend-Styles
        if (class_exists('\BsAwoJobs\Wp\Assets\DynamicStyles')) {
            \BsAwoJobs\Wp\Assets\DynamicStyles::init();
        }

        // DB-Schema-Upgrades (z. B. neue Spalten in bsawo_events).
        if (class_exists('\BsAwoJobs\Wp\Activation')) {
            \BsAwoJobs\Wp\Activation::maybe_upgrade();
        }

        // Frontend-Shortcode [bs_awo_jobs]
        add_shortcode('bs_awo_jobs', [\BsAwoJobs\Wp\Shortcodes\JobBoard::class, 'render']);

        // AJAX-Filter: liefert HTML der Stellenliste ohne vollständigen Seiten-Reload.
        add_action('wp_ajax_bs_awo_jobs_filter', 'bs_awo_jobs_ajax_filter');
        add_action('wp_ajax_nopriv_bs_awo_jobs_filter', 'bs_awo_jobs_ajax_filter');

        /**
         * Vordefinierte Fachbereich-Shortcodes
         * Nutzen intern den Haupt-Renderer. fachbereich ist immer die Bezeichnung (Kita, Pflege, Verwaltung).
         * JobBoard filtert bei API-Fachbereich nach department_api (Bezeichnung), bei Mandantenfeld nach department_custom;
         * numerische Werte werden bei API als department_api_id interpretiert.
         */

        // Kita
        add_shortcode('bs_awo_jobs_kita', function ($atts = []) {
            return \BsAwoJobs\Wp\Shortcodes\JobBoard::render(array_merge($atts, [
                'fachbereich'        => 'Kita',
                'layout'             => 'grid',
                'limit'              => 50,
                'hide_filters'       => 1,
                'hide_layout_toggle' => 1,
            ]));
        });

        // Pflege
        add_shortcode('bs_awo_jobs_pflege', function ($atts = []) {
            return \BsAwoJobs\Wp\Shortcodes\JobBoard::render(array_merge($atts, [
                'fachbereich'        => 'Pflege',
                'layout'             => 'grid',
                'limit'              => 50,
                'hide_filters'       => 1,
                'hide_layout_toggle' => 1,
            ]));
        });

        // Verwaltung
        add_shortcode('bs_awo_jobs_verwaltung', function ($atts = []) {
            return \BsAwoJobs\Wp\Shortcodes\JobBoard::render(array_merge($atts, [
                'fachbereich'        => 'Verwaltung',
                'layout'             => 'grid',
                'limit'              => 50,
                'hide_filters'       => 1,
                'hide_layout_toggle' => 1,
            ]));
        });


        if (is_admin()) {
            // Admin-Seiten registrieren.
            if (class_exists('\BsAwoJobs\Wp\Admin\SettingsPage')) {
                \BsAwoJobs\Wp\Admin\SettingsPage::init();
            }

            if (class_exists('\BsAwoJobs\Wp\Admin\SchemaPage')) {
                \BsAwoJobs\Wp\Admin\SchemaPage::init();
            }

            if (class_exists('\BsAwoJobs\Wp\Admin\EventsPage')) {
                \BsAwoJobs\Wp\Admin\EventsPage::init();
            }

            if (class_exists('\BsAwoJobs\Wp\Admin\StatsPage')) {
                \BsAwoJobs\Wp\Admin\StatsPage::init();
            }
            
            if (class_exists('\BsAwoJobs\Wp\Admin\FrontendSettingsPage')) {
                \BsAwoJobs\Wp\Admin\FrontendSettingsPage::init();
            }

            // Admin-Assets laden.
            add_action(
                'admin_enqueue_scripts',
                function ($hook_suffix) {
                    // Nur auf unseren Plugin-Seiten laden.
                    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

                    if (
                        $page === BS_AWO_JOBS_MENU_SLUG
                        || $page === 'bs-awo-jobs-schema'
                        || $page === 'bs-awo-jobs-events'
                        || $page === 'bs-awo-jobs-stats'
                        || $page === 'bs-awo-jobs-frontend'
                    ) {
                        wp_enqueue_style(
                            'bs-awo-jobs-admin',
                            BS_AWO_JOBS_PLUGIN_URL . 'assets/admin.css',
                            [],
                            BS_AWO_JOBS_VERSION
                        );
                    }
                }
            );
        }
    }
);