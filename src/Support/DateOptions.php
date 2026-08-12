<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Builds the Month / Year dropdown options for a post type.
 *
 * ## Why raw SQL instead of WP_Query
 *
 * The only data needed is the distinct set of (year, month) pairs that actually have
 * published posts. Fetching that through WP_Query would mean loading every post object
 * and its meta/term caches just to read one column — exactly the "query all posts to
 * render one page" pattern this plugin is meant to avoid.
 *
 * This is the same approach WordPress core takes in wp_get_archives(): a single
 * DISTINCT query over wp_posts. It is covered by core's `type_status_date` composite
 * index (post_type, post_status, post_date, ID), so MySQL can satisfy it from the index
 * without touching the row data.
 *
 * Results are memoised per post type for the lifetime of the request, so rendering the
 * filter bar and re-rendering after an AJAX call never runs the query twice.
 */
final class DateOptions
{
    /**
     * Per-request cache of month options, keyed by post type.
     *
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * Returns "YYYY-MM" => "Month YYYY" options, newest month first.
     *
     * @param  string $post_type Registered post type slug.
     * @return array<string, string>
     */
    public static function get(string $post_type): array
    {
        if (isset(self::$cache[$post_type])) {
            return self::$cache[$post_type];
        }

        global $wpdb;

        // $post_type is a validated slug from Config, and is bound as a parameter anyway.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // No zero-date comparison in SQL: on servers running with NO_ZERO_DATE in
                // sql_mode, comparing against '0000-00-00 00:00:00' raises a warning or an
                // error. The YEAR/MONTH range check below discards those rows instead.
                "SELECT DISTINCT YEAR(post_date) AS lgs_year, MONTH(post_date) AS lgs_month
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status = 'publish'
                 ORDER BY lgs_year DESC, lgs_month DESC",
                $post_type
            )
        );

        $options = [];

        foreach ((array) $rows as $row) {
            $year  = (int) $row->lgs_year;
            $month = (int) $row->lgs_month;

            if ($year <= 0 || $month < 1 || $month > 12) {
                continue;
            }

            $options[sprintf('%04d-%02d', $year, $month)] = sprintf(
                /* translators: 1: month name, 2: four-digit year */
                _x('%1$s %2$d', 'month year dropdown option', 'loop-grid-search'),
                self::month_name($month),
                $year
            );
        }

        /**
         * Filters the Month / Year dropdown options.
         *
         * @param array<string, string> $options   Map of "YYYY-MM" to display label.
         * @param string                $post_type The post type the options were built for.
         */
        $options = (array) apply_filters('lgs_date_options', $options, $post_type);

        self::$cache[$post_type] = $options;

        return $options;
    }

    /**
     * Returns the localised full month name for a month number.
     *
     * Uses WP_Locale rather than date formatting so no timezone conversion can shift
     * the month, and the name is translated through WordPress's own locale data.
     *
     * @param  int $month Month number 1–12.
     * @return string
     */
    private static function month_name(int $month): string
    {
        global $wp_locale;

        if ($wp_locale instanceof \WP_Locale) {
            return $wp_locale->get_month($month);
        }

        // Extremely defensive fallback for early-boot or unit-test contexts.
        return gmdate('F', gmmktime(0, 0, 0, $month, 1, 2000));
    }
}
