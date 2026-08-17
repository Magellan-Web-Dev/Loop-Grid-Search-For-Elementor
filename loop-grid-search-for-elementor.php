<?php
/**
 * Plugin Name:       Loop Grid Search for Elementor
 * Description:       AJAX keyword / date / taxonomy search and filtering for any post type, rendered server-side through an Elementor loop template. Ships as both a shortcode and a drag-and-drop "Loop Grid Search" Elementor widget.
 * Version:           1.8.0
 * Author:            Chris Paschall
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       loop-grid-search
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Elementor tested up to: 4.1.4
 * Elementor Pro tested up to: 4.1.2
 *
 * Flow overview:
 *  1. The shortcode ([loop_grid_search]) or the "Loop Grid Search" Elementor widget builds a
 *     validated, HMAC-signed Config object from its attributes/controls.
 *  2. InterfaceRenderer prints the filter bar, the first (unfiltered) page of results, the
 *     pagination, and a JSON <script> block holding the signed Config.
 *  3. loop-grid-search.js reads that Config, debounces keyword input, and POSTs to admin-ajax.php.
 *  4. SearchEndpoint verifies the nonce, verifies the Config signature, re-validates every value,
 *     builds a WP_Query, and returns fully rendered HTML in a single JSON response.
 *  5. KeywordSearch attaches a tightly scoped posts_clauses filter that turns the keyword into
 *     "(post_title LIKE … OR EXISTS(postmeta row LIKE …))" and detaches itself immediately after.
 *  6. ResultsRenderer renders each result through the chosen Elementor template (with correct
 *     global $post context) or through an overridable PHP card template.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.1+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.1+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Loop Grid Search For Elementor</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin bootstrap.
 * Defines the plugin constants, registers the PSR-4 autoloader, and hands control to the
 * Plugin composition root once every other plugin (Elementor, ACF) has loaded.
 */
} else {

    /** @var string Plugin version. */
    define('LGS_VERSION', '1.8.0');

    /** @var string Absolute path to the main plugin file. */
    define('LGS_FILE', __FILE__);

    /** @var string Absolute path to the plugin root directory (with trailing slash). */
    define('LGS_PATH', plugin_dir_path(__FILE__));

    /** @var string Public URL to the plugin root directory (with trailing slash). */
    define('LGS_URL', plugin_dir_url(__FILE__));

    // Require and register the class-based PSR-4 autoloader before anything else.
    require_once LGS_PATH . 'src/Autoloader.php';
    \LoopGridSearch\Autoloader::register();

    // Initialise after all plugins are loaded so ELEMENTOR_VERSION is already defined.
    add_action('plugins_loaded', static function (): void {
        \LoopGridSearch\Plugin::instance();
    }, 20);
}
