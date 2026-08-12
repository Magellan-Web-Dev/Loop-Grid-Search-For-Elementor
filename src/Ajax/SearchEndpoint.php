<?php

declare(strict_types=1);

namespace LoopGridSearch\Ajax;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Query\QueryBuilder;
use LoopGridSearch\Render\PaginationRenderer;
use LoopGridSearch\Render\ResultsRenderer;
use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\Criteria;
use LoopGridSearch\Support\PageLinks;
use LoopGridSearch\Support\UrlState;

/**
 * The single AJAX endpoint that powers every search, filter and page change.
 *
 * ## Why admin-ajax rather than a REST route
 *
 * Both are viable; admin-ajax is the better fit here:
 *
 *  • The response is HTML, not a resource representation. There is no entity to model, no
 *    collection to expose, and no schema worth publishing — so REST buys nothing.
 *  • `wp_send_json_success()` / `wp_send_json_error()` already produce the envelope the
 *    payload needs, with correct headers and status codes.
 *  • admin-ajax.php is `nocache_headers()`-guarded and is treated as uncacheable by every
 *    common page-cache and CDN configuration. A public GET REST route is exactly the kind
 *    of URL a CDN *will* cache, which would silently serve one visitor's filtered results
 *    to another.
 *  • Nonce handling is one call, with no permission_callback ambiguity for what is
 *    deliberately a public, read-only endpoint.
 *
 * ## Security model
 *
 * The endpoint is public (both `wp_ajax_` and `wp_ajax_nopriv_` are registered) because it
 * only ever exposes published posts in a public post type — the same data the site's own
 * archive pages show. Every request is nonetheless fully validated:
 *
 *  1. **Nonce.** `wp_verify_nonce()` against the `lgs_search` action.
 *  2. **Configuration integrity.** The query scope (post type, meta key, taxonomy, page
 *     size, template) is *not* taken on trust from the request. It arrives HMAC-signed by
 *     the server and {@see Config::from_client_array()} rejects anything whose signature
 *     does not verify, then re-validates every value against the live WordPress registry.
 *     An attacker cannot point this endpoint at a private post type, an arbitrary meta key,
 *     or an unrelated Elementor template.
 *  3. **Visitor input.** Keyword, month/year, term and page are parsed and range-checked by
 *     {@see Criteria}: the keyword is length-capped and bound via `$wpdb->prepare()`, the
 *     month/year must match `YYYY-MM` exactly, the term must genuinely exist in the
 *     configured taxonomy, and the page number is `absint`-floored at 1.
 *  4. **Output.** All rendered values pass through the escaping helpers in the renderers.
 *
 * No SQL fragment, meta key, orderby value or post type from the request ever reaches a
 * query without passing through one of those gates.
 */
final class SearchEndpoint
{
    /** @var string admin-ajax action name and nonce action. */
    public const ACTION = 'lgs_search';

    /**
     * Registers the endpoint for logged-in and anonymous visitors alike.
     */
    public function __construct()
    {
        add_action('wp_ajax_' . self::ACTION,        [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Handles one search request and sends the JSON response.
     *
     * Response shape (the standard WordPress AJAX envelope):
     *
     *   {
     *     "success": true,
     *     "data": {
     *       "html":            "…rendered results…",
     *       "pagination_html": "…rendered pagination…",
     *       "current_page":    1,
     *       "total_pages":     4,
     *       "total_results":   31
     *     }
     *   }
     *
     * @return void Never returns; wp_send_json_* terminates the request.
     */
    public function handle(): void
    {
        // ── 1. Nonce ────────────────────────────────────────────────────────────────
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::ACTION)) {
            wp_send_json_error(
                [
                    'code'    => 'lgs_invalid_nonce',
                    'message' => __('Your session has expired. Please reload the page and try again.', 'loop-grid-search'),
                ],
                403
            );
        }

        // ── 2. Configuration integrity ──────────────────────────────────────────────
        $raw_config = isset($_POST['config']) && is_array($_POST['config'])
            // Unslash before verifying: the signature was produced over unslashed values.
            ? wp_unslash($_POST['config'])
            : [];

        $config = Config::from_client_array($raw_config);

        if (!$config instanceof Config) {
            wp_send_json_error(
                [
                    'code'    => 'lgs_invalid_config',
                    'message' => __('This search could not be verified. Please reload the page and try again.', 'loop-grid-search'),
                ],
                400
            );
        }

        // ── 3. Visitor input ────────────────────────────────────────────────────────
        $criteria = Criteria::from_request(wp_unslash($_POST), $config);

        // ── 4. Query and render ─────────────────────────────────────────────────────
        $result   = (new QueryBuilder())->run($config, $criteria);
        $query    = $result['query'];
        $criteria = $result['criteria'];

        $total_pages   = (int) $query->max_num_pages;
        $total_results = (int) $query->found_posts;

        // The replacement pagination markup needs the same crawlable hrefs the server
        // render produced, but this request arrives at admin-ajax.php and knows nothing
        // about the page the instance sits on. The script therefore sends its own
        // location, which base_from_client() accepts only if it points at this site.
        $links = null;

        if ($config->seo_pagination()) {
            $page_url = isset($_POST['page_url']) && is_scalar($_POST['page_url'])
                ? (string) wp_unslash($_POST['page_url'])
                : '';

            $instance = isset($_POST['instance']) && is_scalar($_POST['instance'])
                ? sanitize_html_class(wp_unslash((string) $_POST['instance']))
                : '';

            $links = new PageLinks(UrlState::base_from_client($page_url), $criteria, $config, $instance);
        }

        $payload = [
            'html'            => (new ResultsRenderer())->render($query, $config),
            'pagination_html' => (new PaginationRenderer())->render($criteria->page(), $total_pages, $config, $links),
            'current_page'    => $criteria->page(),
            'total_pages'     => $total_pages,
            'total_results'   => $total_results,
        ];

        /**
         * Filters the AJAX response payload.
         *
         * @param array<string, mixed> $payload
         * @param \WP_Query             $query
         * @param Config                $config
         * @param Criteria              $criteria
         */
        $payload = (array) apply_filters('lgs_ajax_response', $payload, $query, $config, $criteria);

        wp_send_json_success($payload);
    }
}
