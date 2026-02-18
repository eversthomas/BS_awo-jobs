<?php

namespace BsAwoJobs\Wp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class SchemaPage
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
     * Registriert die Schema-Inspector-Unterseite.
     *
     * @return void
     */
    public static function register_menu()
    {
        add_submenu_page(
            BS_AWO_JOBS_MENU_SLUG,
            __('Schema Inspector', 'bs-awo-jobs'),
            __('Schema Inspector', 'bs-awo-jobs'),
            'manage_options',
            'bs-awo-jobs-schema',
            [self::class, 'render_schema_page']
        );
    }

    /**
     * Rendert den Schema Inspector.
     *
     * @return void
     */
    public static function render_schema_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Du hast keine Berechtigung, diese Seite zu sehen.', 'bs-awo-jobs'));
        }

        $report = get_option(SettingsPage::OPTION_LAST_SCHEMA_REPORT);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('AWO Jobs – Schema Inspector', 'bs-awo-jobs'); ?></h1>

            <?php if (! is_array($report) || empty($report)) : ?>
                <p>
                    <?php
                    echo esc_html__(
                        'Es wurde noch kein Sync durchgeführt oder es liegt noch kein Schema-Report vor.',
                        'bs-awo-jobs'
                    );
                    ?>
                </p>
                <p>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: Link zu den Einstellungen */
                            __('Führe zuerst einen Sync auf der <a href="%s">AWO Jobs – Einstellungen</a>-Seite aus.', 'bs-awo-jobs'),
                            esc_url(
                                add_query_arg(
                                    ['page' => BS_AWO_JOBS_MENU_SLUG],
                                    admin_url('admin.php')
                                )
                            )
                        )
                    );
                    ?>
                </p>
                <?php
                return;
            endif;

            $totalJobs      = isset($report['total_jobs']) ? (int) $report['total_jobs'] : 0;
            $fields         = isset($report['fields']) && is_array($report['fields']) ? $report['fields'] : [];
            $stellUnique    = ! empty($report['stellennummer_unique']);
            $stellMissing   = isset($report['stellennummer_missing_count']) ? (int) $report['stellennummer_missing_count'] : 0;
            $stellDupes     = isset($report['stellennummer_dupe_count']) ? (int) $report['stellennummer_dupe_count'] : 0;
            $mandantPercent = isset($report['mandant_population_percent']) ? (float) $report['mandant_population_percent'] : 0.0;
            $fachEnum       = isset($report['fachbereich_enum']) && is_array($report['fachbereich_enum']) ? $report['fachbereich_enum'] : [];
            $jobfamilyEnum  = isset($report['jobfamily_enum']) && is_array($report['jobfamily_enum']) ? $report['jobfamily_enum'] : [];
            ?>

            <h2><?php echo esc_html__('Zusammenfassung', 'bs-awo-jobs'); ?></h2>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: Anzahl Jobs */
                        __('Anzahl Jobs im letzten Snapshot: %d', 'bs-awo-jobs'),
                        $totalJobs
                    )
                );
                ?>
            </p>

            <h3><?php echo esc_html__('Spezielle Checks', 'bs-awo-jobs'); ?></h3>
            <ul>
                <li>
                    <strong><?php echo esc_html__('Eindeutige Stellennummern', 'bs-awo-jobs'); ?>:</strong>
                    <?php if ($stellUnique) : ?>
                        <span class="bs-awo-jobs-badge bs-awo-jobs-badge-success">✅ <?php echo esc_html__('Ja', 'bs-awo-jobs'); ?></span>
                    <?php else : ?>
                        <span class="bs-awo-jobs-badge bs-awo-jobs-badge-error">⚠️ <?php echo esc_html__('Nein', 'bs-awo-jobs'); ?></span>
                        <?php
                        echo ' ';
                        echo esc_html(
                            sprintf(
                                /* translators: 1: fehlend, 2: Duplikate */
                                __('Fehlend: %1$d, Duplikate: %2$d', 'bs-awo-jobs'),
                                $stellMissing,
                                $stellDupes
                            )
                        );
                        ?>
                    <?php endif; ?>
                </li>
                <li>
                    <strong><?php echo esc_html__('Mandantnr/Einrichtungsnr Belegung', 'bs-awo-jobs'); ?>:</strong>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: Prozentwert */
                            __('%s%% gefüllt', 'bs-awo-jobs'),
                            number_format_i18n($mandantPercent, 2)
                        )
                    );
                    ?>
                    <?php if ($mandantPercent < 50.0) : ?>
                        <span class="bs-awo-jobs-badge bs-awo-jobs-badge-warning">
                            <?php echo esc_html__('⚠️ Weniger als 50% gefüllt', 'bs-awo-jobs'); ?>
                        </span>
                    <?php endif; ?>
                </li>
            </ul>

            <h3><?php echo esc_html__('Fachbereich-IDs (API-Taxonomie)', 'bs-awo-jobs'); ?></h3>
            <?php if (! empty($fachEnum)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('ID', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Bezeichnung', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fachEnum as $id => $label) : ?>
                        <tr>
                            <td><?php echo esc_html($id); ?></td>
                            <td><?php echo esc_html($label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('Keine Fachbereich-IDs gefunden.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>

            <h3><?php echo esc_html__('Stellenbezeichnung-IDs (Jobfamilien)', 'bs-awo-jobs'); ?></h3>
            <?php if (! empty($jobfamilyEnum)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('ID', 'bs-awo-jobs'); ?></th>
                            <th><?php echo esc_html__('Bezeichnung', 'bs-awo-jobs'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobfamilyEnum as $id => $label) : ?>
                        <tr>
                            <td><?php echo esc_html($id); ?></td>
                            <td><?php echo esc_html($label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php echo esc_html__('Keine Stellenbezeichnung-IDs gefunden.', 'bs-awo-jobs'); ?></p>
            <?php endif; ?>

            <h3><?php echo esc_html__('Feldanalyse', 'bs-awo-jobs'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Feldname', 'bs-awo-jobs'); ?></th>
                        <th><?php echo esc_html__('Datentyp(en)', 'bs-awo-jobs'); ?></th>
                        <th><?php echo esc_html__('Beispielwerte (bis zu 3)', 'bs-awo-jobs'); ?></th>
                        <th><?php echo esc_html__('Null/leer', 'bs-awo-jobs'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fields as $field) : ?>
                    <tr>
                        <td><?php echo esc_html($field['name']); ?></td>
                        <td><?php echo esc_html($field['types']); ?></td>
                        <td>
                            <?php
                            if (! empty($field['samples'])) {
                                echo esc_html(implode(' | ', $field['samples']));
                            } else {
                                echo '&ndash;';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $nullCount = isset($field['null_count']) ? (int) $field['null_count'] : 0;
                            echo esc_html(
                                sprintf(
                                    '%d / %d',
                                    $nullCount,
                                    isset($field['total']) ? (int) $field['total'] : 0
                                )
                            );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            global $wpdb;
            $jobsTable   = $wpdb->prefix . 'bsawo_jobs_current';
            $rawJsonRow  = $wpdb->get_row(
                "SELECT job_id, raw_json FROM {$jobsTable} WHERE raw_json IS NOT NULL AND raw_json != '' LIMIT 1"
            );
            $expectedKeys = ['DetailUrl', 'Einleitungstext', 'Infos', 'Qualifikation', 'Wirbieten'];
            ?>
            <h3 style="margin-top: 2em;"><?php echo esc_html__('raw_json Struktur-Check (Beispiel-Job aus DB)', 'bs-awo-jobs'); ?></h3>
            <p class="description"><?php echo esc_html__('Prüft, ob die in der Tabelle bsawo_jobs_current gespeicherten raw_json-Daten die Felder für Detail-Ansicht und „Jetzt bewerben“-Link enthalten. Die Feldanalyse oben basiert auf dem letzten API-Snapshot; dieser Block prüft die tatsächlich in der DB gespeicherten Job-Daten.', 'bs-awo-jobs'); ?></p>
            <?php if ($rawJsonRow && ! empty($rawJsonRow->raw_json)) :
                $raw = json_decode($rawJsonRow->raw_json, true);
                $rawKeys = is_array($raw) ? array_keys($raw) : [];
                ?>
                <table class="widefat striped" style="max-width: 720px;">
                    <tbody>
                        <tr>
                            <td><strong><?php echo esc_html__('Beispiel job_id', 'bs-awo-jobs'); ?></strong></td>
                            <td><?php echo esc_html($rawJsonRow->job_id); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php echo esc_html__('Anzahl Keys in raw_json', 'bs-awo-jobs'); ?></strong></td>
                            <td><?php echo esc_html((string) count($rawKeys)); ?></td>
                        </tr>
                        <?php foreach ($expectedKeys as $key) : ?>
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
                            <td><code style="font-size: 0.9em;"><?php echo esc_html(implode(', ', array_slice($rawKeys, 0, 25)) . (count($rawKeys) > 25 ? ' …' : '')); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            <?php else : ?>
                <?php
                $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobsTable)) === $jobsTable;
                $columnExists = false;
                if ($tableExists) {
                    $hasColumn = $wpdb->get_results("SHOW COLUMNS FROM `{$jobsTable}` LIKE 'raw_json'");
                    $columnExists = ! empty($hasColumn);
                }
                ?>
                <p class="description"><?php echo esc_html__('Kein Job mit gespeichertem raw_json in der DB gefunden. Bitte auf der Einstellungsseite einen Sync ausführen – danach werden die API-Daten in bsawo_jobs_current.raw_json gespeichert.', 'bs-awo-jobs'); ?></p>
                <?php if ($tableExists && $columnExists) : ?>
                    <p><strong><?php echo esc_html__('Spalte raw_json', 'bs-awo-jobs'); ?>:</strong> ✓ <?php echo esc_html__('vorhanden', 'bs-awo-jobs'); ?> – <?php echo esc_html__('Sync erneut ausführen, damit die Spalte befüllt wird.', 'bs-awo-jobs'); ?></p>
                <?php elseif ($tableExists && ! $columnExists) : ?>
                    <p><strong><?php echo esc_html__('Spalte raw_json', 'bs-awo-jobs'); ?>:</strong> ✗ <?php echo esc_html__('fehlt in der Tabelle', 'bs-awo-jobs'); ?> – <?php echo esc_html__('Eine Seite im Backend neu laden (z. B. Einstellungen), dann Sync ausführen. Die Migration legt die Spalte beim nächsten Seitenaufruf an.', 'bs-awo-jobs'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php
                // Plugin-Footer
                \BsAwoJobs\Wp\Admin\Footer::render();
                ?>
            </div>
        <?php
    }
}