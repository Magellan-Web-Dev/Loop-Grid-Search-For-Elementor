<?php

declare(strict_types=1);

namespace LoopGridSearch\Render;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Frontend\AssetManager;
use LoopGridSearch\Query\QueryBuilder;
use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\Criteria;
use LoopGridSearch\Support\PageLinks;
use LoopGridSearch\Support\UrlState;

/**
 * Composes the complete search interface for one instance.
 *
 * Shared by the shortcode and the Elementor widget so both paths produce byte-identical
 * markup and behaviour — there is exactly one implementation of the interface.
 *
 * Output shape:
 *
 *   <div class="ajax-post-search" id="lgs-1" data-lgs-instance data-lgs-current-page="1">
 *     <script type="application/json" class="ajax-post-search__config">…signed config…</script>
 *     <form class="ajax-post-search__filters" role="search">…</form>
 *     <p class="ajax-post-search__status" role="status" aria-live="polite"></p>
 *     <div class="ajax-post-search__results" tabindex="-1" aria-busy="false">…results…</div>
 *     <nav class="ajax-post-search__pagination">…</nav>
 *   </div>
 *
 * Notes:
 *  • The requested page is rendered server-side, so the interface is useful before (and
 *    without) any JavaScript, search engines see real content, and Elementor's conditional
 *    asset loader gets to see every widget the loop template uses during the normal page
 *    lifecycle. Which page that is comes from the query string ({@see UrlState}): a visit
 *    to `?lgs_page=3` renders page 3 outright, which is what makes the pagination links
 *    crawlable rather than decorative.
 *  • The per-instance configuration travels in a JSON `<script>` block rather than in a
 *    wall of data-* attributes or a per-instance inline `<script>`. One shared JS file
 *    serves any number of instances on a page; each reads its own block.
 *  • Layout knobs (column count, gap) are emitted as CSS custom properties inside a single
 *    scoped `<style>` element rather than as inline `style` attributes.
 */
final class InterfaceRenderer
{
    /**
     * Monotonic counter used to build unique DOM ids when no explicit id is supplied.
     *
     * @var int
     */
    private static int $instance_counter = 0;

    /**
     * Renders the whole interface, including the first page of results.
     *
     * @param  Config      $config      Validated instance configuration.
     * @param  string|null $instance_id Explicit DOM id (the Elementor widget passes its own
     *                                  widget id so the id is stable across re-renders).
     * @return string
     */
    public function render(Config $config, ?string $instance_id = null): string
    {
        AssetManager::enqueue();

        $instance_id = $this->resolve_instance_id($instance_id);

        // What the visitor asked for. With SEO pagination on, that comes from the query
        // string, so a shared or reloaded URL reproduces exactly what was on screen; with
        // it off, the initial render is always the unfiltered, newest-first first page.
        $criteria = $config->seo_pagination()
            // No nonce: this is a public, read-only render of published posts, and every
            // value is sanitised and range-checked by Criteria before it reaches a query.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? UrlState::criteria_from_query(wp_unslash($_GET), $config)
            : Criteria::initial();

        $result   = (new QueryBuilder())->run($config, $criteria);
        $query    = $result['query'];
        $criteria = $result['criteria'];

        $links = $config->seo_pagination()
            ? new PageLinks(UrlState::current_url($config), $criteria, $config, $instance_id)
            : null;

        $results_html    = (new ResultsRenderer())->render($query, $config);
        $pagination_html = (new PaginationRenderer())->render(
            $criteria->page(),
            (int) $query->max_num_pages,
            $config,
            $links
        );
        $filters_html = (new FiltersRenderer())->render($config, $criteria, $instance_id);

        // data-lgs-current-page seeds the script's page state, so a server render of page 3
        // does not leave the script believing it is showing page 1. Deliberately not named
        // data-lgs-page: that attribute marks an individual pagination control, and the
        // script's delegated click handler finds those with closest().
        $html = '<div class="ajax-post-search" id="' . esc_attr($instance_id) . '"'
            . ' data-lgs-instance="1" data-lgs-current-page="' . (int) $criteria->page() . '">';

        $html .= $this->render_instance_style($config, $instance_id);
        $html .= $this->render_config_script($config);
        $html .= $filters_html;

        // Polite live region for "Searching…" / "N results found" announcements.
        $html .= '<p class="ajax-post-search__status screen-reader-text" role="status" aria-live="polite"></p>';

        // tabindex="-1" lets the script move focus here after a page change so keyboard
        // users are not dropped back at the top of the document.
        $html .= '<div class="ajax-post-search__results" tabindex="-1" aria-busy="false">'
            . $results_html
            . '</div>';

        $html .= '<div class="ajax-post-search__pagination-wrap">' . $pagination_html . '</div>';

        $html .= '</div>';

        /**
         * Filters the complete rendered interface.
         *
         * @param string $html
         * @param Config $config
         * @param string $instance_id
         */
        return (string) apply_filters('lgs_interface_html', $html, $config, $instance_id);
    }

    /**
     * Emits the signed configuration payload the JavaScript reads for this instance.
     *
     * A `<script type="application/json">` block is inert — the browser never executes it —
     * and JSON-encoding with the HEX flags escapes `<`, `>`, `&`, `'` and `"` so the payload
     * cannot break out of the element or inject markup.
     *
     * @param  Config $config
     * @return string
     */
    private function render_config_script(Config $config): string
    {
        $json = wp_json_encode(
            $config->to_client_array(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return '<script type="application/json" class="ajax-post-search__config">'
            . ($json ?: '{}')
            . '</script>';
    }

    /**
     * Emits a scoped stylesheet carrying this instance's layout custom properties.
     *
     * Two deliberate choices here:
     *
     *  • CSS custom properties in a scoped `<style>` element rather than an inline `style`
     *    attribute, so the values stay in the cascade and are trivially overridable.
     *
     *  • The id selector is wrapped in `:where()`, which forces its specificity to zero.
     *    These are *defaults*: a single theme class, or the CSS the Elementor widget's own
     *    responsive Columns / Gap controls generate, wins without needing `!important` or a
     *    more specific selector.
     *
     * @param  Config $config
     * @param  string $instance_id
     * @return string
     */
    private function render_instance_style(Config $config, string $instance_id): string
    {
        return '<style>:where(#' . esc_attr($instance_id) . '){'
            . '--lgs-columns:' . (int) $config->columns() . ';'
            . '--lgs-gap:' . (int) $config->gap() . 'px;'
            . '}</style>';
    }

    /**
     * Returns a sanitised, unique DOM id for this instance.
     *
     * @param  string|null $instance_id
     * @return string
     */
    private function resolve_instance_id(?string $instance_id): string
    {
        if (null !== $instance_id && '' !== $instance_id) {
            return 'lgs-' . sanitize_html_class($instance_id);
        }

        self::$instance_counter++;

        return 'lgs-' . self::$instance_counter;
    }
}
