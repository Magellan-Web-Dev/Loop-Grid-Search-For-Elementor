<?php

declare(strict_types=1);

namespace LoopGridSearch\Widget;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use LoopGridSearch\Frontend\AssetManager;
use LoopGridSearch\Render\InterfaceRenderer;
use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\FieldRegistry;

/**
 * The "Loop Grid Search" Elementor widget.
 *
 * Appears in the editor panel under a "Loop Grid Search" section, is drag-and-drop
 * placeable, and every option is editable from the panel — no shortcode required.
 *
 * The widget is a thin adapter: it turns Elementor control values into a {@see Config} and
 * hands off to {@see InterfaceRenderer}, the exact same path the shortcode takes. There is
 * one implementation of the search interface, and the widget and the shortcode cannot drift
 * apart.
 *
 * Only Elementor's stable, documented widget API is used: get_name/get_title/get_icon/
 * get_categories/get_keywords, register_controls(), render(), and
 * get_style_depends()/get_script_depends(). No private methods, no undocumented endpoints.
 */
final class LoopGridSearchWidget extends Widget_Base
{
    /**
     * Widget type slug. Also forms the Elementor JS hook name
     * (`frontend/element_ready/loop-grid-search.default`).
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'loop-grid-search';
    }

    /**
     * Human-readable title shown in the Elementor panel.
     *
     * @return string
     */
    public function get_title(): string
    {
        return esc_html__('Loop Grid Search', 'loop-grid-search');
    }

    /**
     * Panel icon.
     *
     * @return string
     */
    public function get_icon(): string
    {
        return 'eicon-filter';
    }

    /**
     * Panel categories this widget belongs to.
     *
     * @return string[]
     */
    public function get_categories(): array
    {
        return [WidgetManager::CATEGORY, 'general'];
    }

    /**
     * Search keywords for the panel's widget search box.
     *
     * @return string[]
     */
    public function get_keywords(): array
    {
        return ['search', 'filter', 'loop', 'grid', 'ajax', 'posts', 'archive', 'taxonomy', 'acf'];
    }

    /**
     * Stylesheet handles this widget needs.
     *
     * Declaring them lets Elementor's conditional asset loading account for the widget.
     *
     * @return string[]
     */
    public function get_style_depends(): array
    {
        return [AssetManager::HANDLE];
    }

    /**
     * Script handles this widget needs.
     *
     * @return string[]
     */
    public function get_script_depends(): array
    {
        return [AssetManager::HANDLE];
    }

    // ── Controls ──────────────────────────────────────────────────────────────────────

    /**
     * Registers every panel control.
     *
     * @return void
     */
    protected function register_controls(): void
    {
        $this->register_query_section();
        $this->register_search_section();
        $this->register_filter_section();
        $this->register_results_section();
        $this->register_pagination_section();
        $this->register_layout_style_section();
    }

    /**
     * Content ▸ Query — what gets searched.
     *
     * @return void
     */
    private function register_query_section(): void
    {
        $this->start_controls_section('lgs_section_query', [
            'label' => esc_html__('Query', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('post_type', [
            'label'       => esc_html__('Post Type', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT,
            'options'     => self::post_type_options(),
            'default'     => 'post',
            'description' => esc_html__('Only public post types are listed.', 'loop-grid-search'),
        ]);

        $this->add_control('posts_per_page', [
            'label'   => esc_html__('Results Per Page', 'loop-grid-search'),
            'type'    => Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 100,
            'step'    => 1,
            'default' => 9,
        ]);

        $this->add_control('orderby', [
            'label'   => esc_html__('Default Order By', 'loop-grid-search'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'date',
            'options' => [
                'date'       => esc_html__('Published Date', 'loop-grid-search'),
                'title'      => esc_html__('Title', 'loop-grid-search'),
                'modified'   => esc_html__('Last Modified', 'loop-grid-search'),
                'menu_order' => esc_html__('Menu Order', 'loop-grid-search'),
                'ID'         => esc_html__('Post ID', 'loop-grid-search'),
            ],
        ]);

        $this->add_control('order', [
            'label'       => esc_html__('Default Direction', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'DESC',
            'options'     => [
                'DESC' => esc_html__('Descending (newest first)', 'loop-grid-search'),
                'ASC'  => esc_html__('Ascending (oldest first)', 'loop-grid-search'),
            ],
            'description' => esc_html__('Clearing the filters always returns to this order.', 'loop-grid-search'),
        ]);

        $this->end_controls_section();
    }

    /**
     * Content ▸ Keyword Search — the keyword field and the custom field it also searches.
     *
     * @return void
     */
    private function register_search_section(): void
    {
        $this->start_controls_section('lgs_section_search', [
            'label' => esc_html__('Keyword Search', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('show_keyword', [
            'label'        => esc_html__('Show Keyword Field', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Yes', 'loop-grid-search'),
            'label_off'    => esc_html__('No', 'loop-grid-search'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        // ── What the keyword matches against ─────────────────────────────────────
        // Three controls, all OR-ed together at query time:
        //   search_in           → native wp_posts columns (post title by default)
        //   search_meta_fields  → ACF fields, discovered and picked visually
        //   search_meta_custom  → any other meta key, typed by hand
        $this->add_control('search_in', [
            'label'       => esc_html__('Search In', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => FieldRegistry::get_column_options(),
            'default'     => ['post_title'],
            'description' => esc_html__('Built-in post fields the keyword matches. Post Title is selected by default.', 'loop-grid-search'),
            'condition'   => ['show_keyword' => 'yes'],
        ]);

        $acf_options = FieldRegistry::get_meta_field_options();

        $this->add_control('search_meta_fields', [
            'label'       => esc_html__('Also Search Custom Fields', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $acf_options,
            'default'     => [],
            'description' => [] === $acf_options
                ? esc_html__('No searchable ACF fields were detected. Enter meta keys by hand below instead.', 'loop-grid-search')
                : esc_html__('ACF fields whose value the keyword also matches. Only text-style fields are listed — types that store IDs or arrays cannot be searched meaningfully.', 'loop-grid-search'),
            'condition'   => ['show_keyword' => 'yes'],
        ]);

        $this->add_control('search_meta_custom', [
            'label'       => esc_html__('Additional Meta Keys', 'loop-grid-search'),
            'type'        => Controls_Manager::TEXT,
            'default'     => 'excerpt',
            'placeholder' => 'excerpt, summary',
            'label_block' => true,
            'ai'          => ['active' => false],
            'description' => esc_html__('Comma-separated meta keys, for fields not managed by ACF. Combined with the selections above.', 'loop-grid-search'),
            'condition'   => ['show_keyword' => 'yes'],
        ]);

        $this->add_control('keyword_label', [
            'label'       => esc_html__('Field Label', 'loop-grid-search'),
            'type'        => Controls_Manager::TEXT,
            'default'     => esc_html__('Search', 'loop-grid-search'),
            'ai'          => ['active' => false],
            'condition'   => ['show_keyword' => 'yes'],
        ]);

        $this->add_control('keyword_placeholder', [
            'label'     => esc_html__('Placeholder', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('Search…', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_keyword' => 'yes'],
        ]);

        $this->end_controls_section();
    }

    /**
     * Content ▸ Filters — date, taxonomy, sort and clear controls.
     *
     * @return void
     */
    private function register_filter_section(): void
    {
        $this->start_controls_section('lgs_section_filters', [
            'label' => esc_html__('Filters', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        // ── Month / Year ─────────────────────────────────────────────────────────
        $this->add_control('show_date', [
            'label'        => esc_html__('Show Month / Year Filter', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
            'description'  => esc_html__('Options are generated from the dates that actually exist for the selected post type.', 'loop-grid-search'),
        ]);

        $this->add_control('date_label', [
            'label'     => esc_html__('Date Label', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('Date', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_date' => 'yes'],
        ]);

        $this->add_control('date_all_label', [
            'label'     => esc_html__('"All Dates" Option Text', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('All Dates', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_date' => 'yes'],
        ]);

        // ── Taxonomy ─────────────────────────────────────────────────────────────
        $this->add_control('lgs_taxonomy_divider', ['type' => Controls_Manager::DIVIDER]);

        $this->add_control('show_taxonomy', [
            'label'        => esc_html__('Show Taxonomy Filter', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $this->add_control('taxonomy', [
            'label'       => esc_html__('Taxonomy', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT,
            'options'     => self::taxonomy_options(),
            'default'     => 'post_tag',
            'condition'   => ['show_taxonomy' => 'yes'],
            'description' => esc_html__('Any public taxonomy works, including custom ones such as resource_type.', 'loop-grid-search'),
        ]);

        $this->add_control('taxonomy_label', [
            'label'     => esc_html__('Taxonomy Label', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('Tag', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_taxonomy' => 'yes'],
        ]);

        $this->add_control('taxonomy_all_label', [
            'label'     => esc_html__('"All Terms" Option Text', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('All Tags', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_taxonomy' => 'yes'],
        ]);

        // ── Sort ─────────────────────────────────────────────────────────────────
        $this->add_control('lgs_sort_divider', ['type' => Controls_Manager::DIVIDER]);

        $this->add_control('show_sort', [
            'label'        => esc_html__('Show Sort Dropdown', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => '',
        ]);

        $this->add_control('sort_label', [
            'label'     => esc_html__('Sort Label', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('Sort By', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_sort' => 'yes'],
        ]);

        // ── Clear ────────────────────────────────────────────────────────────────
        $this->add_control('lgs_clear_divider', ['type' => Controls_Manager::DIVIDER]);

        $this->add_control('show_clear', [
            'label'        => esc_html__('Show Clear Filters Button', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $this->add_control('clear_label', [
            'label'     => esc_html__('Clear Button Text', 'loop-grid-search'),
            'type'      => Controls_Manager::TEXT,
            'default'   => esc_html__('Clear Filters', 'loop-grid-search'),
            'ai'        => ['active' => false],
            'condition' => ['show_clear' => 'yes'],
        ]);

        $this->end_controls_section();
    }

    /**
     * Content ▸ Results — how each result is rendered, plus pagination and empty state.
     *
     * @return void
     */
    private function register_results_section(): void
    {
        $this->start_controls_section('lgs_section_results', [
            'label' => esc_html__('Results', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        // Note: Elementor's select control ignores `options` entirely when `groups` is
        // present, so the "no template" choice is the first entry of the group list.
        $this->add_control('template_id', [
            'label'       => esc_html__('Result Template', 'loop-grid-search'),
            'type'        => Controls_Manager::SELECT,
            'default'     => '',
            'groups'      => self::template_groups(),
            'description' => esc_html__('Choose an Elementor template (a Loop Item template is ideal) to render each result. Leave unset to use the plugin\'s PHP card, which a theme can override at loop-grid-search/result-card.php.', 'loop-grid-search'),
        ]);

        $this->add_control('no_results_text', [
            'label'   => esc_html__('No Results Message', 'loop-grid-search'),
            'type'    => Controls_Manager::TEXT,
            'default' => esc_html__('No results found matching your search.', 'loop-grid-search'),
            'ai'      => ['active' => false],
        ]);

        $this->end_controls_section();
    }

    /**
     * Content ▸ Pagination — mode, button text and number truncation.
     *
     * @return void
     */
    private function register_pagination_section(): void
    {
        $this->start_controls_section('lgs_section_pagination', [
            'label' => esc_html__('Pagination', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('pagination_mode', [
            'label'   => esc_html__('Pagination Style', 'loop-grid-search'),
            'type'    => Controls_Manager::SELECT,
            'default' => 'numbers',
            'options' => [
                'numbers'   => esc_html__('Previous / Next + Page Numbers', 'loop-grid-search'),
                'prev_next' => esc_html__('Previous / Next Only', 'loop-grid-search'),
            ],
            'description' => esc_html__('"Previous / Next Only" shows a "Page 2 of 5" counter instead of numbered buttons.', 'loop-grid-search'),
        ]);

        $this->add_control('pagination_prev_label', [
            'label'       => esc_html__('Previous Button Text', 'loop-grid-search'),
            'type'        => Controls_Manager::TEXT,
            'default'     => esc_html__('Previous', 'loop-grid-search'),
            'placeholder' => esc_html__('Previous', 'loop-grid-search'),
            'ai'          => ['active' => false],
        ]);

        $this->add_control('pagination_next_label', [
            'label'       => esc_html__('Next Button Text', 'loop-grid-search'),
            'type'        => Controls_Manager::TEXT,
            'default'     => esc_html__('Next', 'loop-grid-search'),
            'placeholder' => esc_html__('Next', 'loop-grid-search'),
            'ai'          => ['active' => false],
        ]);

        $this->add_control('pagination_max_numbers', [
            'label'       => esc_html__('Max Page Numbers Shown', 'loop-grid-search'),
            'type'        => Controls_Manager::NUMBER,
            'min'         => 3,
            'max'         => 50,
            'step'        => 1,
            'default'     => 6,
            'condition'   => ['pagination_mode' => 'numbers'],
            'description' => esc_html__('Beyond this many pages the list truncates with an ellipsis and the visible window follows the current page. With 9 pages and a limit of 6, page 4 shows: … 2 3 4 5 6 7 …', 'loop-grid-search'),
        ]);

        $this->add_control('seo_pagination', [
            'label'        => esc_html__('SEO-Friendly Page Links', 'loop-grid-search'),
            'type'         => Controls_Manager::SWITCHER,
            'default'      => 'yes',
            'separator'    => 'before',
            'label_on'     => esc_html__('On', 'loop-grid-search'),
            'label_off'    => esc_html__('Off', 'loop-grid-search'),
            'return_value' => 'yes',
            'description'  => esc_html__('Renders pages as real links (?lgs_page=2) so search engines can crawl every page and a shared or reloaded URL restores the same results. Clicks are still handled without a page reload. Turn this off on the second search widget of a page that has two, so they do not share one set of URL parameters.', 'loop-grid-search'),
        ]);

        $this->end_controls_section();
    }

    /**
     * Style ▸ Results Grid — column count and gap.
     *
     * Both controls write CSS custom properties through Elementor's `selectors`, so the
     * generated CSS lands in the page's Elementor stylesheet. The plugin's own scoped
     * defaults use `:where()` (zero specificity), so these values always win.
     *
     * @return void
     */
    private function register_layout_style_section(): void
    {
        $this->start_controls_section('lgs_section_layout', [
            'label' => esc_html__('Results Grid', 'loop-grid-search'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('columns', [
            'label'          => esc_html__('Columns', 'loop-grid-search'),
            'type'           => Controls_Manager::SELECT,
            'default'        => '3',
            'tablet_default' => '2',
            'mobile_default' => '1',
            'options'        => [
                '1' => '1',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
            ],
            'selectors' => [
                '{{WRAPPER}} .ajax-post-search' => '--lgs-columns: {{VALUE}};',
            ],
        ]);

        $this->add_responsive_control('gap', [
            'label'      => esc_html__('Gap', 'loop-grid-search'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'range'      => [
                'px'  => ['min' => 0, 'max' => 120],
                'em'  => ['min' => 0, 'max' => 10],
                'rem' => ['min' => 0, 'max' => 10],
            ],
            'default'   => ['unit' => 'px', 'size' => 24],
            'selectors' => [
                '{{WRAPPER}} .ajax-post-search' => '--lgs-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    // ── Render ────────────────────────────────────────────────────────────────────────

    /**
     * Renders the widget on the frontend and in the editor preview.
     *
     * Uses get_settings_for_display() so any dynamic tags an author has applied to the text
     * controls are already resolved. The Elementor widget id is passed through as the DOM id
     * so it stays stable across editor re-renders and remains unique when several instances
     * sit on one page.
     *
     * @return void
     */
    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        $config = Config::from_attributes([
            'post_type'           => $settings['post_type'] ?? null,
            'posts_per_page'      => $settings['posts_per_page'] ?? null,
            'orderby'             => $settings['orderby'] ?? null,
            'order'               => $settings['order'] ?? null,
            'search_columns'      => $settings['search_in'] ?? null,
            'search_meta_keys'    => $this->collect_search_meta_keys($settings),
            'taxonomy'            => $settings['taxonomy'] ?? null,
            'template_id'         => $settings['template_id'] ?? null,
            'pagination_mode'        => $settings['pagination_mode'] ?? null,
            'pagination_max_numbers' => $settings['pagination_max_numbers'] ?? null,
            'pagination_prev_label'  => $settings['pagination_prev_label'] ?? null,
            'pagination_next_label'  => $settings['pagination_next_label'] ?? null,
            'seo_pagination'         => $settings['seo_pagination'] ?? null,
            'show_keyword'        => $settings['show_keyword'] ?? null,
            'show_date'           => $settings['show_date'] ?? null,
            'show_taxonomy'       => $settings['show_taxonomy'] ?? null,
            'show_sort'           => $settings['show_sort'] ?? null,
            'show_clear'          => $settings['show_clear'] ?? null,
            'keyword_label'       => $settings['keyword_label'] ?? null,
            'keyword_placeholder' => $settings['keyword_placeholder'] ?? null,
            'date_label'          => $settings['date_label'] ?? null,
            'date_all_label'      => $settings['date_all_label'] ?? null,
            'taxonomy_label'      => $settings['taxonomy_label'] ?? null,
            'taxonomy_all_label'  => $settings['taxonomy_all_label'] ?? null,
            'sort_label'          => $settings['sort_label'] ?? null,
            'clear_label'         => $settings['clear_label'] ?? null,
            'no_results_text'     => $settings['no_results_text'] ?? null,
            // Columns and gap are intentionally omitted: the Style tab controls above emit
            // real responsive CSS for them, which the plugin's zero-specificity defaults
            // never fight.
        ]);

        // The interface HTML is assembled entirely from escaped values by the renderers.
        echo (new InterfaceRenderer())->render($config, (string) $this->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Merges the visually-picked ACF fields with any hand-entered meta keys.
     *
     * Returns null when neither control has a value, so {@see Config::from_attributes()}
     * falls back to its own default rather than being handed an empty list — which would
     * otherwise silently drop the meta side of the search.
     *
     * @param  array<string, mixed> $settings Resolved widget settings.
     * @return list<string>|null
     */
    private function collect_search_meta_keys(array $settings): ?array
    {
        $keys = [];

        foreach ((array) ($settings['search_meta_fields'] ?? []) as $key) {
            if (is_scalar($key) && '' !== trim((string) $key)) {
                $keys[] = trim((string) $key);
            }
        }

        // The custom control is a comma-separated string; Config validates each entry.
        foreach (explode(',', (string) ($settings['search_meta_custom'] ?? '')) as $key) {
            if ('' !== trim($key)) {
                $keys[] = trim($key);
            }
        }

        // Distinguish "author cleared both controls" (an empty list — honour it) from
        // "neither control exists yet" (null — use the Config default).
        if (!isset($settings['search_meta_fields']) && !isset($settings['search_meta_custom'])) {
            return null;
        }

        return $keys;
    }

    // ── Control option builders ───────────────────────────────────────────────────────

    /**
     * Returns the public post types available in the Post Type dropdown.
     *
     * Attachments are excluded: media has no useful "published in month" archive and its
     * titles are filenames.
     *
     * @return array<string, string>
     */
    private static function post_type_options(): array
    {
        $options = [];

        foreach (get_post_types(['public' => true], 'objects') as $post_type) {
            if ('attachment' === $post_type->name) {
                continue;
            }

            $options[$post_type->name] = $post_type->labels->singular_name ?? $post_type->name;
        }

        return $options;
    }

    /**
     * Returns the public taxonomies available in the Taxonomy dropdown.
     *
     * @return array<string, string>
     */
    private static function taxonomy_options(): array
    {
        $options = [];

        foreach (get_taxonomies(['public' => true, 'show_ui' => true], 'objects') as $taxonomy) {
            $options[$taxonomy->name] = sprintf(
                '%s (%s)',
                $taxonomy->labels->singular_name ?? $taxonomy->name,
                $taxonomy->name
            );
        }

        return $options;
    }

    /**
     * Returns Elementor library templates for the Result Template dropdown, grouped by
     * template type so Loop Item templates are easy to find.
     *
     * Elementor's SELECT control accepts a `groups` array of {label, options} entries and
     * renders each as an <optgroup>. The first group holds the "use the built-in card"
     * choice, because a select control with `groups` ignores its `options` array.
     *
     * @return list<array{label: string, options: array<string, string>}>
     */
    private static function template_groups(): array
    {
        $templates = get_posts([
            'post_type'        => 'elementor_library',
            'post_status'      => ['publish', 'private', 'draft'],
            'posts_per_page'   => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]);

        $grouped = [];

        foreach ($templates as $template) {
            $type = (string) get_post_meta($template->ID, '_elementor_template_type', true);
            $type = '' !== $type ? $type : 'other';

            $grouped[$type][(string) $template->ID] = $template->post_title !== ''
                ? $template->post_title
                : sprintf(
                    /* translators: %d: template post ID */
                    esc_html__('(no title) #%d', 'loop-grid-search'),
                    $template->ID
                );
        }

        // Loop Item templates first — they are what this widget is designed around.
        $order  = ['loop-item', 'section', 'container', 'page', 'other'];
        $labels = [
            'loop-item' => esc_html__('Loop Item Templates', 'loop-grid-search'),
            'section'   => esc_html__('Sections', 'loop-grid-search'),
            'container' => esc_html__('Containers', 'loop-grid-search'),
            'page'      => esc_html__('Pages', 'loop-grid-search'),
            'other'     => esc_html__('Other Templates', 'loop-grid-search'),
        ];

        $groups = [
            [
                'label'   => esc_html__('Default', 'loop-grid-search'),
                'options' => ['' => esc_html__('Built-in PHP card', 'loop-grid-search')],
            ],
        ];

        foreach ($order as $type) {
            if (!empty($grouped[$type])) {
                $groups[] = ['label' => $labels[$type], 'options' => $grouped[$type]];
                unset($grouped[$type]);
            }
        }

        // Anything with an unexpected template type still gets listed.
        foreach ($grouped as $type => $options) {
            $groups[] = ['label' => ucwords(str_replace(['-', '_'], ' ', (string) $type)), 'options' => $options];
        }

        return $groups;
    }
}
