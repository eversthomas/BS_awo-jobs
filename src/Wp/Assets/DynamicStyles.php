<?php

namespace BsAwoJobs\Wp\Assets;

use BsAwoJobs\Wp\Admin\FrontendSettingsPage;

if (! defined('ABSPATH')) {
    exit;
}

class DynamicStyles
{
    /**
     * Initialisierung.
     */
    public static function init()
    {
        add_action('wp_head', [__CLASS__, 'output_custom_css'], 100);
    }

    /**
     * Gibt benutzerdefiniertes CSS aus.
     */
    public static function output_custom_css()
    {
        // Nur ausgeben, wenn einer unserer Shortcodes auf der Seite ist (robuster Check)
        $content = '';

        if (is_singular()) {
            $post_id = get_queried_object_id();
            if ($post_id) {
                $content = (string) get_post_field('post_content', $post_id);
            }
        } elseif (! empty($GLOBALS['post']) && isset($GLOBALS['post']->post_content)) {
            $content = (string) $GLOBALS['post']->post_content;
        }

        if ($content === '') {
            return;
        }

        // Prüfen, ob einer der relevanten Shortcodes verwendet wird.
        $shortcodes = [
            'bs_awo_jobs',
            'bs_awo_jobs_kita',
            'bs_awo_jobs_pflege',
            'bs_awo_jobs_verwaltung',
        ];

        $has_jobs_shortcode = false;
        foreach ($shortcodes as $code) {
            if (has_shortcode($content, $code)) {
                $has_jobs_shortcode = true;
                break;
            }
        }

        if (! $has_jobs_shortcode) {
            return;
        }

        $design  = FrontendSettingsPage::get_design_options();
        $display = FrontendSettingsPage::get_display_options();

        $design = wp_parse_args($design, [
            'card_bg_color'      => '#ffffff',
            'card_text_color'    => '#333333',
            'card_link_color'    => '#0073aa',
            'card_border_color'  => '#dddddd',
            'button_bg_color'    => '#0073aa',
            'button_text_color'  => '#ffffff',
            'grid_card_width'    => 300,
            'card_border_radius' => 8,
            'card_padding'       => 20,
        ]);

        $display = wp_parse_args($display, [
            'image_height' => 200,
        ]);

        ?>
        <style id="bs-awo-jobs-custom-styles">
            /* Optional: CSS-Variablen, falls du später im CSS mehr darüber steuerst */
            .bs-awo-jobs {
                --bs-awo-card-bg: <?php echo esc_attr($design['card_bg_color']); ?>;
                --bs-awo-card-text: <?php echo esc_attr($design['card_text_color']); ?>;
                --bs-awo-card-link: <?php echo esc_attr($design['card_link_color']); ?>;
                --bs-awo-card-border: <?php echo esc_attr($design['card_border_color']); ?>;
                --bs-awo-btn-bg: <?php echo esc_attr($design['button_bg_color']); ?>;
                --bs-awo-btn-text: <?php echo esc_attr($design['button_text_color']); ?>;
            }

            /* === KACHELN === */
            .bs-awo-job-card {
                background: <?php echo esc_attr($design['card_bg_color']); ?> !important;
                color: <?php echo esc_attr($design['card_text_color']); ?> !important;
                border: 1px solid <?php echo esc_attr($design['card_border_color']); ?> !important;
                border-radius: <?php echo esc_attr($design['card_border_radius']); ?>px !important;
                padding: <?php echo esc_attr($design['card_padding']); ?>px !important;
            }

            .bs-awo-jobs-layout-grid .bs-awo-job-card {
                max-width: <?php echo esc_attr($design['grid_card_width']); ?>px !important;
            }

            .bs-awo-job-card h3,
            .bs-awo-job-card h3 a {
                color: <?php echo esc_attr($design['card_link_color']); ?> !important;
            }

            .bs-awo-job-card .bs-awo-job-image {
                height: <?php echo esc_attr($display['image_height']); ?>px !important;
                overflow: hidden;
            }

            .bs-awo-job-card .bs-awo-job-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }

            .bs-awo-job-card .bs-awo-btn-primary {
                background: <?php echo esc_attr($design['button_bg_color']); ?> !important;
                color: <?php echo esc_attr($design['button_text_color']); ?> !important;
            }

            /* === DETAILANSICHT === */
            .bs-awo-jobs-detail-article {
                color: <?php echo esc_attr($design['card_text_color']); ?> !important;
            }

            .bs-awo-jobs-detail-title {
                color: <?php echo esc_attr($design['card_link_color']); ?> !important;
            }

            .bs-awo-jobs-detail a {
                color: <?php echo esc_attr($design['card_link_color']); ?> !important;
            }

            /* wichtig: dein Detail-Button nutzt .bs-awo-jobs-btn-primary (nicht .bs-awo-btn-primary) */
            .bs-awo-jobs-detail .bs-awo-jobs-btn-primary,
            .bs-awo-jobs-detail .bs-awo-jobs-btn-primary:visited {
                background: <?php echo esc_attr($design['button_bg_color']); ?> !important;
                color: <?php echo esc_attr($design['button_text_color']); ?> !important;
                border-color: <?php echo esc_attr($design['button_bg_color']); ?> !important;
            }

            .bs-awo-jobs-detail .bs-awo-jobs-btn-primary:hover {
                opacity: 0.9;
            }

            /* Detail-Bild optisch konsistent */
            .bs-awo-job-detail-image img {
                border-radius: <?php echo esc_attr($design['card_border_radius']); ?>px !important;
            }
        </style>
        <?php
    }
}