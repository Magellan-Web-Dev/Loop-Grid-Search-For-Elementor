<?php
/**
 * Elementor IDE stubs for Loop Grid Search for Elementor.
 *
 * These declarations exist solely to give IDEs (Intelephense, PHPStorm, etc.) type
 * information for Elementor classes that are not available as Composer packages. This file
 * is NEVER loaded at runtime — it is not required anywhere in the plugin and the PSR-4
 * autoloader only handles the LoopGridSearch\ namespace. If Elementor is active, the real
 * classes are already in memory.
 *
 * @see https://github.com/elementor/elementor
 */

// phpcs:disable

namespace Elementor;

if (false) {

    /**
     * Base class for all Elementor elements (widgets, sections, columns, containers).
     */
    class Element_Base
    {
        /**
         * Returns the element's unique name / type slug.
         *
         * @return string
         */
        public function get_name(): string
        {
            return '';
        }

        /**
         * Returns the element's instance id within the document.
         *
         * @return string
         */
        public function get_id(): string
        {
            return '';
        }

        /**
         * Returns all settings resolved for display (dynamic tags applied).
         *
         * @param  string|null $_setting_key  Optional specific setting key.
         * @return array<string, mixed>|mixed
         */
        public function get_settings_for_display(?string $_setting_key = null): mixed
        {
            return [];
        }

        /**
         * Adds or merges an HTML attribute value on a named attribute group.
         *
         * @param  string                     $_element    Attribute group key (e.g. "_wrapper").
         * @param  string|array<string,mixed> $_key        Attribute name or key-value map.
         * @param  mixed                      $_value      Attribute value (when $_key is a string).
         * @param  bool                       $_overwrite  Replace existing value instead of merging.
         * @return static
         */
        public function add_render_attribute(
            string $_element,
            string|array|null $_key = null,
            mixed $_value = null,
            bool $_overwrite = false
        ): static {
            return $this;
        }

        /**
         * Opens a new controls section.
         *
         * @param  string              $_section_id  Unique section identifier.
         * @param  array<string,mixed> $_args        Section configuration (label, tab, condition…).
         * @return void
         */
        public function start_controls_section(string $_section_id, array $_args = []): void {}

        /**
         * Closes the most recently opened controls section.
         *
         * @return void
         */
        public function end_controls_section(): void {}

        /**
         * Registers a single control inside the active section.
         *
         * @param  string              $_id    Unique control identifier.
         * @param  array<string,mixed> $_args  Control configuration (type, label, default…).
         * @return void
         */
        public function add_control(string $_id, array $_args): void {}

        /**
         * Registers a control with per-breakpoint variants (desktop / tablet / mobile).
         *
         * @param  string              $_id    Unique control identifier.
         * @param  array<string,mixed> $_args  Control configuration.
         * @return void
         */
        public function add_responsive_control(string $_id, array $_args): void {}

        /**
         * Returns all raw settings for this element.
         *
         * @param  string|null $_setting_key
         * @return array<string, mixed>|mixed
         */
        public function get_settings(?string $_setting_key = null): mixed
        {
            return [];
        }
    }

    /**
     * Base class for all draggable Elementor widgets.
     */
    class Widget_Base extends Element_Base
    {
        /**
         * Returns the widget's human-readable display title.
         *
         * @return string
         */
        public function get_title(): string
        {
            return '';
        }

        /**
         * Returns the Elementor icon class string for the widget panel icon.
         *
         * @return string
         */
        public function get_icon(): string
        {
            return '';
        }

        /**
         * Returns the widget panel category slugs this widget belongs to.
         *
         * @return string[]
         */
        public function get_categories(): array
        {
            return [];
        }

        /**
         * Returns search keyword strings used to find this widget in the panel.
         *
         * @return string[]
         */
        public function get_keywords(): array
        {
            return [];
        }

        /**
         * Returns stylesheet handles this widget depends on.
         *
         * @return string[]
         */
        public function get_style_depends(): array
        {
            return [];
        }

        /**
         * Returns script handles this widget depends on.
         *
         * @return string[]
         */
        public function get_script_depends(): array
        {
            return [];
        }

        /**
         * Registers controls for this widget. Called once per widget type.
         *
         * @return void
         */
        protected function register_controls(): void {}

        /**
         * Renders the widget's HTML on the frontend.
         *
         * @return void
         */
        protected function render(): void {}
    }

    /**
     * Manages the global registry of registered Elementor widgets.
     */
    class Widgets_Manager
    {
        /**
         * Adds a widget instance to the registry.
         *
         * @param  Widget_Base $_widget  The widget to register.
         * @return void
         */
        public function register(Widget_Base $_widget): void {}
    }

    /**
     * Manages the element types and the widget panel categories.
     */
    class Elements_Manager
    {
        /**
         * Registers a widget panel category.
         *
         * @param  string              $_category_name       Category slug.
         * @param  array<string,mixed> $_category_properties Title, icon, active flag.
         * @return void
         */
        public function add_category(string $_category_name, array $_category_properties): void {}
    }

    /**
     * Provides control type constants and utility methods for building Elementor controls.
     */
    class Controls_Manager
    {
        /** @var string Content tab identifier. */
        const TAB_CONTENT = 'content';

        /** @var string Style tab identifier. */
        const TAB_STYLE = 'style';

        /** @var string Advanced tab identifier. */
        const TAB_ADVANCED = 'advanced';

        /** @var string Plain-text input control type. */
        const TEXT = 'text';

        /** @var string Multi-line textarea control type. */
        const TEXTAREA = 'textarea';

        /** @var string Number input control type. */
        const NUMBER = 'number';

        /** @var string Dropdown select control type. */
        const SELECT = 'select';

        /** @var string Searchable Select2 dropdown control type. */
        const SELECT2 = 'select2';

        /** @var string On/off switcher control type. */
        const SWITCHER = 'switcher';

        /** @var string Non-interactive section heading. */
        const HEADING = 'heading';

        /** @var string Horizontal rule between controls. */
        const DIVIDER = 'divider';

        /** @var string Range slider with unit selection. */
        const SLIDER = 'slider';

        /** @var string Colour picker control type. */
        const COLOR = 'color';
    }

    /**
     * Static utility helpers used throughout Elementor.
     */
    class Utils
    {
        /**
         * Returns the URL of Elementor's built-in placeholder image.
         *
         * @return string
         */
        public static function get_placeholder_image_src(): string
        {
            return '';
        }
    }

    /**
     * Elementor's main singleton.
     *
     * Only the members this plugin touches are stubbed.
     */
    class Plugin
    {
        /** @var Plugin */
        public static $instance;

        /** @var \Elementor\Frontend */
        public $frontend;

        /** @var Widgets_Manager */
        public $widgets_manager;

        /** @var Elements_Manager */
        public $elements_manager;
    }

    /**
     * Elementor's frontend renderer.
     */
    class Frontend
    {
        /**
         * Renders a saved Elementor document and returns its HTML.
         *
         * Elementor's documented public render API — the same call used by Elementor Pro's
         * Template widget and the [elementor-template] shortcode.
         *
         * @param  int  $_post_id  The template/document post ID.
         * @param  bool $_with_css Whether to print the document CSS inline.
         * @return string
         */
        public function get_builder_content_for_display(int $_post_id, bool $_with_css = false): string
        {
            return '';
        }
    }

} // end if (false)
