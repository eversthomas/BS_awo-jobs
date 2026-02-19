<?php

namespace BsAwoJobs\Wp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class FrontendSettingsPage
{
    const OPTION_PREFIX = 'bs_awo_jobs_frontend_';
    const NONCE_ACTION  = 'bs_awo_jobs_save_frontend_settings';

    /**
     * Initialisierung.
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 15);
        add_action('admin_init', [__CLASS__, 'handle_save']);
    }

    /**
     * Menü registrieren.
     */
    public static function register_menu()
    {
        add_submenu_page(
            BS_AWO_JOBS_MENU_SLUG,
            __('Frontend-Einstellungen', 'bs-awo-jobs'),
            __('Frontend', 'bs-awo-jobs'),
            'manage_options',
            'bs-awo-jobs-frontend',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Formular speichern.
     */
    public static function handle_save()
    {
        if (! isset($_POST['bs_awo_jobs_frontend_submit'])) {
            return;
        }

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        check_admin_referer(self::NONCE_ACTION, 'bs_awo_jobs_frontend_nonce');

        // Design-Optionen
        $design_defaults_hex = [
            'card_bg_color'     => '#ffffff',
            'card_text_color'   => '#333333',
            'card_link_color'   => '#0073aa',
            'card_border_color' => '#dddddd',
            'button_bg_color'   => '#0073aa',
            'button_text_color' => '#ffffff',
        ];
        $design_options = [
            'card_bg_color'      => sanitize_hex_color($_POST['card_bg_color'] ?? '#ffffff') ?: $design_defaults_hex['card_bg_color'],
            'card_text_color'    => sanitize_hex_color($_POST['card_text_color'] ?? '#333333') ?: $design_defaults_hex['card_text_color'],
            'card_link_color'    => sanitize_hex_color($_POST['card_link_color'] ?? '#0073aa') ?: $design_defaults_hex['card_link_color'],
            'card_border_color'  => sanitize_hex_color($_POST['card_border_color'] ?? '#dddddd') ?: $design_defaults_hex['card_border_color'],
            'button_bg_color'    => sanitize_hex_color($_POST['button_bg_color'] ?? '#0073aa') ?: $design_defaults_hex['button_bg_color'],
            'button_text_color'  => sanitize_hex_color($_POST['button_text_color'] ?? '#ffffff') ?: $design_defaults_hex['button_text_color'],
            'grid_card_width'    => absint($_POST['grid_card_width'] ?? 300),
            'grid_columns'       => absint($_POST['grid_columns'] ?? 3),
            'card_border_radius' => absint($_POST['card_border_radius'] ?? 8),
            'card_padding'       => absint($_POST['card_padding'] ?? 20),
        ];

        // Clamp grid_columns auf 1..3 (sicher + robust)
        if ($design_options['grid_columns'] < 1) {
            $design_options['grid_columns'] = 1;
        }
        if ($design_options['grid_columns'] > 3) {
            $design_options['grid_columns'] = 3;
        }

        // Anzeige-Optionen
        $display_options = [
            'show_image'           => isset($_POST['show_image']) ? 1 : 0,
            'show_facility'        => isset($_POST['show_facility']) ? 1 : 0,
            'show_location'        => isset($_POST['show_location']) ? 1 : 0,
            'show_department'      => isset($_POST['show_department']) ? 1 : 0,
            'show_contract_type'   => isset($_POST['show_contract_type']) ? 1 : 0,
            'show_employment_type' => isset($_POST['show_employment_type']) ? 1 : 0,
            'show_date'            => isset($_POST['show_date']) ? 1 : 0,
            'image_position'       => sanitize_text_field($_POST['image_position'] ?? 'top'),
            'image_height'         => absint($_POST['image_height'] ?? 200),

            // NEU: externer Bewerbungslink direkt zum Formular
            'external_form_direct' => isset($_POST['external_form_direct']) ? 1 : 0,
        ];

        // Hardening: nur erlaubte Werte für image_position
        if (! in_array($display_options['image_position'], ['top', 'left'], true)) {
            $display_options['image_position'] = 'top';
        }

        update_option(self::OPTION_PREFIX . 'design', $design_options);
        update_option(self::OPTION_PREFIX . 'display', $display_options);

        add_settings_error(
            'bs_awo_jobs_frontend',
            'settings_saved',
            __('Frontend-Einstellungen gespeichert.', 'bs-awo-jobs'),
            'success'
        );
    }

    /**
     * Seite rendern.
     */
    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bs-awo-jobs'));
        }

        $design  = get_option(self::OPTION_PREFIX . 'design', []);
        $display = get_option(self::OPTION_PREFIX . 'display', []);

        // Defaults
        $design = wp_parse_args($design, [
            'card_bg_color'      => '#ffffff',
            'card_text_color'    => '#333333',
            'card_link_color'    => '#0073aa',
            'card_border_color'  => '#dddddd',
            'button_bg_color'    => '#0073aa',
            'button_text_color'  => '#ffffff',
            'grid_card_width'    => 300,
            'grid_columns'       => 3,
            'card_border_radius' => 8,
            'card_padding'       => 20,
        ]);

        $display = wp_parse_args($display, [
            'show_image'           => 1,
            'show_facility'        => 1,
            'show_location'        => 1,
            'show_department'      => 1,
            'show_contract_type'   => 1,
            'show_employment_type' => 0,
            'show_date'            => 0,
            'image_position'       => 'top',
            'image_height'         => 200,

            // NEU: Standard = direkt ins Formular (UX-optimiert)
            'external_form_direct' => 1,
        ]);

        // Clamp (auch beim Rendern, falls DB-Wert kaputt)
        $design['grid_columns'] = (int) $design['grid_columns'];
        if ($design['grid_columns'] < 1) {
            $design['grid_columns'] = 1;
        }
        if ($design['grid_columns'] > 3) {
            $design['grid_columns'] = 3;
        }

        settings_errors('bs_awo_jobs_frontend');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Frontend-Einstellungen', 'bs-awo-jobs'); ?></h1>
            <p class="description">
                <?php esc_html_e('Passen Sie das Design und die Anzeige der Stellenanzeigen im Frontend an.', 'bs-awo-jobs'); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, 'bs_awo_jobs_frontend_nonce'); ?>

                <h2>🎨 <?php esc_html_e('Design & Farben', 'bs-awo-jobs'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="card_bg_color"><?php esc_html_e('Kachel-Hintergrundfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="card_bg_color" id="card_bg_color"
                                   value="<?php echo esc_attr($design['card_bg_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['card_bg_color']); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="card_text_color"><?php esc_html_e('Textfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="card_text_color" id="card_text_color"
                                   value="<?php echo esc_attr($design['card_text_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['card_text_color']); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="card_link_color"><?php esc_html_e('Link-/Titelfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="card_link_color" id="card_link_color"
                                   value="<?php echo esc_attr($design['card_link_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['card_link_color']); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="card_border_color"><?php esc_html_e('Rahmenfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="card_border_color" id="card_border_color"
                                   value="<?php echo esc_attr($design['card_border_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['card_border_color']); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="button_bg_color"><?php esc_html_e('Button-Hintergrundfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="button_bg_color" id="button_bg_color"
                                   value="<?php echo esc_attr($design['button_bg_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['button_bg_color']); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="button_text_color"><?php esc_html_e('Button-Textfarbe', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="color" name="button_text_color" id="button_text_color"
                                   value="<?php echo esc_attr($design['button_text_color']); ?>"
                                   class="bs-color-picker">
                            <code><?php echo esc_html($design['button_text_color']); ?></code>
                        </td>
                    </tr>
                </table>

                <h2>📐 <?php esc_html_e('Layout-Abmessungen', 'bs-awo-jobs'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="grid_card_width"><?php esc_html_e('Kachelbreite (Grid-Modus)', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="number" name="grid_card_width" id="grid_card_width"
                                   value="<?php echo esc_attr($design['grid_card_width']); ?>"
                                   min="200" max="600" step="10" class="small-text">
                            <span class="description"><?php esc_html_e('Pixel (empfohlen: 250–400)', 'bs-awo-jobs'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="grid_columns"><?php esc_html_e('Spalten im Grid', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <select name="grid_columns" id="grid_columns">
                                <option value="1" <?php selected((int) $design['grid_columns'], 1); ?>>1</option>
                                <option value="2" <?php selected((int) $design['grid_columns'], 2); ?>>2</option>
                                <option value="3" <?php selected((int) $design['grid_columns'], 3); ?>>3</option>
                            </select>
                            <p class="description"><?php esc_html_e('Maximale Anzahl Kacheln nebeneinander (nur im Grid-Modus).', 'bs-awo-jobs'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="card_border_radius"><?php esc_html_e('Rahmen-Rundung', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="number" name="card_border_radius" id="card_border_radius"
                                   value="<?php echo esc_attr($design['card_border_radius']); ?>"
                                   min="0" max="50" step="1" class="small-text">
                            <span class="description"><?php esc_html_e('Pixel (0 = eckig, 8 = leicht rund)', 'bs-awo-jobs'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="card_padding"><?php esc_html_e('Innenabstand', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="number" name="card_padding" id="card_padding"
                                   value="<?php echo esc_attr($design['card_padding']); ?>"
                                   min="10" max="50" step="5" class="small-text">
                            <span class="description"><?php esc_html_e('Pixel', 'bs-awo-jobs'); ?></span>
                        </td>
                    </tr>
                </table>

                <h2>🖼️ <?php esc_html_e('Bild-Einstellungen', 'bs-awo-jobs'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Bilder anzeigen', 'bs-awo-jobs'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_image" value="1" <?php checked($display['show_image'], 1); ?>>
                                <?php esc_html_e('Stellenbilder aus der API anzeigen', 'bs-awo-jobs'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Gilt für Grid-Kacheln und Detail-Ansicht.', 'bs-awo-jobs'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="image_position"><?php esc_html_e('Bild-Position (Grid)', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <select name="image_position" id="image_position">
                                <option value="top" <?php selected($display['image_position'], 'top'); ?>>
                                    <?php esc_html_e('Oben (über Titel)', 'bs-awo-jobs'); ?>
                                </option>
                                <option value="left" <?php selected($display['image_position'], 'left'); ?>>
                                    <?php esc_html_e('Links (neben Text)', 'bs-awo-jobs'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="image_height"><?php esc_html_e('Bildhöhe (Grid)', 'bs-awo-jobs'); ?></label></th>
                        <td>
                            <input type="number" name="image_height" id="image_height"
                                   value="<?php echo esc_attr($display['image_height']); ?>"
                                   min="100" max="400" step="10" class="small-text">
                            <span class="description"><?php esc_html_e('Pixel (empfohlen: 150–250)', 'bs-awo-jobs'); ?></span>
                        </td>
                    </tr>
                </table>

                <h2>📋 <?php esc_html_e('Anzuzeigende Felder (Grid-Kacheln)', 'bs-awo-jobs'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Kurzansicht-Inhalte', 'bs-awo-jobs'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="show_facility" value="1" <?php checked($display['show_facility'], 1); ?>>
                                    <?php esc_html_e('Einrichtung/Arbeitgeber', 'bs-awo-jobs'); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="show_location" value="1" <?php checked($display['show_location'], 1); ?>>
                                    <?php esc_html_e('Standort (Ort)', 'bs-awo-jobs'); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="show_department" value="1" <?php checked($display['show_department'], 1); ?>>
                                    <?php esc_html_e('Fachbereich', 'bs-awo-jobs'); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="show_contract_type" value="1" <?php checked($display['show_contract_type'], 1); ?>>
                                    <?php esc_html_e('Vertragsart', 'bs-awo-jobs'); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="show_employment_type" value="1" <?php checked($display['show_employment_type'], 1); ?>>
                                    <?php esc_html_e('Beschäftigungsart (Vollzeit/Teilzeit)', 'bs-awo-jobs'); ?>
                                </label><br>

                                <label>
                                    <input type="checkbox" name="show_date" value="1" <?php checked($display['show_date'], 1); ?>>
                                    <?php esc_html_e('Veröffentlichungsdatum', 'bs-awo-jobs'); ?>
                                </label>
                            </fieldset>
                            <p class="description">
                                <strong><?php esc_html_e('Hinweis:', 'bs-awo-jobs'); ?></strong>
                                <?php esc_html_e('Der Titel wird immer angezeigt.', 'bs-awo-jobs'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>🔗 <?php esc_html_e('Bewerbungslink', 'bs-awo-jobs'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Weiterleitung', 'bs-awo-jobs'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="external_form_direct" value="1" <?php checked($display['external_form_direct'], 1); ?>>
                                <?php esc_html_e('Direkt zum Bewerbungsformular auf awo-jobs.de weiterleiten (falls vorhanden).', 'bs-awo-jobs'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Ist die Option aktiv, führt der primäre Button direkt zum Formular (SimpleFormUrl/FormUrl). Andernfalls wird zur ausführlichen Anzeige auf awo-jobs.de verlinkt.', 'bs-awo-jobs'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Einstellungen speichern', 'bs-awo-jobs'), 'primary', 'bs_awo_jobs_frontend_submit'); ?>
            </form>

            <hr style="margin: 40px 0;">

            <h2>👁️ <?php esc_html_e('Vorschau', 'bs-awo-jobs'); ?></h2>
            <div style="padding: 20px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">
                <div style="
                    background: <?php echo esc_attr($design['card_bg_color']); ?>;
                    border: 1px solid <?php echo esc_attr($design['card_border_color']); ?>;
                    border-radius: <?php echo esc_attr($design['card_border_radius']); ?>px;
                    padding: <?php echo esc_attr($design['card_padding']); ?>px;
                    max-width: <?php echo esc_attr($design['grid_card_width']); ?>px;
                    color: <?php echo esc_attr($design['card_text_color']); ?>;
                ">
                    <?php if ($display['show_image']) : ?>
                        <div style="
                            width: 100%;
                            height: <?php echo esc_attr($display['image_height']); ?>px;
                            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                            margin-bottom: 15px;
                            border-radius: 4px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            color: white;
                            font-weight: bold;
                        ">
                            <?php esc_html_e('Beispielbild', 'bs-awo-jobs'); ?>
                        </div>
                    <?php endif; ?>

                    <h3 style="
                        margin: 0 0 10px 0;
                        color: <?php echo esc_attr($design['card_link_color']); ?>;
                        font-size: 18px;
                    ">
                        <?php esc_html_e('Pflegefachkraft (m/w/d)', 'bs-awo-jobs'); ?>
                    </h3>

                    <?php if ($display['show_facility']) : ?>
                        <p style="margin: 5px 0;"><strong>🏢</strong> <?php esc_html_e('AWO Seniorenzentrum Beispielstadt', 'bs-awo-jobs'); ?></p>
                    <?php endif; ?>

                    <?php if ($display['show_location']) : ?>
                        <p style="margin: 5px 0;"><strong>📍</strong> <?php esc_html_e('Wesel', 'bs-awo-jobs'); ?></p>
                    <?php endif; ?>

                    <?php if ($display['show_department']) : ?>
                        <p style="margin: 5px 0;"><strong>🏥</strong> <?php esc_html_e('Pflege & Betreuung', 'bs-awo-jobs'); ?></p>
                    <?php endif; ?>

                    <?php if ($display['show_contract_type']) : ?>
                        <p style="margin: 5px 0;"><strong>📄</strong> <?php esc_html_e('unbefristet', 'bs-awo-jobs'); ?></p>
                    <?php endif; ?>

                    <?php if ($display['show_employment_type']) : ?>
                        <p style="margin: 5px 0;"><strong>⏰</strong> <?php esc_html_e('Vollzeit', 'bs-awo-jobs'); ?></p>
                    <?php endif; ?>

                    <?php if ($display['show_date']) : ?>
                        <p style="margin: 5px 0; font-size: 12px; opacity: 0.7;">
                            <?php esc_html_e('Veröffentlicht: 12.02.2026', 'bs-awo-jobs'); ?>
                        </p>
                    <?php endif; ?>

                    <a href="#" style="
                        display: inline-block;
                        margin-top: 15px;
                        padding: 10px 20px;
                        background: <?php echo esc_attr($design['button_bg_color']); ?>;
                        color: <?php echo esc_attr($design['button_text_color']); ?>;
                        text-decoration: none;
                        border-radius: 4px;
                        font-weight: 500;
                    ">
                        <?php esc_html_e('Weiterlesen →', 'bs-awo-jobs'); ?>
                    </a>

                    <p style="margin-top: 12px; font-size: 12px; opacity: 0.7;">
                        <?php
                        echo ! empty($display['external_form_direct'])
                            ? esc_html__('Primärer Bewerbungslink: direkt zum Formular.', 'bs-awo-jobs')
                            : esc_html__('Primärer Bewerbungslink: zur Anzeige auf awo-jobs.de.', 'bs-awo-jobs');
                        ?>
                    </p>
                </div>
            </div>

            <hr style="margin: 40px 0;">

            <?php
            $dept_source = get_option(\BsAwoJobs\Wp\Admin\SettingsPage::OPTION_DEPARTMENT_SOURCE, 'api');
            ?>

            <h2>🔧 <?php esc_html_e('Shortcodes für Spezialseiten', 'bs-awo-jobs'); ?></h2>
            <p>
                <?php esc_html_e('Der Haupt-Shortcode lautet:', 'bs-awo-jobs'); ?>
                <code>[bs_awo_jobs]</code>
            </p>
            <p>
                <?php esc_html_e('Über Attribute kannst du beliebige Spezialseiten konfigurieren. Die wichtigsten Attribute sind:', 'bs-awo-jobs'); ?>
            </p>
            <ul style="list-style: disc; margin-left: 1.5em;">
                <li><code>limit</code> – <?php esc_html_e('Anzahl der Stellen pro Seite (z. B. limit=\"20\")', 'bs-awo-jobs'); ?></li>
                <li><code>layout</code> – <?php esc_html_e('Anzeige als Liste oder Kacheln (layout=\"list\" oder layout=\"grid\")', 'bs-awo-jobs'); ?></li>
                <li><code>ort</code> – <?php esc_html_e('nur Stellen aus einer bestimmten Stadt (muss zu einem Eintrag im Orte-Filter passen)', 'bs-awo-jobs'); ?></li>
                <li>
                    <code>fachbereich</code> – <?php esc_html_e('nur Stellen eines Fachbereichs.', 'bs-awo-jobs'); ?>
                    <br><span class="description">
                        <?php
                        if ($dept_source === 'api') {
                            esc_html_e('Der Wert muss der ID aus dem Fachbereichs-Dropdown entsprechen (value-Attribut, z. B. \"6\" für einen API-Fachbereich).', 'bs-awo-jobs');
                        } else {
                            esc_html_e('Der Wert muss genau dem Wert im Mandantenfeld / department_custom entsprechen (z. B. \"Kita\", \"Pflege\", \"Verwaltung\").', 'bs-awo-jobs');
                        }
                        ?>
                    </span>
                </li>
                <li>
                    <code>jobfamily</code> – <?php esc_html_e('nur eine bestimmte Berufsfamilie.', 'bs-awo-jobs'); ?>
                    <br><span class="description">
                        <?php esc_html_e('Der Wert entspricht der ID aus dem Jobfamilien-Dropdown bzw. aus der Schema-Analyse (value-Attribut).', 'bs-awo-jobs'); ?>
                    </span>
                </li>
                <li>
                    <code>vertragsart</code> – <?php esc_html_e('nur eine bestimmte Vertragsart (z. B. \"unbefristet\", \"Teilzeit\").', 'bs-awo-jobs'); ?>
                </li>
                <li><code>hide_filters</code> – <?php esc_html_e('Filterleiste ausblenden (hide_filters=\"1\").', 'bs-awo-jobs'); ?></li>
                <li><code>hide_layout_toggle</code> – <?php esc_html_e('Umschalter Liste/Kacheln ausblenden (hide_layout_toggle=\"1\").', 'bs-awo-jobs'); ?></li>
            </ul>
            <p class="description">
                <?php esc_html_e('Tipp: Die korrekten Werte für fachbereich, jobfamily und vertragsart kannst du direkt den value-Attributen der jeweiligen Filter-Dropdowns auf einer Beispielseite entnehmen.', 'bs-awo-jobs'); ?>
            </p>
            <p>
                <?php esc_html_e('Beispiele:', 'bs-awo-jobs'); ?>
            </p>
            <ul style="list-style: disc; margin-left: 1.5em;">
                <li>
                    <code>[bs_awo_jobs fachbereich="Pflege"]</code><br>
                    <span class="description"><?php esc_html_e('Alle Pflege-Stellen (mit Filterleiste).', 'bs-awo-jobs'); ?></span>
                </li>
                <li>
                    <code>[bs_awo_jobs fachbereich="Kita" ort="Wesel" hide_filters="1" layout="grid"]</code><br>
                    <span class="description"><?php esc_html_e('Kachel-Übersicht aller Kita-Stellen in Wesel ohne Filterleiste.', 'bs-awo-jobs'); ?></span>
                </li>
                <li>
                    <code>[bs_awo_jobs vertragsart="Teilzeit"]</code><br>
                    <span class="description"><?php esc_html_e('Alle Teilzeit-Stellen mit allen Filtern.', 'bs-awo-jobs'); ?></span>
                </li>
            </ul>

            <?php
            // Plugin-Footer
            \BsAwoJobs\Wp\Admin\Footer::render();
            ?>
        </div>
        <?php
    }

    /**
     * Gibt Design-Optionen zurück.
     */
    public static function get_design_options()
    {
        return get_option(self::OPTION_PREFIX . 'design', []);
    }

    /**
     * Gibt Anzeige-Optionen zurück.
     */
    public static function get_display_options()
    {
        return get_option(self::OPTION_PREFIX . 'display', []);
    }
}