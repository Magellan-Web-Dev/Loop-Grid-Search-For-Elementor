<?php

declare(strict_types=1);

namespace LoopGridSearch\Frontend;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Ajax\SearchEndpoint;

/**
 * Registers and conditionally enqueues the plugin's single CSS and JS file.
 *
 * ## One script, many instances
 *
 * The stylesheet and script are registered once and enqueued at most once per page,
 * regardless of how many shortcode or widget instances are present. Nothing per-instance is
 * ever printed as JavaScript: each instance's configuration travels in an inert
 * `<script type="application/json">` block that {@see \LoopGridSearch\Render\InterfaceRenderer}
 * emits, and the shared script discovers instances by scanning the DOM.
 *
 * ## Conditional loading
 *
 * Handles are *registered* on `wp_enqueue_scripts` but only *enqueued* from
 * {@see enqueue()}, which the shortcode and widget call while rendering. Pages with no
 * search interface therefore download neither file. WordPress prints assets enqueued after
 * `wp_head` in the footer, so a late enqueue from inside `the_content` still works.
 *
 * The Elementor widget additionally declares these handles through
 * `get_style_depends()` / `get_script_depends()`, which lets Elementor's own conditional
 * asset loader account for them.
 */
final class AssetManager
{
    /** @var string Shared handle for both the stylesheet and the script. */
    public const HANDLE = 'loop-grid-search';

    /**
     * Registers the asset handles early on both the frontend and the Elementor editor
     * preview.
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'register_assets'], 5);
    }

    /**
     * Registers the stylesheet, the script and the script's settings object.
     *
     * `wp_localize_script()` runs here, at registration time, so the settings object is
     * guaranteed to be attached before the script can be printed. The nonce and the
     * admin-ajax URL are the only two values the script needs globally — everything else is
     * per-instance.
     *
     * @return void
     */
    public function register_assets(): void
    {
        self::register();
    }

    /**
     * Performs the actual registration.
     *
     * Static so {@see enqueue()} can recover from a missing registration without
     * constructing another instance (which would add a duplicate `wp_enqueue_scripts`
     * callback).
     *
     * @return void
     */
    private static function register(): void
    {
        wp_register_style(
            self::HANDLE,
            LGS_URL . 'assets/css/loop-grid-search.css',
            [],
            LGS_VERSION
        );

        wp_register_script(
            self::HANDLE,
            LGS_URL . 'assets/js/loop-grid-search.js',
            [],
            LGS_VERSION,
            true
        );

        wp_localize_script(self::HANDLE, 'LGS_Settings', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'action'   => SearchEndpoint::ACTION,
            'nonce'    => wp_create_nonce(SearchEndpoint::ACTION),
            'debounce' => (int) apply_filters('lgs_search_debounce_ms', 400),
            'i18n'     => [
                'searching' => __('Searching…', 'loop-grid-search'),
                /* translators: %d: number of matching results */
                'results'   => __('%d results found.', 'loop-grid-search'),
                'oneResult' => __('1 result found.', 'loop-grid-search'),
                'noResults' => __('No results found.', 'loop-grid-search'),
                'error'     => __('Something went wrong loading the results. Please try again.', 'loop-grid-search'),
            ],
        ]);
    }

    /**
     * Enqueues the assets for the current page.
     *
     * Safe to call any number of times — WordPress ignores repeat enqueues of the same
     * handle. Falls back to registering the handles first in the unusual case where a
     * shortcode is rendered outside a normal page lifecycle (for example, from a REST
     * request or a CLI render) and `wp_enqueue_scripts` never fired.
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (!wp_style_is(self::HANDLE, 'registered') || !wp_script_is(self::HANDLE, 'registered')) {
            self::register();
        }

        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
    }
}
