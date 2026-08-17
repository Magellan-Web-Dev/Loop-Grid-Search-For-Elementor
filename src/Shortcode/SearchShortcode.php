<?php

declare(strict_types=1);

namespace LoopGridSearch\Shortcode;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Render\InterfaceRenderer;
use LoopGridSearch\Support\Config;

/**
 * Registers the search shortcode.
 *
 * Two tags are registered and behave identically:
 *
 *   [loop_grid_search]   — the canonical tag, matching the plugin name
 *   [ajax_post_search]   — convenience alias
 *
 * Both work inside an Elementor **Shortcode** widget, inside the classic editor, inside a
 * Gutenberg shortcode block, and in `do_shortcode()` from a theme template.
 *
 * The callback itself is deliberately three lines long: validating the attributes is
 * {@see Config}'s job and building the markup is {@see InterfaceRenderer}'s job.
 *
 * Example:
 *
 *   [ajax_post_search
 *       post_type="post"
 *       acf_search_field="excerpt"
 *       taxonomy="post_tag"
 *       posts_per_page="9"
 *       elementor_template_id="1234"]
 *
 * With two term dropdowns, relabelling the second one:
 *
 *   [ajax_post_search
 *       post_type="resource"
 *       taxonomies="resource_type,post_tag"
 *       taxonomy_labels="post_tag:Topic"
 *       taxonomy_all_labels="post_tag:All Topics"]
 */
final class SearchShortcode
{
    /** @var string Canonical shortcode tag. */
    public const TAG = 'loop_grid_search';

    /** @var string Alias tag kept for readability in content. */
    public const ALIAS = 'ajax_post_search';

    /**
     * Maps public shortcode attribute names to internal {@see Config} keys.
     *
     * The public names favour clarity for site authors (`acf_search_field`,
     * `elementor_template_id`); the internal names stay implementation-neutral, because the
     * search does not actually require ACF and the template does not have to be a Loop Item.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTE_MAP = [
        'post_type'             => 'post_type',

        // Search scope. All of these accept a comma-separated list. The first two names are
        // the original single-field spelling and are kept so existing shortcodes keep
        // working; they now feed the same multi-key list as the canonical names.
        'acf_search_field'      => 'search_meta_keys',
        'meta_search_field'     => 'search_meta_keys',
        'search_meta_keys'      => 'search_meta_keys',
        'search_in'             => 'search_columns',
        'search_columns'        => 'search_columns',

        // Taxonomy filters. `taxonomy` is the original single-dropdown spelling and still
        // names the first dropdown; `taxonomies` is a comma-separated list, appended after it,
        // for a bar with more than one term dropdown.
        'taxonomy'              => 'taxonomy',
        'taxonomies'            => 'taxonomies',
        'taxonomy_terms_in_post_type' => 'taxonomy_terms_in_post_type',

        'posts_per_page'        => 'posts_per_page',
        'elementor_template_id' => 'template_id',
        'template_id'           => 'template_id',
        'orderby'               => 'orderby',
        'order'                 => 'order',
        'columns'               => 'columns',
        'gap'                   => 'gap',
        // Pagination. `pagination_numbers="no"` is the original boolean spelling and still
        // works; Config maps it onto pagination_mode.
        'pagination_numbers'     => 'pagination_numbers',
        'pagination_mode'        => 'pagination_mode',
        'pagination_max_numbers' => 'pagination_max_numbers',
        'pagination_prev_label'  => 'pagination_prev_label',
        'pagination_next_label'  => 'pagination_next_label',
        // `seo_pagination="no"` reverts to non-crawlable buttons and leaves the URL alone.
        'seo_pagination'         => 'seo_pagination',
        'show_keyword'          => 'show_keyword',
        'show_date'             => 'show_date',
        'show_taxonomy'         => 'show_taxonomy',
        'show_sort'             => 'show_sort',
        'show_clear'            => 'show_clear',
        'keyword_label'         => 'keyword_label',
        'keyword_placeholder'   => 'keyword_placeholder',
        'date_label'            => 'date_label',
        'date_all_label'        => 'date_all_label',
        // Labels for the first dropdown. Every further dropdown is labelled through the two
        // per-taxonomy attributes below, or from the taxonomy's own registered names.
        'taxonomy_label'        => 'taxonomy_label',
        'taxonomy_all_label'    => 'taxonomy_all_label',
        'taxonomy_labels'       => 'taxonomy_labels',
        'taxonomy_all_labels'   => 'taxonomy_all_labels',
        'sort_label'            => 'sort_label',
        'clear_label'           => 'clear_label',
        'no_results_text'       => 'no_results_text',
    ];

    /**
     * Config keys whose attribute value is a `slug:label|slug:label` map rather than a scalar.
     *
     * @var list<string>
     */
    private const LABEL_MAP_KEYS = ['taxonomy_labels', 'taxonomy_all_labels'];

    /**
     * Registers both shortcode tags.
     */
    public function __construct()
    {
        add_shortcode(self::TAG, [$this, 'render']);
        add_shortcode(self::ALIAS, [$this, 'render']);
    }

    /**
     * Shortcode callback.
     *
     * @param  array<string, mixed>|string $atts Raw shortcode attributes.
     * @return string Rendered interface HTML.
     */
    public function render(mixed $atts = []): string
    {
        $atts   = shortcode_atts($this->defaults(), is_array($atts) ? $atts : [], self::TAG);
        $config = Config::from_attributes($this->map_attributes($atts));

        return (new InterfaceRenderer())->render($config);
    }

    /**
     * Builds the shortcode_atts() default map from the public attribute names.
     *
     * Every recognised attribute defaults to null, which lets {@see Config::normalize()}
     * distinguish "not supplied" (use the Config default) from "supplied as empty string"
     * (for example `taxonomy=""` to switch the term filter off).
     *
     * @return array<string, null>
     */
    private function defaults(): array
    {
        return array_fill_keys(array_keys(self::ATTRIBUTE_MAP), null);
    }

    /**
     * Translates public attribute names into internal Config keys, dropping anything the
     * author did not supply.
     *
     * @param  array<string, mixed> $atts
     * @return array<string, mixed>
     */
    private function map_attributes(array $atts): array
    {
        $mapped = [];

        foreach (self::ATTRIBUTE_MAP as $attribute => $config_key) {
            if (isset($atts[$attribute]) && null !== $atts[$attribute]) {
                $mapped[$config_key] = in_array($config_key, self::LABEL_MAP_KEYS, true)
                    ? $this->parse_label_map((string) $atts[$attribute])
                    : $atts[$attribute];
            }
        }

        return $mapped;
    }

    /**
     * Parses a per-taxonomy label attribute into the slug => label map Config expects.
     *
     * Format: `taxonomy_labels="category:Section|post_tag:Topic"`. Pipe-separated rather than
     * comma-separated because a label is prose and may well contain a comma; a taxonomy slug
     * can contain neither a pipe nor a colon, so the first colon always ends the slug.
     *
     * @param  string $value
     * @return array<string, string>
     */
    private function parse_label_map(string $value): array
    {
        $map = [];

        foreach (explode('|', $value) as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }

            [$taxonomy, $label] = explode(':', $pair, 2);

            $taxonomy = trim($taxonomy);
            $label    = trim($label);

            if ('' !== $taxonomy && '' !== $label) {
                $map[$taxonomy] = $label;
            }
        }

        return $map;
    }
}
