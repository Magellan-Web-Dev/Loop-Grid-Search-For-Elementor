<?php

declare(strict_types=1);

namespace LoopGridSearch\Query;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\FieldRegistry;

/**
 * Adds "post title OR custom field" keyword matching to a single WP_Query.
 *
 * ## Why not WP_Query's own `s` parameter
 *
 * The obvious approach — `['s' => 'solar', 'meta_query' => [['key' => 'excerpt', 'compare' => 'LIKE', 'value' => 'solar']]]`
 * — produces the wrong result. WordPress builds the `s` clause and the meta clause as two
 * independent WHERE fragments and joins them with AND:
 *
 *     AND ( post_title LIKE '%solar%' OR post_excerpt LIKE … OR post_content LIKE … )
 *     AND ( postmeta.meta_key = 'excerpt' AND postmeta.meta_value LIKE '%solar%' )
 *
 * A post whose title contains "solar" but whose ACF field does not is therefore excluded.
 * There is no `relation` that spans the search clause and the meta clause, so the two can
 * never be OR-ed through the public API.
 *
 * ## What this class does instead
 *
 * `s` is never set. The keyword is carried on a private query var and a `posts_clauses`
 * filter appends one self-contained parenthesised group to the WHERE clause. Any number of
 * native columns and any number of meta keys can participate, all OR-ed together:
 *
 *     AND (
 *       ( wp_posts.post_title   LIKE '%solar%'
 *         OR wp_posts.post_excerpt LIKE '%solar%'
 *         OR EXISTS ( SELECT 1 FROM wp_postmeta
 *                     WHERE post_id = wp_posts.ID
 *                       AND meta_key IN ('excerpt','summary')
 *                       AND meta_value LIKE '%solar%' ) )
 *     )
 *
 * Design notes:
 *
 *  • **EXISTS, not JOIN.** A LEFT JOIN on wp_postmeta duplicates a post row for every
 *    matching meta row, which would require a GROUP BY to de-duplicate and would distort
 *    SQL_CALC_FOUND_ROWS. A correlated EXISTS subquery returns each post exactly once and
 *    resolves through wp_postmeta's `post_id` index, so it stays fast.
 *
 *  • **One EXISTS for all meta keys.** Several searchable fields become a single
 *    `meta_key IN (…)` subquery rather than one subquery per field, so adding fields costs
 *    almost nothing: the correlated lookup still visits only the current post's meta rows.
 *
 *  • **Multi-word keywords.** Each whitespace-separated term must match, and each term may
 *    match in either the title or the meta field — i.e. AND across terms, OR across fields.
 *    This mirrors how WordPress core's own search treats multiple terms, and reduces to the
 *    single-field OR behaviour above for a one-word search. The term count is capped in
 *    {@see \LoopGridSearch\Support\Criteria::keyword_terms()}.
 *
 *  • **Injection safety.** Every visitor-supplied value goes through
 *    $wpdb->esc_like() and is then bound with $wpdb->prepare(). Meta keys are bound as
 *    parameters too, so even a hypothetical hostile Config could not inject SQL. Column
 *    names cannot be bound as parameters, so they are re-checked against
 *    {@see \LoopGridSearch\Support\FieldRegistry::SEARCHABLE_COLUMNS} here — independently
 *    of Config — before being interpolated. Otherwise only the $wpdb->posts /
 *    $wpdb->postmeta table names are interpolated.
 *
 *  • **Filter lifetime.** {@see run()} adds the filter, executes exactly one WP_Query, and
 *    removes the filter in a finally block, so it is gone even if rendering throws. The
 *    callback additionally verifies that the query carries our private query var, so any
 *    unrelated query that happens to run inside that window is untouched.
 */
final class KeywordSearch
{
    /** @var string Private WP_Query var carrying the raw keyword. */
    public const QUERY_VAR_KEYWORD = 'lgs_keyword';

    /** @var string Private WP_Query var carrying the wp_posts columns to search. */
    public const QUERY_VAR_COLUMNS = 'lgs_keyword_columns';

    /** @var string Private WP_Query var carrying the meta keys to search. */
    public const QUERY_VAR_META_KEYS = 'lgs_keyword_meta_keys';

    /**
     * Runs a WP_Query with keyword matching applied, guaranteeing the filter is removed.
     *
     * @param  array<string, mixed> $args WP_Query arguments produced by {@see QueryBuilder}.
     * @return \WP_Query
     */
    public static function run(array $args): \WP_Query
    {
        $needs_filter = '' !== (string) ($args[self::QUERY_VAR_KEYWORD] ?? '');

        if (!$needs_filter) {
            return new \WP_Query($args);
        }

        add_filter('posts_clauses', [self::class, 'filter_clauses'], 10, 2);

        try {
            return new \WP_Query($args);
        } finally {
            // Removed immediately, and even on exception, so no other query on the page
            // can inherit this clause.
            remove_filter('posts_clauses', [self::class, 'filter_clauses'], 10);
        }
    }

    /**
     * Appends the title-OR-meta keyword group to the WHERE clause.
     *
     * Hooked to posts_clauses only for the duration of {@see run()}. Returns the clauses
     * untouched unless the query carries this plugin's private keyword query var.
     *
     * @param  array<string, string> $clauses The SQL clause fragments (where, join, groupby, …).
     * @param  \WP_Query             $query   The query being built.
     * @return array<string, string>
     */
    public static function filter_clauses(array $clauses, \WP_Query $query): array
    {
        $keyword = (string) $query->get(self::QUERY_VAR_KEYWORD, '');

        if ('' === $keyword) {
            return $clauses;
        }

        $where = self::build_where(
            $keyword,
            (array) $query->get(self::QUERY_VAR_COLUMNS, []),
            (array) $query->get(self::QUERY_VAR_META_KEYS, [])
        );

        if ('' === $where) {
            return $clauses;
        }

        $clauses['where'] = ($clauses['where'] ?? '') . ' AND (' . $where . ')';

        return $clauses;
    }

    /**
     * Builds the parenthesised keyword condition.
     *
     * @param  string       $keyword   Raw (already sanitised) keyword phrase.
     * @param  list<string> $columns   wp_posts columns to match; re-validated here.
     * @param  list<string> $meta_keys Meta keys to match, OR-ed with the columns.
     * @return string SQL fragment without the leading "AND (" / trailing ")".
     */
    private static function build_where(string $keyword, array $columns, array $meta_keys): string
    {
        global $wpdb;

        // Column names cannot be bound as query parameters, so they are interpolated —
        // which means they must be provably safe. Config already restricts them, but this
        // is the code that writes them into SQL, so it re-checks them itself rather than
        // trusting a caller.
        $columns = array_values(array_intersect(
            array_map('strval', $columns),
            FieldRegistry::SEARCHABLE_COLUMNS
        ));

        // Meta keys are bound as parameters, but are still filtered to the character class
        // WordPress allows so a malformed value cannot silently widen the search.
        $meta_keys = array_values(array_filter(
            array_map('strval', $meta_keys),
            static fn(string $key): bool => 1 === preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $key)
        ));

        if ([] === $columns && [] === $meta_keys) {
            return '';
        }

        $terms = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_slice(is_array($terms) ? $terms : [$keyword], 0, 10);

        if ([] === $terms) {
            return '';
        }

        $groups = [];

        foreach ($terms as $term) {
            $like       = '%' . $wpdb->esc_like($term) . '%';
            $conditions = [];

            foreach ($columns as $column) {
                $conditions[] = $wpdb->prepare("{$wpdb->posts}.{$column} LIKE %s", $like);
            }

            if ([] !== $meta_keys) {
                // One IN() subquery covers every searchable field for this term.
                $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

                $conditions[] = $wpdb->prepare(
                    "EXISTS ("
                    . "   SELECT 1 FROM {$wpdb->postmeta} AS lgs_meta"
                    . "   WHERE lgs_meta.post_id = {$wpdb->posts}.ID"
                    . "     AND lgs_meta.meta_key IN ({$placeholders})"
                    . "     AND lgs_meta.meta_value LIKE %s"
                    . " )",
                    array_merge($meta_keys, [$like])
                );
            }

            // OR across fields for this term…
            $groups[] = '( ' . implode(' OR ', $conditions) . ' )';
        }

        // …AND across terms.
        $where = implode(' AND ', $groups);

        /**
         * Filters the generated keyword WHERE fragment.
         *
         * Anything returned here is injected verbatim into the query's WHERE clause, so a
         * callback MUST escape and prepare its own values. Return the original $where to
         * opt out.
         *
         * @param string       $where     SQL fragment (already prepared).
         * @param list<string> $terms     Individual keyword terms.
         * @param list<string> $columns   wp_posts columns being searched.
         * @param list<string> $meta_keys Meta keys being searched.
         */
        return (string) apply_filters('lgs_keyword_where', $where, $terms, $columns, $meta_keys);
    }
}
