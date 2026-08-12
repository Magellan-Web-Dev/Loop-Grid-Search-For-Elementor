<?php

declare(strict_types=1);

namespace LoopGridSearch;

if (!defined('ABSPATH')) exit;

/**
 * Checks for optional plugin dependencies and surfaces admin notices when they are missing.
 *
 * Unlike most Elementor add-ons, this plugin's core functionality (shortcode, AJAX search,
 * fallback card rendering) works with no Elementor at all. Elementor is only required for
 * the drag-and-drop widget and for rendering results through an Elementor loop template.
 *
 * This class is intentionally free of any Elementor API calls so it remains
 * safe to instantiate even when Elementor is not active.
 */
final class DependencyChecker
{
    /**
     * Returns true when the Elementor core plugin is active.
     *
     * Uses the ELEMENTOR_VERSION constant, which Elementor defines at the top of
     * its main plugin file — reliably present after plugins_loaded regardless of
     * plugin load order.
     *
     * @return bool
     */
    public function is_elementor_active(): bool
    {
        return defined('ELEMENTOR_VERSION');
    }

    /**
     * Returns true when the Elementor Pro plugin is active.
     *
     * Checks both the ELEMENTOR_PRO_VERSION constant and the Pro main class as a
     * fallback, covering edge cases where constants may not yet be defined.
     *
     * Elementor Pro is only needed to *author* Loop Item templates in the editor.
     * Rendering an already-saved template requires Elementor core only.
     *
     * @return bool
     */
    public function is_elementor_pro_active(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin', false);
    }

    /**
     * Returns true when Advanced Custom Fields is active.
     *
     * Only used for admin messaging. The keyword search reads post meta directly
     * with $wpdb, so ACF is never required at query time.
     *
     * @return bool
     */
    public function is_acf_active(): bool
    {
        return class_exists('\ACF', false) || function_exists('acf_get_field_groups');
    }

    /**
     * Outputs a warning-level admin notice when Elementor core is not active.
     *
     * The plugin stays functional as a shortcode with the PHP fallback card, so
     * this is a warning rather than an error.
     *
     * @return void
     */
    public function notice_elementor_missing(): void
    {
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(
            sprintf(
                /* translators: %s: Elementor plugin link */
                __('<strong>Loop Grid Search for Elementor</strong> works best with %s. Without it the shortcode still runs, but the "Loop Grid Search" widget and Elementor template rendering are unavailable.', 'loop-grid-search'),
                '<a href="https://wordpress.org/plugins/elementor/" target="_blank" rel="noopener">Elementor</a>'
            )
        );
        echo '</p></div>';
    }
}
