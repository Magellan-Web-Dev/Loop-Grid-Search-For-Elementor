<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Translates between the visitor's filter/page state and the page's query string.
 *
 * ## Why the state lives in the URL
 *
 * Pagination controls used to be `<button>` elements whose page number existed only in a
 * `data-` attribute. That is fine for a human with JavaScript and invisible to everything
 * else: a crawler sees one page of results and no route to the rest, and a visitor who
 * reloads or shares the URL lands back on page 1.
 *
 * Putting the state in the query string fixes all three at once. `?lgs_page=2` is a real
 * URL that the server renders on its own, so:
 *
 *  • crawlers follow ordinary `<a href>` links and index every page of the result set;
 *  • a reload, a bookmark or a shared link reproduces exactly what was on screen;
 *  • the browser Back button works, because each page change is a history entry.
 *
 * The JavaScript still calls `preventDefault()` on the click and swaps the results over
 * AJAX — the href is what the *document* means, not what the *click* does.
 *
 * ## Parameter names
 *
 * All five are prefixed so they can never collide with WordPress's own query vars
 * (`paged`, `s`, `orderby`…) or with another plugin's. In particular `lgs_page` is
 * deliberately **not** `paged`: `paged` is a registered public query var and would make
 * WordPress treat the request as a paged archive, producing 404s and canonical redirects
 * on a singular page.
 *
 *   lgs_page   1-based page number (omitted entirely on page 1)
 *   lgs_q      keyword
 *   lgs_date   YYYY-MM month selection
 *   lgs_term   taxonomy term ID
 *   lgs_sort   sort preset key
 */
final class UrlState
{
    /** @var string Query parameter carrying the 1-based page number. */
    public const PARAM_PAGE = 'lgs_page';

    /** @var string Query parameter carrying the keyword. */
    public const PARAM_KEYWORD = 'lgs_q';

    /** @var string Query parameter carrying the YYYY-MM month selection. */
    public const PARAM_DATE = 'lgs_date';

    /** @var string Query parameter carrying the taxonomy term ID. */
    public const PARAM_TERM = 'lgs_term';

    /** @var string Query parameter carrying the sort preset key. */
    public const PARAM_SORT = 'lgs_sort';

    /**
     * Maps each query parameter to the request key {@see Criteria::from_request()} expects.
     *
     * Keeping the mapping here means Criteria stays a pure model of "what the visitor
     * asked for" and knows nothing about URLs, while every sanitiser it already applies
     * (keyword length cap, YYYY-MM format check, term existence check, page floor) is
     * reused verbatim for query-string input.
     *
     * @var array<string, string>
     */
    private const PARAM_MAP = [
        self::PARAM_KEYWORD => 'keyword',
        self::PARAM_DATE    => 'date',
        self::PARAM_TERM    => 'term',
        self::PARAM_SORT    => 'sort',
        self::PARAM_PAGE    => 'paged',
    ];

    /**
     * Returns every query parameter this plugin owns.
     *
     * Used to strip stale state out of a base URL before fresh state is appended.
     *
     * @return list<string>
     */
    public static function params(): array
    {
        return array_keys(self::PARAM_MAP);
    }

    /**
     * Returns the parameter names keyed by their short role name, for the browser.
     *
     * Emitted in the script settings object so the JavaScript builds history URLs from
     * the same names PHP reads, with no second copy of the list to drift.
     *
     * @return array<string, string>
     */
    public static function param_map(): array
    {
        return [
            'page'    => self::PARAM_PAGE,
            'keyword' => self::PARAM_KEYWORD,
            'date'    => self::PARAM_DATE,
            'term'    => self::PARAM_TERM,
            'sort'    => self::PARAM_SORT,
        ];
    }

    /**
     * Builds Criteria from a query string.
     *
     * @param  array<string, mixed> $query  Unslashed query data (typically $_GET).
     * @param  Config               $config Instance configuration, for term validation.
     * @return Criteria
     */
    public static function criteria_from_query(array $query, Config $config): Criteria
    {
        $request = [];

        foreach (self::PARAM_MAP as $param => $request_key) {
            if (isset($query[$param])) {
                $request[$request_key] = $query[$param];
            }
        }

        return Criteria::from_request($request, $config);
    }

    /**
     * Converts Criteria into the query arguments that describe it.
     *
     * Only values that differ from what the instance would do on its own are emitted, so
     * one view of the results always has exactly one address:
     *
     *  • Page 1 writes no page parameter — the bare URL and `?lgs_page=1` would otherwise
     *    be two crawlable URLs for one page of results.
     *  • A sort that merely restates the instance's configured default is dropped. The
     *    sort dropdown preselects that default, so without this the first interaction on
     *    an instance with sorting enabled would stamp `lgs_sort=newest` onto every URL
     *    the visitor shares, for a choice they never made.
     *
     * @param  Criteria $criteria
     * @param  Config   $config       Supplies the instance's default sort order.
     * @param  bool     $include_page Whether to include the page number.
     * @return array<string, string>
     */
    public static function to_query_args(Criteria $criteria, Config $config, bool $include_page = true): array
    {
        $args = [];

        if ($criteria->has_keyword()) {
            $args[self::PARAM_KEYWORD] = $criteria->keyword();
        }

        if ($criteria->has_date()) {
            $args[self::PARAM_DATE] = sprintf('%04d-%02d', $criteria->year(), $criteria->month());
        }

        if ($criteria->has_term()) {
            $args[self::PARAM_TERM] = (string) $criteria->term_id();
        }

        $default_sort = Criteria::preset_for($config->orderby(), $config->order());

        if ('' !== $criteria->sort() && $criteria->sort() !== $default_sort) {
            $args[self::PARAM_SORT] = $criteria->sort();
        }

        if ($include_page && $criteria->page() > 1) {
            $args[self::PARAM_PAGE] = (string) $criteria->page();
        }

        return $args;
    }

    /**
     * Returns the URL of the current request with this plugin's parameters removed.
     *
     * The host and scheme come from `home_url()` rather than from `$_SERVER['HTTP_HOST']`,
     * which is attacker-controlled behind a permissive web server config; only the path and
     * query are taken from the request, and both are passed through `esc_url_raw()`.
     *
     * @return string
     */
    public static function current_url(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';

        if ('' === $request_uri) {
            return home_url('/');
        }

        // REQUEST_URI is already root-relative and includes any subdirectory install path,
        // so it is appended to the bare origin rather than to home_url()'s own path.
        $url = self::origin() . '/' . ltrim($request_uri, '/');

        return (string) remove_query_arg(self::params(), $url);
    }

    /**
     * Returns a safe base URL from a page URL the browser supplied.
     *
     * The AJAX endpoint has no idea which page a request came from, so the script sends
     * `location.href` along with it. That value is untrusted: it is accepted only when it
     * points at this site, and anything else falls back to the home URL. The result is
     * only ever used to build `href` attributes, but an unchecked value would let a
     * crafted request produce off-site links inside this site's own markup.
     *
     * @param  string $url Raw value from the request.
     * @return string
     */
    public static function base_from_client(string $url): string
    {
        $url = esc_url_raw(trim($url));

        if ('' === $url) {
            return home_url('/');
        }

        $parts = wp_parse_url($url);
        $home  = wp_parse_url(home_url());

        if (!is_array($parts) || !is_array($home) || empty($parts['host'])) {
            return home_url('/');
        }

        if (strtolower((string) $parts['host']) !== strtolower((string) ($home['host'] ?? ''))) {
            return home_url('/');
        }

        // Rebuilt from the parsed components, which drops any fragment and any userinfo.
        $rebuilt = self::origin()
            . ('' !== ($parts['path'] ?? '') ? '/' . ltrim((string) $parts['path'], '/') : '/')
            . (isset($parts['query']) && '' !== $parts['query'] ? '?' . $parts['query'] : '');

        return (string) remove_query_arg(self::params(), $rebuilt);
    }

    /**
     * Returns the site's scheme + host + port, with no path.
     *
     * @return string
     */
    private static function origin(): string
    {
        $home = wp_parse_url(home_url());

        if (!is_array($home) || empty($home['host'])) {
            return untrailingslashit(home_url());
        }

        $scheme = $home['scheme'] ?? (is_ssl() ? 'https' : 'http');
        $port   = isset($home['port']) ? ':' . (int) $home['port'] : '';

        return $scheme . '://' . $home['host'] . $port;
    }
}
