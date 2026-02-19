<?php

namespace BsAwoJobs\Wp\Shortcodes;

use BsAwoJobs\Wp\Admin\SettingsPage;
use BsAwoJobs\Wp\Admin\FrontendSettingsPage;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [bs_awo_jobs]: Stellenübersicht (Kurzform) mit Filtern und Detail-Ansicht (Langform) mit Weiterlesen-Link.
 */
class JobBoard
{
    const DEFAULT_LIMIT  = 10;
    const DEFAULT_LAYOUT = 'list';

    /** Transient-Prefix für gecachte Filter-Optionen (Invalidierung nach Sync). */
    const TRANSIENT_FILTER_OPTS_PREFIX = 'bs_awo_jobs_filter_opts_v2_';

    /**
     * Shortcode rendern.
     *
     * @param array $atts Shortcode-Attribute (limit, layout).
     * @return string
     */
        public static function render($atts = [])
    {
        $base_url_override = isset($atts['_base_url']) ? $atts['_base_url'] : null;
        $atts = shortcode_atts(
            [
                'limit'              => self::DEFAULT_LIMIT,
                'layout'             => self::DEFAULT_LAYOUT,
                'fachbereich'        => '',
                'jobfamily'          => '',
                'vertragsart'        => '',
                'ort'                => '',
                'hide_filters'       => 0,
                'hide_layout_toggle' => 0,
            ],
            $atts,
            'bs_awo_jobs'
        );
        if ($base_url_override !== null) {
            $atts['_base_url'] = $base_url_override;
        }

        $limit  = max(1, min(100, (int) $atts['limit']));
        $layout = in_array($atts['layout'], ['list', 'grid'], true) ? $atts['layout'] : self::DEFAULT_LAYOUT;
        // URL-Parameter ?layout=list|grid hat Vorrang (Layout-Umschalter)
        $layout_from_url = isset($_GET['layout']) ? sanitize_text_field(wp_unslash($_GET['layout'])) : '';
        if (in_array($layout_from_url, ['list', 'grid'], true)) {
            $layout = $layout_from_url;
        }

        self::enqueue_assets();

        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
        if ($job_id !== '') {
            return self::render_detail($job_id);
        }

        return self::render_list($limit, $layout, $atts);
    }


    /**
     * Frontend-CSS enqueuen.
     *
     * @return void
     */
    private static function enqueue_assets()
    {
        wp_enqueue_style(
            'bs-awo-jobs-frontend',
            BS_AWO_JOBS_PLUGIN_URL . 'assets/bs-awo-jobs.css',
            [],
            BS_AWO_JOBS_VERSION
        );
        wp_enqueue_script(
            'bs-awo-jobs-filter-ajax',
            BS_AWO_JOBS_PLUGIN_URL . 'assets/bs-awo-jobs-filter-ajax.js',
            [],
            BS_AWO_JOBS_VERSION,
            true
        );
        wp_localize_script(
            'bs-awo-jobs-filter-ajax',
            'bsAwoJobsFilter',
            [
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('bs_awo_jobs_filter'),
                'i18nError' => __('Filter konnten nicht geladen werden. Seite wird neu geladen.', 'bs-awo-jobs'),
            ]
        );
    }

    /**
     * Listen-Ansicht (Kurzform) mit Filtern und Pagination.
     *
     * @param int    $limit  Einträge pro Seite.
     * @param string $layout list|grid.
     * @return string
     */
    private static function render_list($limit, $layout, $atts = [])
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_jobs_current';

        $ort         = $atts['ort'] !== '' ? $atts['ort'] : (isset($_GET['ort']) ? sanitize_text_field(wp_unslash($_GET['ort'])) : '');
        $fachbereich = $atts['fachbereich'] !== '' ? $atts['fachbereich'] : (isset($_GET['fachbereich']) ? sanitize_text_field(wp_unslash($_GET['fachbereich'])) : '');
        $jobfamily   = $atts['jobfamily'] !== '' ? $atts['jobfamily'] : (isset($_GET['jobfamily']) ? sanitize_text_field(wp_unslash($_GET['jobfamily'])) : '');
        $vertragsart = $atts['vertragsart'] !== '' ? $atts['vertragsart'] : (isset($_GET['vertragsart']) ? sanitize_text_field(wp_unslash($_GET['vertragsart'])) : '');

        // Department-Spalte: Mandantenfeld → department_custom; API + numerischer fachbereich → department_api_id; API + Bezeichnung → department_api (UX-intuitiv).
        $deptSource = get_option(SettingsPage::OPTION_DEPARTMENT_SOURCE, 'api');
        if ($deptSource === 'custom') {
            $deptCol = 'department_custom';
        } elseif ($deptSource === 'api') {
            $deptCol = ($fachbereich !== '' && is_numeric(trim((string) $fachbereich)))
                ? 'department_api_id'
                : 'department_api';
        } else {
            $deptCol = 'department_api_id';
        }

        $layoutParam = $layout;
        $hide_filters = !empty($atts['hide_filters']);
        $hide_layout_toggle = !empty($atts['hide_layout_toggle']);


        $current_page = (int) get_query_var('paged', 0);
        if ($current_page < 1 && isset($_GET['paged'])) {
            $current_page = max(1, (int) $_GET['paged']);
        }
        if ($current_page < 1) {
            $current_page = 1;
        }
        $offset = ($current_page - 1) * $limit;

        $where        = ['1=1'];
        $prepare_args = [];

        if ($ort !== '') {
            // Einsatzort (tatsächlicher Arbeitsort) und Ansprechpartner-Adresse (facility_address).
            $where[]        = ' ( COALESCE(einsatzort,\'\') LIKE %s OR facility_address LIKE %s ) ';
            $prepare_args[] = '%' . $wpdb->esc_like($ort) . '%';
            $prepare_args[] = '%' . $wpdb->esc_like($ort) . '%';
        }
        if ($fachbereich !== '') {
            $where[]        = " {$deptCol} = %s ";
            $prepare_args[] = $fachbereich;
        }
        if ($jobfamily !== '') {
            $where[]        = ' jobfamily_id = %s ';
            $prepare_args[] = $jobfamily;
        }
        if ($vertragsart !== '') {
            $where[]        = ' contract_type = %s ';
            $prepare_args[] = $vertragsart;
        }

        $where_sql   = implode(' AND ', $where);
        $filter_args = $prepare_args;

        $total = (int) $wpdb->get_var(
            $filter_args
                ? $wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $filter_args)
                : "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}"
        );

        // raw_json wird benötigt (Bild / Stellenbezeichnung etc.); einsatzort für Anzeige/Filter.
        $select_args = array_merge($filter_args, [$limit, $offset]);
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT job_id, facility_name, facility_address, COALESCE(einsatzort,'') AS einsatzort, jobfamily_name, contract_type, employment_type,
                        department_api, department_custom, {$deptCol} AS dept_val, raw_json
                 FROM `{$table}`
                 WHERE {$where_sql}
                 ORDER BY facility_name ASC, jobfamily_name ASC
                 LIMIT %d OFFSET %d",
                $select_args
            )
        );

        $base_url = ! empty($atts['_base_url']) ? $atts['_base_url'] : get_permalink();
        if (! $base_url) {
            $base_url = home_url($_SERVER['REQUEST_URI'] ?? '');
        }
        $base_url = remove_query_arg(['job_id', 'paged'], $base_url);

        $filter_options = self::get_filter_options(
            $deptCol,
            [
                'fachbereich' => $fachbereich,
                'ort'         => $ort,
            ]
        );
        
        $design = FrontendSettingsPage::get_design_options();
        $design = wp_parse_args($design, ['grid_columns' => 3]);

        $cols = (int) $design['grid_columns'];
        if ($cols < 1) $cols = 1;
        if ($cols > 3) $cols = 3;

        $grid_cols_class = 'bs-awo-grid-cols-' . $cols;

        ob_start();
        ?>
        <div id="bs-awo-jobs" class="bs-awo-jobs">
            <h2 class="bs-awo-jobs-title"><?php echo esc_html__('Stellenangebote', 'bs-awo-jobs'); ?></h2>

            <?php if (!$hide_filters): ?>
			<form method="get" class="bs-awo-jobs-filters" action="<?php echo esc_url($base_url); ?>" aria-label="<?php echo esc_attr__('Filter für Stellenangebote', 'bs-awo-jobs'); ?>">
                <div class="bs-awo-jobs-filter-row">
                    <label for="bs-awo-jobs-ort"><?php echo esc_html__('Ort / Standort', 'bs-awo-jobs'); ?></label>
                    <select id="bs-awo-jobs-ort" name="ort">
                        <option value=""><?php echo esc_html__('— Alle Orte —', 'bs-awo-jobs'); ?></option>
                        <?php foreach ($filter_options['ort'] as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($ort, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bs-awo-jobs-filter-row">
                    <label for="bs-awo-jobs-fachbereich"><?php echo esc_html__('Fachbereich', 'bs-awo-jobs'); ?></label>
                    <select id="bs-awo-jobs-fachbereich" name="fachbereich">
                        <option value=""><?php echo esc_html__('— Alle —', 'bs-awo-jobs'); ?></option>
                        <?php foreach ($filter_options['fachbereich'] as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($fachbereich, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bs-awo-jobs-filter-row">
                    <label for="bs-awo-jobs-jobfamily"><?php echo esc_html__('Beruf / Tätigkeit', 'bs-awo-jobs'); ?></label>
                    <select id="bs-awo-jobs-jobfamily" name="jobfamily">
                        <option value=""><?php echo esc_html__('— Alle —', 'bs-awo-jobs'); ?></option>
                        <?php foreach ($filter_options['jobfamily'] as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($jobfamily, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bs-awo-jobs-filter-row">
                    <label for="bs-awo-jobs-vertragsart"><?php echo esc_html__('Vertragsart', 'bs-awo-jobs'); ?></label>
                    <select id="bs-awo-jobs-vertragsart" name="vertragsart">
                        <option value=""><?php echo esc_html__('— Alle —', 'bs-awo-jobs'); ?></option>
                        <?php foreach ($filter_options['vertragsart'] as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($vertragsart, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bs-awo-jobs-filter-row">
                    <input type="hidden" name="layout" value="<?php echo esc_attr($layoutParam); ?>" />
                    <input type="hidden" name="limit" value="<?php echo esc_attr($limit); ?>" />
                    <button type="submit" class="bs-awo-jobs-btn bs-awo-jobs-btn-primary"><?php echo esc_html__('Filtern', 'bs-awo-jobs'); ?></button>
                    <p class="bs-awo-jobs-reset-wrapper" style="margin-top: 8px; margin-bottom: 0;">
                        <a href="<?php echo esc_url($base_url); ?>" class="bs-awo-jobs-reset-link"><?php echo esc_html__('Filter zurücksetzen', 'bs-awo-jobs'); ?></a>
                    </p>
                </div>
            </form>
            <?php endif; ?>

            <?php
            $layout_params = array_filter([
                'ort'         => $ort,
                'fachbereich' => $fachbereich,
                'jobfamily'   => $jobfamily,
                'vertragsart' => $vertragsart,
            ]);
            $list_url = add_query_arg(array_merge($layout_params, ['layout' => 'list']), $base_url);
            $grid_url = add_query_arg(array_merge($layout_params, ['layout' => 'grid']), $base_url);
            ?>
            <?php if (!$hide_layout_toggle): ?>
			<div class="bs-awo-jobs-layout-toggle">
                <a href="<?php echo esc_url($list_url); ?>" class="<?php echo $layoutParam === 'list' ? 'active' : ''; ?>"><?php echo esc_html__('Liste', 'bs-awo-jobs'); ?></a>
                <a href="<?php echo esc_url($grid_url); ?>" class="<?php echo $layoutParam === 'grid' ? 'active' : ''; ?>"><?php echo esc_html__('Kacheln', 'bs-awo-jobs'); ?></a>
            </div>
            <?php endif; ?>

            <?php if ($total === 0) : ?>
                <p class="bs-awo-jobs-empty"><?php echo esc_html__('Keine Stellen entsprechen den gewählten Filtern.', 'bs-awo-jobs'); ?></p>
                <p><a href="<?php echo esc_url($base_url); ?>"><?php echo esc_html__('Filter zurücksetzen', 'bs-awo-jobs'); ?></a></p>
            <?php else : ?>
                <?php
                $dept_src = get_option(SettingsPage::OPTION_DEPARTMENT_SOURCE, 'api');
                $display  = FrontendSettingsPage::get_display_options();
                $display  = wp_parse_args($display, [
                    'show_image'           => 1,
                    'show_facility'        => 1,
                    'show_location'        => 1,
                    'show_department'      => 1,
                    'show_contract_type'   => 1,
                    'show_employment_type' => 0,
                    'show_date'            => 0,
                    'image_position'       => 'top',
                ]);
                $card_options = ['dept_src' => $dept_src, 'display' => $display];
                ?>
                <ul class="bs-awo-jobs-list bs-awo-jobs-layout-<?php echo esc_attr($layoutParam); ?> <?php echo esc_attr($grid_cols_class); ?>">
                    <?php foreach ($jobs as $job) : ?>
                        <li class="bs-awo-jobs-card">
                            <?php echo wp_kses_post(self::render_job_card($job, $base_url, $card_options)); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                $total_pages = (int) ceil($total / $limit);
                if ($total_pages > 1) :
                    $paginate_base = add_query_arg(array_merge($layout_params, ['layout' => $layoutParam, 'paged' => '%_%']), $base_url);
                    ?>
                    <nav class="bs-awo-jobs-pagination" aria-label="<?php echo esc_attr__('Seitennavigation', 'bs-awo-jobs'); ?>">
                        <?php
                        echo wp_kses_post(paginate_links([
                            'base'      => $paginate_base,
                            'format'    => '%#%',
                            'current'   => $current_page,
                            'total'     => $total_pages,
                            'prev_text' => __('&laquo; Zurück', 'bs-awo-jobs'),
                            'next_text' => __('Weiter &raquo;', 'bs-awo-jobs'),
                        ]));
                        ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Einzelne Job-Karte rendern.
     *
     * @param object $job
     * @param string $base_url
     * @param array  $options Optional: dept_src, display (vermeidet get_option/get_display_options in der Schleife).
     * @return string
     */
    private static function render_job_card($job, $base_url, $options = [])
    {
        if (isset($options['display']) && is_array($options['display'])) {
            $display = $options['display'];
        } else {
            $display = FrontendSettingsPage::get_display_options();
            $display = wp_parse_args($display, [
                'show_image'           => 1,
                'show_facility'        => 1,
                'show_location'        => 1,
                'show_department'      => 1,
                'show_contract_type'   => 1,
                'show_employment_type' => 0,
                'show_date'            => 0,
                'image_position'       => 'top',
            ]);
        }

        $raw = [];
        if (! empty($job->raw_json) && is_string($job->raw_json)) {
            $decoded = json_decode($job->raw_json, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $detailUrl = self::build_detail_url($job->job_id, $base_url);

        // Bild aus raw_json extrahieren (API-Varianten inkl. CustomBildUrl-Map)
        $imageUrl = '';
        if (! empty($display['show_image'])) {
            $candidate = self::extract_image_url($raw, (string) $job->job_id);
            if ($candidate !== '') {
                $imageUrl = esc_url($candidate);
            }
        }

        // Titel
        $title = '';
        if (! empty($raw['Stellenbezeichnung']) && is_string($raw['Stellenbezeichnung'])) {
            $title = trim((string) $raw['Stellenbezeichnung']);
        } elseif (! empty($job->jobfamily_name)) {
            $title = (string) $job->jobfamily_name;
        } else {
            $title = (string) $job->job_id;
        }

        // Standort: bevorzugt raw['Ort'], fallback facility_address
        $location = '';
        if (! empty($raw['Ort']) && is_string($raw['Ort'])) {
            $location = trim((string) $raw['Ort']);
        } elseif (! empty($job->facility_address)) {
            $location = (string) $job->facility_address;
        }

        // Fachbereich je nach Quelle
        $dept = '';
        if (! empty($display['show_department'])) {
            $deptSrc = isset($options['dept_src']) ? $options['dept_src'] : get_option(SettingsPage::OPTION_DEPARTMENT_SOURCE, 'api');
            $dept    = $deptSrc === 'custom'
                ? (! empty($job->department_custom) ? (string) $job->department_custom : '')
                : (! empty($job->department_api) ? (string) $job->department_api : '');
        }

        ob_start();
        ?>
        <div class="bs-awo-job-card">
            <?php if ($imageUrl && $display['image_position'] === 'top') : ?>
                <div class="bs-awo-job-image">
                    <img src="<?php echo esc_url($imageUrl); ?>" alt="<?php echo esc_attr($title); ?>">
                </div>
            <?php endif; ?>

            <h3>
                <a href="<?php echo esc_url($detailUrl); ?>">
                    <?php echo esc_html($title ?: 'Unbekannt'); ?>
                </a>
            </h3>

            <?php if (! empty($display['show_facility']) && ! empty($job->facility_name)) : ?>
                <p class="bs-awo-meta">
                    <strong>🏢</strong> <?php echo esc_html($job->facility_name); ?>
                </p>
            <?php endif; ?>

            <?php if (! empty($display['show_location']) && $location !== '') : ?>
                <p class="bs-awo-meta">
                    <strong>📍</strong> <?php echo esc_html($location); ?>
                </p>
            <?php endif; ?>

            <?php if (! empty($display['show_department']) && $dept !== '') : ?>
                <p class="bs-awo-meta">
                    <strong>🏥</strong> <?php echo esc_html($dept); ?>
                </p>
            <?php endif; ?>

            <?php if (! empty($display['show_contract_type']) && ! empty($job->contract_type)) : ?>
                <p class="bs-awo-meta">
                    <strong>📄</strong> <?php echo esc_html($job->contract_type); ?>
                </p>
            <?php endif; ?>

            <?php if (! empty($display['show_employment_type']) && ! empty($job->employment_type)) : ?>
                <p class="bs-awo-meta">
                    <strong>⏰</strong> <?php echo esc_html($job->employment_type); ?>
                </p>
            <?php endif; ?>

            <?php if (! empty($display['show_date']) && ! empty($job->published_at) && is_numeric($job->published_at)) : ?>
                <p class="bs-awo-meta bs-awo-date">
                    <?php echo esc_html__('Veröffentlicht', 'bs-awo-jobs'); ?>:
                    <?php echo esc_html(date_i18n('d.m.Y', (int) $job->published_at)); ?>
                </p>
            <?php endif; ?>

            <a href="<?php echo esc_url($detailUrl); ?>" class="bs-awo-btn-primary">
                <?php echo esc_html__('Weiterlesen', 'bs-awo-jobs'); ?> →
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Detail-URL bauen.
     *
     * @param string $job_id
     * @param string $base_url
     * @return string
     */
    private static function build_detail_url($job_id, $base_url)
    {
        return add_query_arg('job_id', $job_id, $base_url);
    }

    /**
     * Bild-URL aus raw_json extrahieren (API-Varianten inkl. CustomBildUrl-Map).
     *
     * Erwartete Felder (Beispiele aus der API):
     * - BildUrl (string)
     * - HeaderbildIndividuellUrl (string, teils nur "https://...//")
     * - VerbandLogoUrl (string)
     * - AnsprechpartnerBildUrl (string)
     * - CustomBildUrl (map: { "<job_id>": "https://..." })
     *
     * @param array  $raw
     * @param string $job_id
     * @return string
     */
    private static function extract_image_url(array $raw, $job_id = '')
    {
        // 1) CustomBildUrl kann Map sein: { "402583": "https://..." }
        if (! empty($raw['CustomBildUrl'])) {
            if (is_array($raw['CustomBildUrl'])) {
                if ($job_id !== '' && ! empty($raw['CustomBildUrl'][$job_id]) && is_string($raw['CustomBildUrl'][$job_id])) {
                    $u = trim((string) $raw['CustomBildUrl'][$job_id]);
                    if (preg_match('#^https?://#i', $u)) {
                        return $u;
                    }
                }
                // Fallback: erstes Element der Map
                $first = reset($raw['CustomBildUrl']);
                if (is_string($first)) {
                    $u = trim($first);
                    if (preg_match('#^https?://#i', $u)) {
                        return $u;
                    }
                }
            } elseif (is_string($raw['CustomBildUrl'])) {
                $u = trim((string) $raw['CustomBildUrl']);
                if (preg_match('#^https?://#i', $u)) {
                    return $u;
                }
            }
        }

        // 2) Standard-Felder (Priorität: BildUrl vor anderen)
        foreach (['BildUrl', 'HeaderbildIndividuellUrl', 'AnsprechpartnerBildUrl', 'VerbandLogoUrl'] as $key) {
            if (! empty($raw[$key]) && is_string($raw[$key])) {
                $u = trim((string) $raw[$key]);
                if (preg_match('#^https?://#i', $u) && ! preg_match('#/+$#', $u)) {
                    return $u;
                }
            }
        }

        return '';
    }

    /**
     * Detail-Ansicht (Langform) mit Volltext und Link zur Stellenbörse.
     *
     * @param string $job_id Stellennummer.
     * @return string
     */
    private static function render_detail($job_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_jobs_current';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE job_id = %s", $job_id),
            OBJECT
        );

        if (! $row) {
            return '<div class="bs-awo-jobs"><p class="bs-awo-jobs-empty">' . esc_html__('Stelle nicht gefunden.', 'bs-awo-jobs') . '</p></div>';
        }

        $raw = $row->raw_json ? json_decode($row->raw_json, true) : [];
        $raw = is_array($raw) ? $raw : [];

        // Anzeige-Option: ob der primäre Link direkt zum Formular führen soll
        $display = FrontendSettingsPage::get_display_options();
        $display = wp_parse_args($display, ['show_image' => 1, 'external_form_direct' => 1]);

        // Ermitteln von möglichen URLs aus raw_json (robust)
        $get_raw_string = function(array $a, $k) {
            return (! empty($a[$k]) && is_string($a[$k])) ? trim((string) $a[$k]) : '';
        };

        $simple_form = $get_raw_string($raw, 'SimpleFormUrl');
        $form_url    = $get_raw_string($raw, 'FormUrl');
        $detail_page = $get_raw_string($raw, 'DetailUrl');
        // einige APIs liefern keys unterschiedlich groß — prüfen wir lowercase varianten
        $raw_lower = array_change_key_case($raw, CASE_LOWER);
        if ($simple_form === '' && ! empty($raw_lower['simpleformurl'])) {
            $simple_form = trim((string) $raw_lower['simpleformurl']);
        }
        if ($form_url === '' && ! empty($raw_lower['formurl'])) {
            $form_url = trim((string) $raw_lower['formurl']);
        }
        if ($detail_page === '' && ! empty($raw_lower['detailurl'])) {
            $detail_page = trim((string) $raw_lower['detailurl']);
        }

        // Priorisierung: wenn externe_form_direct aktiviert: SimpleFormUrl -> FormUrl -> DetailUrl
        $primary_link = '';
        if (! empty($display['external_form_direct'])) {
            if ($simple_form !== '' && preg_match('#^https?://#i', $simple_form)) {
                $primary_link = $simple_form;
            } elseif ($form_url !== '' && preg_match('#^https?://#i', $form_url)) {
                $primary_link = $form_url;
            } elseif ($detail_page !== '' && preg_match('#^https?://#i', $detail_page)) {
                // Fallback: wenn nur DetailPage vorhanden ist
                $primary_link = $detail_page;
            }
        } else {
            // Falls Option deaktiviert: primär zur DetailPage verlinken, Form ist sekundär
            if ($detail_page !== '' && preg_match('#^https?://#i', $detail_page)) {
                $primary_link = $detail_page;
            } elseif ($simple_form !== '' && preg_match('#^https?://#i', $simple_form)) {
                $primary_link = $simple_form;
            } elseif ($form_url !== '' && preg_match('#^https?://#i', $form_url)) {
                $primary_link = $form_url;
            }
        }

        // Sekundär-Link: zur vollständigen Anzeige auf awo-jobs.de (falls primär Formular ist)
        $secondary_link = '';
        if ($primary_link !== '' && ($primary_link === $simple_form || $primary_link === $form_url) && $detail_page !== '' && preg_match('#^https?://#i', $detail_page)) {
            $secondary_link = $detail_page;
        }

        $base_url = get_permalink();
        if (! $base_url) {
            $base_url = home_url($_SERVER['REQUEST_URI'] ?? '');
        }
        $back_url = remove_query_arg('job_id', $base_url);

        $title = $row->jobfamily_name ?: $row->job_id;

        ob_start();
        ?>
        <div class="bs-awo-jobs bs-awo-jobs-detail">
            <p class="bs-awo-jobs-back">
                <a href="<?php echo esc_url($back_url); ?>">&larr; <?php echo esc_html__('Zurück zur Übersicht', 'bs-awo-jobs'); ?></a>
            </p>

            <?php
            // Bild (falls vorhanden und aktiviert)
            $imageUrl = '';
            if (! empty($display['show_image'])) {
                $candidate = self::extract_image_url($raw, (string) $row->job_id);
                if ($candidate !== '') {
                    $imageUrl = esc_url($candidate);
                }
            }

            if ($imageUrl !== '') : ?>
                <div class="bs-awo-job-detail-image" style="margin: 20px 0;">
                    <img src="<?php echo esc_url($imageUrl); ?>"
                         alt="<?php echo esc_attr($raw['Stellenbezeichnung'] ?? ''); ?>"
                         style="max-width: 100%; height: auto; border-radius: 8px;">
                </div>
            <?php endif; ?>

            <article class="bs-awo-jobs-detail-article">
                <h2 class="bs-awo-jobs-detail-title"><?php echo esc_html($title); ?></h2>
                <?php if (! empty($row->facility_name)) : ?>
                    <p class="bs-awo-jobs-detail-facility"><?php echo esc_html($row->facility_name); ?></p>
                <?php endif; ?>
                <?php if (! empty($row->facility_address)) : ?>
                    <p class="bs-awo-jobs-detail-location"><?php echo esc_html($row->facility_address); ?></p>
                <?php endif; ?>
                <?php if (! empty($row->contract_type)) : ?>
                    <p class="bs-awo-jobs-detail-type"><?php echo esc_html($row->contract_type); ?></p>
                <?php endif; ?>

                <?php
                // Meta-Block (wie vorher)
                $zeitmodell   = isset($raw['Zeitmodell']) ? trim((string) $raw['Zeitmodell']) : '';
                $einstellung  = isset($raw['Einstellungsdatum']) ? trim((string) $raw['Einstellungsdatum']) : '';
                $ansprech     = isset($raw['Ansprechpartner']) ? trim((string) $raw['Ansprechpartner']) : '';
                $telefon      = isset($raw['Telefon']) ? trim((string) $raw['Telefon']) : '';
                $email        = isset($raw['Email']) ? trim((string) $raw['Email']) : '';
                $einsatzort   = isset($raw['Einsatzort']) ? trim((string) $raw['Einsatzort']) : '';
                if ($zeitmodell || $einstellung || $ansprech || $telefon || $email || $einsatzort) :
                    ?>
                    <div class="bs-awo-jobs-detail-meta">
                        <?php if ($einstellung) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('Einstellungsdatum', 'bs-awo-jobs'); ?>:</strong> <?php echo esc_html($einstellung); ?></p><?php endif; ?>
                        <?php if ($zeitmodell) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('Zeitmodell', 'bs-awo-jobs'); ?>:</strong> <?php echo esc_html($zeitmodell); ?></p><?php endif; ?>
                        <?php if ($einsatzort) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('Einsatzort', 'bs-awo-jobs'); ?>:</strong> <?php echo esc_html($einsatzort); ?></p><?php endif; ?>
                        <?php if ($ansprech) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('Ansprechpartner', 'bs-awo-jobs'); ?>:</strong> <?php echo esc_html($ansprech); ?></p><?php endif; ?>
                        <?php if ($telefon) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('Telefon', 'bs-awo-jobs'); ?>:</strong> <a href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $telefon)); ?>"><?php echo esc_html($telefon); ?></a></p><?php endif; ?>
                        <?php if ($email) : ?><p class="bs-awo-jobs-detail-meta-item"><strong><?php echo esc_html__('E-Mail', 'bs-awo-jobs'); ?>:</strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></p><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="bs-awo-jobs-detail-content">
                    <?php
                    $content_blocks = [
                        'Einleitungstext' => ['headline' => null],
                        'Infos'           => ['headline' => 'HeadlineStellenbeschreibung'],
                        'Qualifikation'   => ['headline' => 'HeadlineSieBringenMit'],
                        'Wirbieten'       => ['headline' => 'HeadlineWirBietenIhnen'],
                    ];
                    $shown_keys = [];
                    foreach ($content_blocks as $key => $opts) {
                        $val = isset($raw[$key]) ? $raw[$key] : (isset($raw_lower[strtolower($key)]) ? $raw_lower[strtolower($key)] : null);
                        if (empty($val) || ! is_string($val)) {
                            continue;
                        }
                        $shown_keys[$key] = true;
                        $headline = null;
                        if (! empty($opts['headline']) && ! empty($raw[$opts['headline']])) {
                            $headline = trim((string) $raw[$opts['headline']]);
                        }
                        echo '<div class="bs-awo-jobs-detail-block bs-awo-jobs-detail-' . esc_attr(sanitize_title($key)) . '">';
                        if ($headline) {
                            echo '<h3 class="bs-awo-jobs-detail-block-title">' . esc_html($headline) . '</h3>';
                        }
                        if (strpos($val, '<') !== false) {
                            echo wp_kses_post($val);
                        } else {
                            echo '<p>' . esc_html($val) . '</p>';
                        }
                        echo '</div>';
                    }
                    $html_fields_lower = array_map('strtolower', array_keys($content_blocks));
                    foreach ($raw as $key => $val) {
                        if (! is_string($val) || trim($val) === '' || isset($shown_keys[$key])) {
                            continue;
                        }
                        if (in_array(strtolower($key), $html_fields_lower, true)) {
                            continue;
                        }
                        if (preg_match('/^(DetailUrl|DetailURL|FormUrl|PdfUrl|ContactUrl|SimpleFormUrl|BildUrl|HeaderbildIndividuellUrl|VerbandLogoUrl|AnsprechpartnerBildUrl|CustomBildUrl|Stellennummer|Anlagedatum|Aenderungsdatum|Startdatum|Stopdatum|Fachbereich-IDs|Stellenbezeichnung-IDs|Vertragsart-IDs|IsMinijob|EinfacheBewerbung|AdressdetailsAusblenden|InformelleAndrede|Geschlecht)$/i', $key)) {
                            continue;
                        }
                        if (strpos($val, '<') !== false && strlen($val) > 20) {
                            echo '<div class="bs-awo-jobs-detail-block bs-awo-jobs-detail-' . esc_attr(sanitize_title($key)) . '">';
                            echo '<h4 class="bs-awo-jobs-detail-block-title">' . esc_html($key) . '</h4>';
                            echo wp_kses_post($val);
                            echo '</div>';
                        }
                    }
                    if (! empty($raw['Video']) && is_string($raw['Video']) && preg_match('#^https?://#i', trim($raw['Video']))) :
                        $video_url = trim($raw['Video']);
                        ?>
                        <div class="bs-awo-jobs-detail-block bs-awo-jobs-detail-video">
                            <h4 class="bs-awo-jobs-detail-block-title"><?php echo esc_html__('Video', 'bs-awo-jobs'); ?></h4>
                            <p><a href="<?php echo esc_url($video_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Video ansehen', 'bs-awo-jobs'); ?></a></p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($primary_link !== '' && preg_match('#^https?://#i', $primary_link)) : ?>
                    <div class="bs-awo-jobs-detail-external">
                        <p class="bs-awo-jobs-detail-external-notice">
                            <?php echo esc_html__('Zur Bewerbung werden Sie zur Stellenbörse weitergeleitet. Sie verlassen damit unsere Website.', 'bs-awo-jobs'); ?>
                        </p>
                        <p>
                            <a href="<?php echo esc_url($primary_link); ?>" target="_blank" rel="noopener noreferrer" class="bs-awo-jobs-btn bs-awo-jobs-btn-primary bs-awo-jobs-btn-external">
                                <?php
                                // Button-Text je nachdem ob es das Formular oder die Detailseite ist
                                if ($primary_link === $simple_form || $primary_link === $form_url) {
                                    echo esc_html__('Jetzt online bewerben', 'bs-awo-jobs');
                                } else {
                                    echo esc_html__('Zur Anzeige auf awo-jobs.de', 'bs-awo-jobs');
                                }
                                ?>
                            </a>

                            <?php if ($secondary_link !== '') : ?>
                                &nbsp;
                                <a href="<?php echo esc_url($secondary_link); ?>" target="_blank" rel="noopener noreferrer" class="bs-awo-jobs-btn bs-awo-jobs-btn-secondary">
                                    <?php echo esc_html__('Vollständige Anzeige auf awo-jobs.de ansehen', 'bs-awo-jobs'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </article>
        </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Optionen für Filter-Dropdowns aus DB holen.
     *
     * @param string $deptCol       Spalte für Fachbereich (department_api_id, department_api oder department_custom).
     * @param array  $currentFilter Aktuelle Filter (z. B. ['fachbereich' => '...']).
     * @return array{fachbereich: array, jobfamily: array, vertragsart: array, ort: array}
     */
    private static function get_filter_options($deptCol, array $currentFilter = [])
    {
        $transient_key = self::TRANSIENT_FILTER_OPTS_PREFIX . $deptCol;
        $cached        = get_transient($transient_key);

        global $wpdb;

        $table = $wpdb->prefix . 'bsawo_jobs_current';

        $hasOrtFilter = ! empty($currentFilter['ort']);
        $ortValue     = $hasOrtFilter ? (string) $currentFilter['ort'] : '';
        $whereOrt     = $hasOrtFilter
            ? $wpdb->prepare(' AND ( COALESCE(einsatzort,\'\') LIKE %s OR facility_address LIKE %s ) ', '%' . $wpdb->esc_like($ortValue) . '%', '%' . $wpdb->esc_like($ortValue) . '%')
            : '';

        if (! $hasOrtFilter && $cached !== false && is_array($cached) && isset($cached['ort'])) {
            $result = $cached;
        } else {
            $fachbereich = [];
            if ($deptCol === 'department_api_id') {
                $rows = $wpdb->get_results(
                    "SELECT department_api_id AS val, department_api AS label FROM `{$table}` WHERE department_api_id IS NOT NULL AND department_api_id <> ''{$whereOrt} GROUP BY department_api_id, department_api ORDER BY department_api",
                    OBJECT_K
                );
            } elseif ($deptCol === 'department_api') {
                $rows = $wpdb->get_results(
                    "SELECT department_api AS val, department_api AS label FROM `{$table}` WHERE department_api IS NOT NULL AND department_api <> ''{$whereOrt} GROUP BY department_api ORDER BY department_api",
                    OBJECT_K
                );
            } else {
                $rows = $wpdb->get_results(
                    "SELECT department_custom AS val, department_custom AS label FROM `{$table}` WHERE department_custom IS NOT NULL AND department_custom <> ''{$whereOrt} GROUP BY department_custom ORDER BY department_custom",
                    OBJECT_K
                );
            }
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $fachbereich[$r->val] = $r->label ?: $r->val;
                }
            }

            $jobfamily = [];
            $rows = $wpdb->get_results(
                "SELECT jobfamily_id AS val, jobfamily_name AS label FROM `{$table}` WHERE jobfamily_id IS NOT NULL AND jobfamily_id <> ''{$whereOrt} GROUP BY jobfamily_id, jobfamily_name ORDER BY jobfamily_name",
                OBJECT_K
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $jobfamily[$r->val] = $r->label ?: $r->val;
                }
            }

            $vertragsart = [];
            $rows = $wpdb->get_results(
                "SELECT contract_type AS val FROM `{$table}` WHERE contract_type IS NOT NULL AND contract_type <> ''{$whereOrt} GROUP BY contract_type ORDER BY contract_type",
                OBJECT_K
            );
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $vertragsart[$r->val] = $r->val;
                }
            }

            // Orte: aus Einsatzort und facility_address – alle Zeilen durchgehen (OBJECT, nicht OBJECT_K, sonst gehen Orte verloren).
            $orte = [];
            $hasEinsatzortCol = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'einsatzort'));
            if (! empty($hasEinsatzortCol)) {
                $rows = $wpdb->get_results(
                    "SELECT facility_address, einsatzort FROM `{$table}` WHERE (facility_address IS NOT NULL AND facility_address <> '') OR (einsatzort IS NOT NULL AND einsatzort <> '')",
                    OBJECT
                );
            } else {
                $rows = $wpdb->get_results(
                    "SELECT facility_address AS val FROM `{$table}` WHERE facility_address IS NOT NULL AND facility_address <> ''",
                    OBJECT
                );
            }
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $rawAddr = isset($r->facility_address) ? (string) $r->facility_address : (isset($r->val) ? (string) $r->val : '');
                    if ($rawAddr !== '') {
                        $city = $rawAddr;
                        if (preg_match('/\b\d{5}\s+(.+)$/u', $rawAddr, $m)) {
                            $city = trim($m[1]);
                        }
                        if ($city !== '') {
                            $orte[$city] = $city;
                        }
                    }
                    $einsatzortStr = isset($r->einsatzort) ? trim((string) $r->einsatzort) : '';
                    if ($einsatzortStr !== '') {
                        foreach (array_map('trim', explode(',', $einsatzortStr)) as $part) {
                            if ($part !== '') {
                                $orte[$part] = $part;
                            }
                        }
                    }
                }
            }
            ksort($orte, SORT_LOCALE_STRING);

            $result = [
                'fachbereich' => $fachbereich,
                'jobfamily'   => $jobfamily,
                'vertragsart' => $vertragsart,
                'ort'         => $orte,
            ];
            if (! $hasOrtFilter) {
                set_transient($transient_key, $result, 12 * HOUR_IN_SECONDS);
            }
        }

        // Wenn ein Fachbereich gewählt ist, Jobfamilien auf diesen Fachbereich einschränken.
        if (! empty($currentFilter['fachbereich'])) {
            $selectedDept = (string) $currentFilter['fachbereich'];

            if ($deptCol === 'department_api_id') {
                $whereDept = $wpdb->prepare(
                    'WHERE jobfamily_id IS NOT NULL AND jobfamily_id <> "" AND department_api_id = %s',
                    $selectedDept
                );
            } elseif ($deptCol === 'department_api') {
                $whereDept = $wpdb->prepare(
                    'WHERE jobfamily_id IS NOT NULL AND jobfamily_id <> "" AND department_api = %s',
                    $selectedDept
                );
            } else {
                $whereDept = $wpdb->prepare(
                    'WHERE jobfamily_id IS NOT NULL AND jobfamily_id <> "" AND department_custom = %s',
                    $selectedDept
                );
            }

            $rows = $wpdb->get_results(
                "SELECT jobfamily_id AS val, jobfamily_name AS label FROM `{$table}` {$whereDept}{$whereOrt} GROUP BY jobfamily_id, jobfamily_name ORDER BY jobfamily_name",
                OBJECT_K
            );

            $jobfamily = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $jobfamily[$r->val] = $r->label ?: $r->val;
                }
            }

            $result['jobfamily'] = $jobfamily;
        }

        return $result;
    }
}