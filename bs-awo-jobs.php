<?php
/**
 * Plugin Name:       BS AWO Jobs
 * Description:       AWO-Stellenbörse: JSON-API-Sync und Anzeige per Shortcode mit konfigurierbarem Design.
 * Version:           2.0
 * Author:            Tom Evers
 * Text Domain:       bs-awo-jobs
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

// Plugin Konstanten.
define('BS_AWO_JOBS_VERSION', '2.0');
define('BS_AWO_JOBS_PLUGIN_FILE', __FILE__);
define('BS_AWO_JOBS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BS_AWO_JOBS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BS_AWO_JOBS_TEXTDOMAIN', 'bs-awo-jobs');
define('BS_AWO_JOBS_MENU_SLUG', 'bs-awo-jobs');
define('BS_AWO_JOBS_DEFAULT_API_URL', 'https://www.awo-jobs.de/stellenboerse-wesel.json');

/**
 * AJAX-Handler für Frontend-Filter: Parameter aus POST bauen, JobBoard mit Param-Array rendern (ohne $_GET-Mutation).
 */
function bs_awo_jobs_ajax_filter()
{
    check_ajax_referer('bs_awo_jobs_filter', 'nonce');

    $base_url = '';
    if (! empty($_POST['base_url']) && is_string($_POST['base_url'])) {
        $base_url = esc_url_raw(wp_unslash($_POST['base_url']), ['http', 'https']);
    }
    $base_url = $base_url !== '' ? $base_url : null;

    $atts = [
        'limit'       => isset($_POST['limit']) ? max(1, min(100, (int) $_POST['limit'])) : 10,
        'layout'      => isset($_POST['layout']) ? sanitize_text_field(wp_unslash($_POST['layout'])) : 'list',
        'ort'         => isset($_POST['ort']) ? sanitize_text_field(wp_unslash($_POST['ort'])) : '',
        'fachbereich' => isset($_POST['fachbereich']) ? sanitize_text_field(wp_unslash($_POST['fachbereich'])) : '',
        'jobfamily'   => isset($_POST['jobfamily']) ? sanitize_text_field(wp_unslash($_POST['jobfamily'])) : '',
        'vertragsart' => isset($_POST['vertragsart']) ? sanitize_text_field(wp_unslash($_POST['vertragsart'])) : '',
        'paged'       => isset($_POST['paged']) ? max(1, (int) $_POST['paged']) : 1,
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

        if (class_exists('\BsAwoJobs\Wp\Activation')) {
            \BsAwoJobs\Wp\Activation::activate();
        }
        if (class_exists('\BsAwoJobs\Wp\Cron')) {
            \BsAwoJobs\Wp\Cron::reschedule();
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

        // DB-Schema-Upgrades (z. B. raw_json in bsawo_jobs_current).
        if (class_exists('\BsAwoJobs\Wp\Activation')) {
            \BsAwoJobs\Wp\Activation::maybe_upgrade();
        }

        // WP-Cron: automatischer Sync (optional)
        add_action(\BsAwoJobs\Wp\Cron::HOOK_SYNC_EVENT, [\BsAwoJobs\Wp\Cron::class, 'run_sync_cron']);

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
            if (class_exists('\BsAwoJobs\Wp\Admin\SettingsPage')) {
                \BsAwoJobs\Wp\Admin\SettingsPage::init();
            }
            if (class_exists('\BsAwoJobs\Wp\Admin\FrontendSettingsPage')) {
                \BsAwoJobs\Wp\Admin\FrontendSettingsPage::init();
            }

            add_action(
                'admin_enqueue_scripts',
                function ($hook_suffix) {
                    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
                    if ($page === BS_AWO_JOBS_MENU_SLUG || $page === 'bs-awo-jobs-frontend') {
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