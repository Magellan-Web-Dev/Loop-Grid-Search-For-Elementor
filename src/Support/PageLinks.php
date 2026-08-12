<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Builds the crawlable `href` for any page of one instance's result set.
 *
 * Constructed once per render from the base URL of the page the instance sits on plus the
 * filters currently applied, then asked for one URL per pagination control. The filter
 * state is baked in at construction because every link in a single pagination block shares
 * it — only the page number differs.
 *
 * Example, for an instance on /news/ filtered to a keyword:
 *
 *   for_page(1) → https://example.com/news/?lgs_q=solar#lgs-1
 *   for_page(3) → https://example.com/news/?lgs_q=solar&lgs_page=3#lgs-1
 *
 * The fragment is the instance's DOM id. It never reaches a crawler's index (fragments are
 * stripped before a URL is queued) and is ignored by the AJAX path, but without JavaScript
 * it is what puts the visitor at the grid instead of at the top of the document.
 */
final class PageLinks
{
    /**
     * Filter arguments common to every link, i.e. everything except the page number.
     *
     * @var array<string, string>
     */
    private readonly array $filters;

    /**
     * @param string   $base_url Absolute URL of the page, already stripped of this
     *                           plugin's own query parameters.
     * @param Criteria $criteria Filters currently applied to this instance.
     * @param Config   $config   Instance configuration, so a sort that only restates the
     *                           configured default is left out of the URL.
     * @param string   $fragment DOM id to append as a fragment, or '' for none.
     */
    public function __construct(
        private readonly string $base_url,
        Criteria $criteria,
        Config $config,
        private readonly string $fragment = '',
    ) {
        $this->filters = UrlState::to_query_args($criteria, $config, false);
    }

    /**
     * Returns the URL for one page of results.
     *
     * @param  int $page 1-based page number.
     * @return string Raw (unescaped) URL; callers escape at the point of output.
     */
    public function for_page(int $page): string
    {
        $args = $this->filters;
        $page = max(1, $page);

        // Page 1 is the bare URL: one page of results must not be reachable at two
        // different addresses.
        if ($page > 1) {
            $args[UrlState::PARAM_PAGE] = (string) $page;
        }

        $url = [] === $args ? $this->base_url : (string) add_query_arg($args, $this->base_url);

        return '' !== $this->fragment ? $url . '#' . $this->fragment : $url;
    }
}
