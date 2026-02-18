<?php

namespace BsAwoJobs\Wp\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class Footer
{
    /**
     * Gibt die Plugin-Footer-Zeile aus.
     *
     * @return void
     */
    public static function render()
    {
        ?>
        <div class="bs-awo-jobs-footer" style="margin-top: 40px; padding: 20px; border-top: 2px solid #0073aa; background: #f9f9f9;">
            <div style="display: flex; align-items: center; gap: 20px; max-width: 800px;">
                <div style="flex-shrink: 0;">
                    <img src="<?php echo esc_url(BS_AWO_JOBS_PLUGIN_URL . 'assets/bezugssysteme.png'); ?>" 
                         alt="Bezugssysteme Logo" 
                         style="max-width: 120px; height: auto;background: #1C2A34; padding: 5px; border-radius: 5px;">
                </div>
                <div style="flex-grow: 1;">
                    <p style="margin: 0 0 5px 0; font-size: 14px; font-weight: 600;">
                        Entwickelt von <a href="https://bezugssysteme.de" target="_blank" rel="noopener noreferrer" style="color: #0073aa; text-decoration: none;">Tom Evers</a>
                    </p>
                    <p style="margin: 0; font-size: 12px; color: #666;">
                        <strong style="color: #d63638;">⚠ Benutzung auf eigene Gefahr.</strong> 
                        Dieses Plugin wird ohne Gewährleistung bereitgestellt.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}