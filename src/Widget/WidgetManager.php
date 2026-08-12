<?php

declare(strict_types=1);

namespace LoopGridSearch\Widget;

if (!defined('ABSPATH')) exit;

/**
 * Registers the plugin's Elementor panel category and its widget.
 *
 * Both hooks used here are Elementor's documented public registration APIs:
 *   • elementor/elements/categories_registered → Elements_Manager::add_category()
 *   • elementor/widgets/register               → Widgets_Manager::register()
 *
 * Instantiated only after Elementor is confirmed active, so nothing here needs to guard
 * against missing Elementor classes.
 */
final class WidgetManager
{
    /** @var string Panel category slug this plugin's widgets live in. */
    public const CATEGORY = 'loop-grid-search';

    /**
     * Hooks category and widget registration.
     */
    public function __construct()
    {
        add_action('elementor/elements/categories_registered', [$this, 'register_category']);
        add_action('elementor/widgets/register', [$this, 'register_widgets']);
    }

    /**
     * Adds the "Loop Grid Search" section to the Elementor widget panel.
     *
     * @param  \Elementor\Elements_Manager $elements_manager
     * @return void
     */
    public function register_category(mixed $elements_manager): void
    {
        if (!is_object($elements_manager) || !method_exists($elements_manager, 'add_category')) {
            return;
        }

        $elements_manager->add_category(self::CATEGORY, [
            'title' => esc_html__('Loop Grid Search', 'loop-grid-search'),
            'icon'  => 'eicon-filter',
        ]);
    }

    /**
     * Registers the "Loop Grid Search" widget with Elementor.
     *
     * @param  \Elementor\Widgets_Manager $widgets_manager
     * @return void
     */
    public function register_widgets(mixed $widgets_manager): void
    {
        if (!is_object($widgets_manager) || !method_exists($widgets_manager, 'register')) {
            return;
        }

        $widgets_manager->register(new LoopGridSearchWidget());
    }
}
