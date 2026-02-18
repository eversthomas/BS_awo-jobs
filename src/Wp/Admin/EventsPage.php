<?php

namespace BsAwoJobs\Wp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class EventsPage
{
    /**
     * Bootstrap.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    /**
     * Registriert das Events-Log-Submenü.
     *
     * @return void
     */
    public static function register_menu()
    {
        add_submenu_page(
            BS_AWO_JOBS_MENU_SLUG,
            __('Events Log', 'bs-awo-jobs'),
            __('Events Log', 'bs-awo-jobs'),
            'manage_options',
            'bs-awo-jobs-events',
            [self::class, 'render_events_page']
        );
    }

    /**
     * Rendert die Events-Log-Seite.
     *
     * @return void
     */
    public static function render_events_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Du hast keine Berechtigung, diese Seite zu sehen.', 'bs-awo-jobs'));
        }

        global $wpdb;

        $eventType = isset($_GET['event_type']) ? sanitize_text_field(wp_unslash($_GET['event_type'])) : '';
        $days      = isset($_GET['days']) ? (int) $_GET['days'] : 30;
        if ($days <= 0) {
            $days = 30;
        }

        $perPage = 25;
        $paged   = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset  = ($paged - 1) * $perPage;

        $eventsTable = $wpdb->prefix . 'bsawo_events';
        $jobsTable   = $wpdb->prefix . 'bsawo_jobs_current';

        $where   = [];
        $params  = [];

        $dateFrom = gmdate('Y-m-d', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        $where[]  = 'e.event_date >= %s';
        $params[] = $dateFrom;

        if (in_array($eventType, ['created', 'modified', 'offlined'], true)) {
            $where[]  = 'e.event_type = %s';
            $params[] = $eventType;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sqlBase = "
            FROM {$eventsTable} e
            LEFT JOIN {$jobsTable} j ON e.job_id = j.job_id
            {$whereSql}
        ";

        $countSql = "SELECT COUNT(*) " . $sqlBase;
        $eventsSql = "
            SELECT 
                e.id,
                e.event_date,
                e.job_id,
                e.event_type,
                e.detected_at,
                j.facility_name,
                j.jobfamily_name
            " . $sqlBase . "
            ORDER BY e.event_date DESC, e.detected_at DESC
            LIMIT %d OFFSET %d
        ";

        $count = (int) $wpdb->get_var($wpdb->prepare($countSql, $params));

        $paramsWithPaging   = array_merge($params, [$perPage, $offset]);
        $preparedEventsSql  = $wpdb->prepare($eventsSql, $paramsWithPaging);
        $rows               = $wpdb->get_results($preparedEventsSql);

        $totalPages = $count > 0 ? (int) ceil($count / $perPage) : 1;

        // Zusammenfassung nach Event-Typ für den aktuellen Filterzeitraum.
        $summary = [
            'created'  => 0,
            'modified' => 0,
            'offlined' => 0,
        ];

        if ($count > 0) {
            $summarySql = "
                SELECT e.event_type, COUNT(*) AS cnt
                " . $sqlBase . "
                GROUP BY e.event_type
            ";

            $summaryRows = $wpdb->get_results($wpdb->prepare($summarySql, $params));

            if ($summaryRows) {
                foreach ($summaryRows as $row) {
                    $type = (string) $row->event_type;
                    if (isset($summary[$type])) {
                        $summary[$type] = (int) $row->cnt;
                    }
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AWO Jobs – Events Log', 'bs-awo-jobs'); ?></h1>

            <form method="get" style="margin-bottom: 1em;">
                <input type="hidden" name="page" value="bs-awo-jobs-events" />

                <label for="bs_awo_jobs_event_type">
                    <?php echo esc_html__('Event-Typ', 'bs-awo-jobs'); ?>
                </label>
                <select id="bs_awo_jobs_event_type" name="event_type">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs'); ?></option>
                    <option value="created" <?php selected($eventType, 'created'); ?>>
                        <?php echo esc_html__('Created', 'bs-awo-jobs'); ?>
                    </option>
                    <option value="modified" <?php selected($eventType, 'modified'); ?>>
                        <?php echo esc_html__('Modified', 'bs-awo-jobs'); ?>
                    </option>
                    <option value="offlined" <?php selected($eventType, 'offlined'); ?>>
                        <?php echo esc_html__('Offlined', 'bs-awo-jobs'); ?>
                    </option>
                </select>

                <label for="bs_awo_jobs_days" style="margin-left: 1em;">
                    <?php echo esc_html__('Zeitraum (Tage)', 'bs-awo-jobs'); ?>
                </label>
                <input
                    type="number"
                    id="bs_awo_jobs_days"
                    name="days"
                    value="<?php echo esc_attr($days); ?>"
                    min="1"
                    style="width: 80px;"
                />

                <?php submit_button(__('Filter anwenden', 'bs-awo-jobs'), 'secondary', '', false); ?>
            </form>

            <?php if ($count === 0) : ?>
                <p><?php echo esc_html__('Keine Events im gewählten Zeitraum gefunden.', 'bs-awo-jobs'); ?></p>
            <?php else : ?>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Anzahl created, 2: Anzahl modified, 3: Anzahl offlined */
                            __('In diesem Zeitraum: %1$d created, %2$d modified, %3$d offlined.', 'bs-awo-jobs'),
                            (int) $summary['created'],
                            (int) $summary['modified'],
                            (int) $summary['offlined']
                        )
                    );
                    ?>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Datum', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Job-ID', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Typ', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Erkannt um', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Jobfamilie', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->event_date); ?></td>
                            <td><?php echo esc_html($row->job_id); ?></td>
                            <td><?php echo esc_html($row->event_type); ?></td>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($row->detected_at))); ?></td>
                            <td><?php echo esc_html($row->facility_name); ?></td>
                            <td><?php echo esc_html($row->jobfamily_name); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                if ($totalPages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo wp_kses_post(
                        paginate_links(
                            [
                                'base'      => add_query_arg(
                                    [
                                        'page'       => 'bs-awo-jobs-events',
                                        'event_type' => $eventType,
                                        'days'       => $days,
                                        'paged'      => '%#%',
                                    ],
                                    admin_url('admin.php')
                                ),
                                'format'    => '',
                                'prev_text' => __('« Zurück', 'bs-awo-jobs'),
                                'next_text' => __('Weiter »', 'bs-awo-jobs'),
                                'total'     => $totalPages,
                                'current'   => $paged,
                            ]
                        )
                    );
                    echo '</div></div>';
                }
                ?>
            <?php endif; ?>
            
            <?php
                // Plugin-Footer
                \BsAwoJobs\Wp\Admin\Footer::render();
                ?>
        </div>
        <?php
    }
}