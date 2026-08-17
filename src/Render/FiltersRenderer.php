<?php

declare(strict_types=1);

namespace LoopGridSearch\Render;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\Criteria;
use LoopGridSearch\Support\DateOptions;
use LoopGridSearch\Support\TaxonomyOptions;
use LoopGridSearch\Support\UrlState;

/**
 * Renders the filter bar: keyword field, Month/Year select, one select per configured
 * taxonomy, optional sort select and the Clear Filters button.
 *
 * Rendered once per instance on the initial page load and never replaced by AJAX, so the
 * visitor's focus, caret position and text selection inside the keyword field survive every
 * request.
 *
 * Accessibility:
 *  • every control has a real `<label for="…">` bound to a per-instance unique id
 *  • the wrapper is a `<form role="search">` so the group is announced as a search landmark
 *  • the form has no `action`, so a no-JavaScript submit reloads the same page harmlessly
 *    rather than navigating somewhere broken
 */
final class FiltersRenderer
{
    /**
     * Renders the complete filter bar.
     *
     * @param  Config   $config      Validated instance configuration.
     * @param  Criteria $criteria    Currently applied filters (used to preselect controls).
     * @param  string   $instance_id DOM id prefix unique to this instance.
     * @return string
     */
    public function render(Config $config, Criteria $criteria, string $instance_id): string
    {
        $fields = '';

        if ($config->show_keyword()) {
            $fields .= $this->render_keyword_field($config, $criteria, $instance_id);
        }

        if ($config->show_date()) {
            $fields .= $this->render_date_field($config, $criteria, $instance_id);
        }

        if ($config->show_taxonomy()) {
            foreach ($config->taxonomies() as $taxonomy) {
                $fields .= $this->render_taxonomy_field($config, $criteria, $instance_id, $taxonomy);
            }
        }

        if ($config->show_sort()) {
            $fields .= $this->render_sort_field($config, $criteria, $instance_id);
        }

        if ($config->show_clear()) {
            $fields .= '<div class="ajax-post-search__field ajax-post-search__field--clear">'
                . '<button type="button" class="ajax-post-search__clear">'
                . esc_html((string) $config->get('clear_label'))
                . '</button>'
                . '</div>';
        }

        if ('' === $fields) {
            return '';
        }

        $html = '<form class="ajax-post-search__filters" role="search" novalidate>' . $fields . '</form>';

        /**
         * Filters the rendered filter bar markup.
         *
         * @param string   $html
         * @param Config   $config
         * @param Criteria $criteria
         * @param string   $instance_id
         */
        return (string) apply_filters('lgs_filters_html', $html, $config, $criteria, $instance_id);
    }

    /**
     * Renders the live keyword search input.
     *
     * `type="search"` gives browsers a native clear affordance; `autocomplete="off"` keeps
     * the browser's own dropdown from covering the results while typing.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @param  string   $instance_id
     * @return string
     */
    private function render_keyword_field(Config $config, Criteria $criteria, string $instance_id): string
    {
        $id = $instance_id . '-keyword';

        return '<div class="ajax-post-search__field ajax-post-search__field--keyword">'
            . '<label class="ajax-post-search__label" for="' . esc_attr($id) . '">'
            . esc_html((string) $config->get('keyword_label'))
            . '</label>'
            . '<input'
            . ' type="search"'
            . ' id="' . esc_attr($id) . '"'
            . ' class="ajax-post-search__keyword"'
            . ' name="lgs_keyword"'
            . ' value="' . esc_attr($criteria->keyword()) . '"'
            . ' placeholder="' . esc_attr((string) $config->get('keyword_placeholder')) . '"'
            . ' autocomplete="off"'
            . ' />'
            . '</div>';
    }

    /**
     * Renders the Month / Year dropdown.
     *
     * Options come from the dates that actually exist for the configured post type — see
     * {@see DateOptions}. Nothing is hard-coded and the list is already newest-first.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @param  string   $instance_id
     * @return string
     */
    private function render_date_field(Config $config, Criteria $criteria, string $instance_id): string
    {
        $id       = $instance_id . '-date';
        $options  = DateOptions::get($config->post_type());
        $selected = $criteria->has_date()
            ? sprintf('%04d-%02d', $criteria->year(), $criteria->month())
            : '';

        $html = '<div class="ajax-post-search__field ajax-post-search__field--date">'
            . '<label class="ajax-post-search__label" for="' . esc_attr($id) . '">'
            . esc_html((string) $config->get('date_label'))
            . '</label>'
            . '<select id="' . esc_attr($id) . '" class="ajax-post-search__date" name="lgs_date">'
            . $this->render_option('', (string) $config->get('date_all_label'), '' === $selected);

        foreach ($options as $value => $label) {
            $html .= $this->render_option((string) $value, (string) $label, (string) $value === $selected);
        }

        return $html . '</select></div>';
    }

    /**
     * Renders the term dropdown for one configured taxonomy.
     *
     * Returns '' when the taxonomy has no terms to offer — with post-type scoping on, a
     * taxonomy shared with other post types can legitimately come back empty here, and a
     * dropdown whose only entry is "All Categories" is worse than no dropdown at all.
     *
     * The `data-lgs-taxonomy` attribute is how the script knows which state key and which
     * query parameter a given select owns; the `name` carries the same query parameter, so a
     * dropdown is self-describing in the markup.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @param  string   $instance_id
     * @param  string   $taxonomy    Validated taxonomy slug from the configured list.
     * @return string
     */
    private function render_taxonomy_field(
        Config $config,
        Criteria $criteria,
        string $instance_id,
        string $taxonomy
    ): string {
        $options = TaxonomyOptions::get(
            $taxonomy,
            $config->post_type(),
            $config->taxonomy_terms_in_post_type()
        );

        if ([] === $options) {
            return '';
        }

        $id       = $instance_id . '-taxonomy-' . sanitize_html_class($taxonomy);
        $selected = $criteria->term_for($taxonomy);

        $html = '<div class="ajax-post-search__field ajax-post-search__field--taxonomy">'
            . '<label class="ajax-post-search__label" for="' . esc_attr($id) . '">'
            . esc_html($config->taxonomy_label($taxonomy))
            . '</label>'
            . '<select id="' . esc_attr($id) . '"'
            . ' class="ajax-post-search__taxonomy"'
            . ' name="' . esc_attr(UrlState::term_param($taxonomy)) . '"'
            . ' data-lgs-taxonomy="' . esc_attr($taxonomy) . '">'
            . $this->render_option('', $config->taxonomy_all_label($taxonomy), 0 === $selected);

        foreach ($options as $term_id => $name) {
            $html .= $this->render_option(
                (string) (int) $term_id,
                (string) $name,
                (int) $term_id === $selected
            );
        }

        return $html . '</select></div>';
    }

    /**
     * Renders the optional sort dropdown.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @param  string   $instance_id
     * @return string
     */
    private function render_sort_field(Config $config, Criteria $criteria, string $instance_id): string
    {
        $id = $instance_id . '-sort';

        // With no explicit sort chosen, preselect whichever preset matches the instance's
        // configured default order. If the configured order has no matching preset (for
        // example menu_order), an explicit "Default" option represents it instead.
        $selected = $criteria->sort();

        if ('' === $selected) {
            $selected = Criteria::preset_for($config->orderby(), $config->order());
        }

        $html = '<div class="ajax-post-search__field ajax-post-search__field--sort">'
            . '<label class="ajax-post-search__label" for="' . esc_attr($id) . '">'
            . esc_html((string) $config->get('sort_label'))
            . '</label>'
            . '<select id="' . esc_attr($id) . '" class="ajax-post-search__sort" name="lgs_sort">';

        if ('' === $selected) {
            $html .= $this->render_option('', __('Default', 'loop-grid-search'), true);
        }

        foreach (Criteria::sort_options() as $value => $label) {
            $html .= $this->render_option((string) $value, (string) $label, (string) $value === $selected);
        }

        return $html . '</select></div>';
    }

    /**
     * Renders one `<option>` element.
     *
     * @param  string $value
     * @param  string $label
     * @param  bool   $selected
     * @return string
     */
    private function render_option(string $value, string $label, bool $selected): string
    {
        return '<option value="' . esc_attr($value) . '"' . ($selected ? ' selected' : '') . '>'
            . esc_html($label)
            . '</option>';
    }
}
