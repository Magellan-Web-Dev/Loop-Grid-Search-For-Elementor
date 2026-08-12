<?php

declare(strict_types=1);

namespace LoopGridSearch\Query;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\Criteria;

/**
 * Translates a {@see Config} + {@see Criteria} pair into WP_Query arguments and runs them.
 *
 * All three filters combine with AND, exactly as the brief describes:
 *
 *     ( post title LIKE keyword OR meta field LIKE keyword )   ← KeywordSearch (posts_clauses)
 *     AND published in the selected month/year                 ← date_query
 *     AND has the selected term                                ← tax_query
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

        if ($criteria->has_term() && '' !== $config->taxonomy()) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $config->taxonomy(),
                    'field'    => 'term_id',
                    'terms'    => [$criteria->term_id()],
                ],
            ];
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
