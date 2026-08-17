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
 * ## Why post-type scoping needs its own query
 *
 * WordPress term counts are per-taxonomy, not per-post-type. `hide_empty` therefore keeps a
 * term whose only posts belong to a *different* post type: with `category` shared between
 * `post` and `resource`, a category used only by blog posts still appears in a dropdown that
 * queries resources, where selecting it can only ever return nothing.
 *
 * {@see get()} closes that gap when `$limit_to_post_type` is true by resolving the term IDs
 * that are genuinely attached to a published post of the queried post type, then handing
 * those IDs back to get_terms(). Two queries instead of one, but the term objects still come
 * from WordPress's own cache and every `term_name`-style filter (WPML, Polylang) still runs —
 * which a single hand-rolled SELECT of term names would bypass.
 */
final class TaxonomyOptions
{
    /**
     * Per-request cache of term options, keyed by taxonomy, post type and scope.
     *
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * Returns term_id => term name options for the given taxonomy.
     *
     * @param  string $taxonomy           Registered taxonomy slug. An empty or unknown slug
     *                                    yields an empty option list.
     * @param  string $post_type          The post type being queried; passed to the filter so
     *                                    integrators can narrow the list.
     * @param  bool   $limit_to_post_type Restrict the list to terms attached to at least one
     *                                    published post of `$post_type`. Ignored when no post
     *                                    type is supplied.
     * @return array<int, string>
     */
    public static function get(string $taxonomy, string $post_type = '', bool $limit_to_post_type = false): array
    {
        if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $limit_to_post_type = $limit_to_post_type && '' !== $post_type;

        // Keyed by taxonomy, post type *and* scope: the lgs_taxonomy_options filter receives
        // all three and may narrow the list differently per instance.
        $cache_key = $taxonomy . '|' . $post_type . '|' . ($limit_to_post_type ? '1' : '0');

        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
            // Only the id and name are needed for a <select>.
            'fields'     => 'id=>name',
        ];

        if ($limit_to_post_type) {
            $term_ids = self::term_ids_in_post_type($taxonomy, $post_type);

            if ([] === $term_ids) {
                // No published post of this post type carries a term from this taxonomy, so
                // the dropdown has nothing to offer. Cached as empty rather than falling
                // through to the unscoped list, which is the whole point of the option.
                self::$cache[$cache_key] = [];

                return [];
            }

            $args['include'] = $term_ids;

            // The ID list is already the definitive answer to "which terms have posts here";
            // leaving hide_empty on would additionally drop a term whose global count is
            // stale, even though a published post of this post type demonstrably uses it.
            $args['hide_empty'] = false;
        }

        $terms   = get_terms($args);
        $options = [];

        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $term_id => $name) {
                $options[(int) $term_id] = (string) $name;
            }
        }

        /**
         * Filters the taxonomy term dropdown options.
         *
         * @param array<int, string> $options            Map of term ID to term name.
         * @param string             $taxonomy           Taxonomy slug.
         * @param string             $post_type          Post type the search instance queries.
         * @param bool               $limit_to_post_type Whether the list was scoped to the post type.
         */
        $options = (array) apply_filters(
            'lgs_taxonomy_options',
            $options,
            $taxonomy,
            $post_type,
            $limit_to_post_type
        );

        self::$cache[$cache_key] = $options;

        return $options;
    }

    /**
     * Returns the term IDs of a taxonomy that at least one published post of a post type has.
     *
     * A DISTINCT query over the relationship tables, joined to wp_posts only to constrain the
     * post type and status. No post rows are returned and no post objects are hydrated — the
     * relationship join is covered by core's indexes on `term_taxonomy_id` and the posts
     * primary key.
     *
     * Only direct assignments count. A parent category whose *children* have the posts is
     * excluded, which matches how `hide_empty` already behaves for hierarchical taxonomies.
     *
     * @param  string $taxonomy  Validated taxonomy slug (bound as a parameter regardless).
     * @param  string $post_type Validated post type slug (likewise).
     * @return list<int>
     */
    private static function term_ids_in_post_type(string $taxonomy, string $post_type): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tt.term_id
                 FROM {$wpdb->term_taxonomy} tt
                 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE tt.taxonomy = %s
                   AND p.post_type = %s
                   AND p.post_status = 'publish'",
                $taxonomy,
                $post_type
            )
        );

        $term_ids = [];

        foreach ((array) $ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $term_ids[] = $id;
            }
        }

        return $term_ids;
    }
}
