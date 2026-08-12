<?php

declare(strict_types=1);

namespace LoopGridSearch\Render;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;
use LoopGridSearch\Support\PageLinks;

/**
 * Renders the pagination control.
 *
 * ## Links, not buttons
 *
 * Each page is a real `<a href="…?lgs_page=3">`. That single change is what makes the
 * result set crawlable and shareable: a search engine follows the links and indexes every
 * page, and a reload or a pasted URL renders the same page server-side. The JavaScript
 * calls `preventDefault()` on the click and swaps the results over AJAX, so a visitor with
 * JavaScript still never sees a page reload — the href describes the destination, it does
 * not dictate how the browser gets there.
 *
 * Modifier-clicks are deliberately left alone by the script, so Cmd/Ctrl-click and
 * middle-click open a page of results in a new tab exactly as they would anywhere else.
 * The page number is still carried in `data-lgs-page` so the script never has to parse a
 * URL to find it.
 *
 * Controls that lead nowhere are `<span>`, not links:
 *
 *  • Previous on page 1 and Next on the last page — there is no target, and a disabled
 *    link is not a thing HTML can express.
 *  • The current page — self-links are noise for crawlers and for screen readers alike.
 *
 * When {@see Config::seo_pagination()} is off, the original `<button>` markup is rendered
 * instead and no URLs are produced. That is the escape hatch for a page carrying more than
 * one instance, where a single shared set of query parameters cannot describe both.
 *
 * ## Two presentation modes
 *
 *  • `numbers`   — Previous / Next with numbered page links between them.
 *  • `prev_next` — Previous / Next with a plain "Page 2 of 5" counter.
 *
 * ## Truncation
 *
 * In `numbers` mode at most {@see Config::pagination_max_numbers()} links are on screen
 * at once. The visible window is centred on the current page and clamped at both ends, with
 * an ellipsis marking each truncated side. With 9 pages and a limit of 6:
 *
 *     page 1 →  1 2 3 4 5 6 …
 *     page 4 →  … 2 3 4 5 6 7 …
 *     page 9 →  … 4 5 6 7 8 9
 *
 * Centring (rather than a window that only slides when the current page reaches an edge)
 * keeps roughly equal context on either side of where the visitor is, and guarantees the
 * link count never grows with the result set.
 */
final class PaginationRenderer
{
    /**
     * Renders the pagination nav, or an empty string when there is only one page.
     *
     * @param  int             $current_page 1-based page currently displayed.
     * @param  int             $total_pages  Total number of pages in the result set.
     * @param  Config          $config       Instance configuration.
     * @param  PageLinks|null  $links        URL builder; null falls back to button markup.
     * @return string
     */
    public function render(int $current_page, int $total_pages, Config $config, ?PageLinks $links = null): string
    {
        if ($total_pages <= 1) {
            return '';
        }

        if (!$config->seo_pagination()) {
            $links = null;
        }

        $current_page = max(1, min($current_page, $total_pages));

        $html = '<nav class="ajax-post-search__pagination" aria-label="'
            . esc_attr__('Results pagination', 'loop-grid-search') . '">';

        $html .= $this->render_step(
            'prev',
            $current_page - 1,
            $config->pagination_prev_label(),
            $current_page <= 1,
            $links
        );

        if ($config->shows_page_numbers()) {
            $html .= '<span class="ajax-post-search__pages">';

            foreach ($this->page_sequence($current_page, $total_pages, $config->pagination_max_numbers()) as $entry) {
                $html .= null === $entry
                    ? '<span class="ajax-post-search__ellipsis" aria-hidden="true">&hellip;</span>'
                    : $this->render_number($entry, $entry === $current_page, $links);
            }

            $html .= '</span>';
        } else {
            $html .= '<span class="ajax-post-search__page-count">'
                . esc_html(sprintf(
                    /* translators: 1: current page number, 2: total number of pages */
                    __('Page %1$d of %2$d', 'loop-grid-search'),
                    $current_page,
                    $total_pages
                ))
                . '</span>';
        }

        $html .= $this->render_step(
            'next',
            $current_page + 1,
            $config->pagination_next_label(),
            $current_page >= $total_pages,
            $links
        );

        $html .= '</nav>';

        /**
         * Filters the rendered pagination markup.
         *
         * @param string        $html
         * @param int           $current_page
         * @param int           $total_pages
         * @param Config        $config
         * @param PageLinks|null $links
         */
        return (string) apply_filters('lgs_pagination_html', $html, $current_page, $total_pages, $config, $links);
    }

    /**
     * Renders the Previous or Next control.
     *
     * `rel="prev"` / `rel="next"` are cheap sequencing hints. Google no longer uses them
     * for indexing, but Bing and several other crawlers still do, and browsers use them for
     * link prefetching.
     *
     * @param  string        $direction 'prev' or 'next'.
     * @param  int           $page      Target page number.
     * @param  string        $label     Visible label.
     * @param  bool          $disabled  Whether the step is unavailable.
     * @param  PageLinks|null $links
     * @return string
     */
    private function render_step(string $direction, int $page, string $label, bool $disabled, ?PageLinks $links): string
    {
        $class = 'ajax-post-search__page ajax-post-search__page--' . $direction;
        $page  = max(1, $page);

        if ($disabled) {
            // No href to give and nothing to activate, so this is text, not a control.
            // aria-disabled is retained because it is what the stylesheet keys the dimmed
            // appearance off, and it keeps the announced state identical to the previous
            // disabled <button>.
            return sprintf(
                '<span class="%1$s is-disabled" aria-disabled="true">%2$s</span>',
                esc_attr($class),
                esc_html($label)
            );
        }

        if (null === $links) {
            return sprintf(
                '<button type="button" class="%1$s" data-lgs-page="%2$d">%3$s</button>',
                esc_attr($class),
                $page,
                esc_html($label)
            );
        }

        return sprintf(
            '<a class="%1$s" href="%2$s" rel="%3$s" data-lgs-page="%4$d">%5$s</a>',
            esc_attr($class),
            esc_url($links->for_page($page)),
            esc_attr($direction),
            $page,
            esc_html($label)
        );
    }

    /**
     * Renders a single numbered page control.
     *
     * @param  int            $page       The page this control targets.
     * @param  bool           $is_current Whether this is the page on screen.
     * @param  PageLinks|null $links
     * @return string
     */
    private function render_number(int $page, bool $is_current, ?PageLinks $links): string
    {
        $class  = 'ajax-post-search__page ajax-post-search__page--number';
        $prefix = '<span class="screen-reader-text">' . esc_html__('Page', 'loop-grid-search') . ' </span>';

        if ($is_current) {
            return sprintf(
                '<span class="%1$s is-current" aria-current="page" aria-disabled="true">%2$s%3$d</span>',
                esc_attr($class),
                $prefix,
                $page
            );
        }

        if (null === $links) {
            return sprintf(
                '<button type="button" class="%1$s" data-lgs-page="%2$d">%3$s%2$d</button>',
                esc_attr($class),
                $page,
                $prefix
            );
        }

        return sprintf(
            '<a class="%1$s" href="%2$s" data-lgs-page="%3$d">%4$s%3$d</a>',
            esc_attr($class),
            esc_url($links->for_page($page)),
            $page,
            $prefix
        );
    }

    /**
     * Builds the visible page sequence, using null to mark an ellipsis.
     *
     * The window holds exactly $max pages (or every page, when there are fewer), is centred
     * on the current page, and slides rather than growing. When $max is even the extra slot
     * falls after the current page, which reads better with a Next control on the right.
     *
     * Worked example — 9 pages, limit 6:
     *
     *   current 1 → start 1, end 6  → [1,2,3,4,5,6,null]
     *   current 4 → start 2, end 7  → [null,2,3,4,5,6,7,null]
     *   current 9 → start 4, end 9  → [null,4,5,6,7,8,9]
     *
     * @param  int $current Current page, already clamped to 1..$total by the caller.
     * @param  int $total   Total pages (always >= 2 here).
     * @param  int $max     Maximum numbered controls to show at once.
     * @return list<int|null>
     */
    private function page_sequence(int $current, int $total, int $max): array
    {
        // Everything fits: no window, no ellipses.
        if ($total <= $max) {
            return range(1, $total);
        }

        // Centre the window on the current page…
        $start = $current - intdiv($max - 1, 2);
        $end   = $start + $max - 1;

        // …then slide it back inside the real range. Only one of these can apply, because
        // $total > $max guarantees the window is narrower than the range.
        if ($start < 1) {
            $start = 1;
            $end   = $max;
        } elseif ($end > $total) {
            $end   = $total;
            $start = $total - $max + 1;
        }

        $sequence = [];

        if ($start > 1) {
            $sequence[] = null;
        }

        for ($page = $start; $page <= $end; $page++) {
            $sequence[] = $page;
        }

        if ($end < $total) {
            $sequence[] = null;
        }

        return $sequence;
    }
}
