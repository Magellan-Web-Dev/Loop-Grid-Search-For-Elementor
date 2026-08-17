<?php

declare(strict_types=1);

namespace LoopGridSearch\Query;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\Criteria;

/**
 * Translates a {@see Config} + {@see Criteria} pair into WP_Query arguments and runs them.
 *
 * Every filter combines with AND, exactly as the brief describes:
 *
 *     ( post title LIKE keyword OR meta field LIKE keyword )   ← KeywordSearch (posts_clauses)
 *     AND published in the selected month/year                 ← date_query
 *     AND has the selected term                                ← tax_query
 *     AND has the selected term of the next taxonomy           ← tax_query (one clause each)
 *
 * This class produces no output and mutates no globals; it is pure argument assembly plus
 * the single WP_Query execution, which keeps it trivially testable.
 */
final class QueryBuilder
{
    /**
     * Builds the WP_Query arguments for a request.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @return array<string, mixed>
     */
    public function build_args(Config $config, Criteria $criteria): array
    {
        $order = $criteria->resolve_order($config);

        $args = [
            'post_type'      => $config->post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => $config->posts_per_page(),
            'paged'          => $criteria->page(),
            'orderby'        => $order['orderby'],
            'order'          => $order['order'],

            // Pagination requires the total row count, so found_rows must stay on.
            'no_found_rows'  => false,

            // Priming both caches with one query each is far cheaper than the per-post
            // lookups the card/loop template will otherwise trigger for thumbnails,
            // ACF values and taxonomy terms.
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,

            // A sticky post would otherwise be prepended to page 1 and break the
            // "newest first" contract and the per-page count.
            'ignore_sticky_posts' => true,

            // This is a secondary query; never let it hijack conditional tags.
            'suppress_filters' => false,
        ];

        if ($criteria->has_keyword()) {
            // Deliberately NOT 's' — see the KeywordSearch class docblock for why the
            // built-in search parameter cannot express "title OR meta".
            $args[KeywordSearch::QUERY_VAR_KEYWORD]   = $criteria->keyword();
            $args[KeywordSearch::QUERY_VAR_COLUMNS]   = $config->search_columns();
            $args[KeywordSearch::QUERY_VAR_META_KEYS] = $config->search_meta_keys();
        }

        if ($criteria->has_date()) {
            $args['date_query'] = [
                [
                    'year'  => $criteria->year(),
                    'month' => $criteria->month(),
                ],
            ];
        }

        // One clause per taxonomy dropdown that has a selection, AND-ed together: choosing a
        // category and a tag narrows to posts carrying both, which is what a visitor reading
        // two dropdowns side by side expects. Criteria has already verified every term
        // against its own taxonomy and dropped anything the instance does not filter on.
        $tax_query = [];

        foreach ($criteria->terms() as $taxonomy => $term_id) {
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => [$term_id],
            ];
        }

        if ([] !== $tax_query) {
            // Explicit, even though AND is WP_Query's default, because the difference matters
            // enough here to be visible to anyone reading a query log.
            $tax_query['relation'] = 'AND';

            $args['tax_query'] = $tax_query;
        }

        /**
         * Filters the WP_Query arguments before the search query runs.
         *
         * @param array<string, mixed> $args
         * @param Config               $config
         * @param Criteria             $criteria
         */
        return (array) apply_filters('lgs_query_args', $args, $config, $criteria);
    }

    /**
     * Runs the search query, clamping an out-of-range page request back into the result set.
     *
     * The clamp costs a second query, but only in the edge case where a stale page number
     * is requested (for example after a filter change raced a pagination click). The
     * returned Criteria reflects the page that was actually served, so the caller can
     * render pagination and the response payload consistently.
     *
     * @param  Config   $config
     * @param  Criteria $criteria
     * @return array{query: \WP_Query, criteria: Criteria}
     */
    public function run(Config $config, Criteria $criteria): array
    {
        $query = KeywordSearch::run($this->build_args($config, $criteria));

        $max_pages = (int) $query->max_num_pages;

        if ($max_pages > 0 && $criteria->page() > $max_pages) {
            $criteria = $criteria->with_page($max_pages);
            $query    = KeywordSearch::run($this->build_args($config, $criteria));
        }

        return ['query' => $query, 'criteria' => $criteria];
    }
}
