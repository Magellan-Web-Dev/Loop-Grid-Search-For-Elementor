<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Builds the taxonomy term dropdown options for a configured taxonomy.
 *
 * Uses get_terms(), which returns lightweight WP_Term objects and is fully cached by
 * WordPress's term cache — no post objects are loaded.
 *
 * Note on hide_empty: WordPress term counts are per-taxonomy, not per-post-type. When a
 * taxonomy is shared between several post types, a term whose only posts belong to a
 * different post type will still appear. That is a WordPress data-model limitation, not a
 * bug here; use the lgs_taxonomy_options filter if a site needs stricter filtering.
 */
final class TaxonomyOptions
{
    /**
     * Per-request cache of term options, keyed by taxonomy slug.
     *
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * Returns term_id => term name options for the given taxonomy.
     *
     * @param  string $taxonomy  Registered taxonomy slug. An empty or unknown slug
     *                           yields an empty option list.
     * @param  string $post_type The post type being queried; passed to the filter so
     *                           integrators can narrow the list.
     * @return array<int, string>
     */
    public static function get(string $taxonomy, string $post_type = ''): array
    {
        if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return [];
        }

        // Keyed by taxonomy *and* post type, because the lgs_taxonomy_options filter
        // receives the post type and may narrow the list differently per instance.
        $cache_key = $taxonomy . '|' . $post_type;

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            // Only the id and name are needed for a <select>.
            'fields'     => 'id=>name',
        ]);

        $options = [];

        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $term_id => $name) {
                $options[(int) $term_id] = (string) $name;
            }
        }

        /**
         * Filters the taxonomy term dropdown options.
         *
         * @param array<int, string> $options   Map of term ID to term name.
         * @param string             $taxonomy  Taxonomy slug.
         * @param string             $post_type Post type the search instance queries.
         */
        $options = (array) apply_filters('lgs_taxonomy_options', $options, $taxonomy, $post_type);

        self::$cache[$cache_key] = $options;

        return $options;
    }
}
