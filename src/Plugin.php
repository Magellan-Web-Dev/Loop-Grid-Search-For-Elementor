<?php

declare(strict_types=1);

namespace LoopGridSearch;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Updates\GitHubUpdater;

/**
 * Main plugin singleton.
 *
 * Bootstraps every component. Keeps a single static instance so the plugin
 * cannot be initialised more than once per WordPress request.
 *
 * Boot sequence:
 *   plugins_loaded (priority 20)
 *     └─ Plugin::instance()
 *          ├─ GitHubUpdater::init()          – release checks + "Check for updates" row action
 *          ├─ new Frontend\AssetManager()    – registers CSS/JS handles and the JS settings object
 *          ├─ new Shortcode\SearchShortcode()– [loop_grid_search] and [ajax_post_search]
 *          ├─ new Ajax\SearchEndpoint()      – wp_ajax_lgs_search / wp_ajax_nopriv_lgs_search
 *          └─ Elementor active?
 *               yes → new Widget\WidgetManager()  – widget category + "Loop Grid Search" widget
 *               no  → admin notice (shortcode keeps working)
 *
 * Design note: the shortcode and the AJAX endpoint are registered unconditionally so the
 * plugin degrades gracefully to a plain PHP/AJAX search when Elementor is not installed.
 */
final class Plugin
{
    /**
     * The single instance of this class.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Dependency checker used to test for Elementor, Elementor Pro and ACF.
     *
     * Declared readonly so it cannot be overwritten after construction.
     *
     * @var DependencyChecker
     */
    private readonly DependencyChecker $checker;

    /**
     * Private constructor — use {@see instance()} to obtain the singleton.
     *
     * Instantiates the dependency checker and begins the boot sequence.
     */
    private function __construct()
    {
        $this->checker = new DependencyChecker();
        $this->boot();
    }

    /**
     * Returns (and lazily creates) the singleton instance.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Returns the shared dependency checker.
     *
     * @return DependencyChecker
     */
    public function checker(): DependencyChecker
    {
        return $this->checker;
    }

    /**
     * Registers the update checker and every always-on component, then adds the
     * Elementor-only widget layer when Elementor is available.
     *
     * Timing note: this plugin loads at plugins_loaded priority 20. Elementor
     * fires elementor/loaded during its own plugin load (lower priority), so by
     * the time we run that action has already fired. We therefore check
     * did_action() and call init_elementor_components() directly when Elementor
     * has already loaded, falling back to the hook for any edge-case load orders.
     *
     * @return void
     */
    private function boot(): void
    {
        GitHubUpdater::init();

        // Always-on layer — works with or without Elementor.
        new Frontend\AssetManager();
        new Shortcode\SearchShortcode();
        new Ajax\SearchEndpoint();

        if (!$this->checker->is_elementor_active()) {
            add_action('admin_notices', [$this->checker, 'notice_elementor_missing']);
            return;
        }

        if (did_action('elementor/loaded') > 0) {
            // elementor/loaded already fired — call directly.
            $this->init_elementor_components();
        } else {
            // Stay hooked for unusual load orders.
            add_action('elementor/loaded', [$this, 'init_elementor_components']);
        }
    }

    /**
     * Instantiates the components that require Elementor to be present.
     *
     * @return void
     */
    public function init_elementor_components(): void
    {
        new Widget\WidgetManager();
    }

    /**
     * Prevents cloning of the singleton instance.
     *
     * @return void
     */
    public function __clone() {}

    /**
     * Prevents unserialization of the singleton instance.
     *
     * @return void
     */
    public function __wakeup() {}
}
