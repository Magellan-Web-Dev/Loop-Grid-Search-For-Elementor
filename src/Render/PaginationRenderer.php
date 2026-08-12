<?php

declare(strict_types=1);

namespace LoopGridSearch\Render;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;

/**
 * Renders the AJAX pagination control.
 *
 * Buttons rather than links: these controls never navigate, so a `<button>` is the correct
 * element. It is focusable and Enter/Space-activated for free, it cannot be middle-clicked
 * into a broken new tab, and `disabled` gives real (not simulated) inertness on page 1 and
 * the last page.
 *
 * The page number lives in `data-lgs-page`; the JavaScript reads it, merges it with the
 * instance's current keyword / date / term / sort state and issues one request. Filter
 * state is therefore always preserved across page changes without being encoded into URLs.
 *
 * ## Two presentation modes
 *
 *  • `numbers`   — Previous / Next with numbered page buttons between them.
 *  • `prev_next` — Previous / Next with a plain "Page 2 of 5" counter.
 *
 * ## Truncation
 *
 * In `numbers` mode at most {@see Config::pagination_max_numbers()} buttons are on screen
 * at once. The visible window is centred on the current page and clamped at both ends, with
 * an ellipsis marking each truncated side. With 9 pages and a limit of 6:
 *
 *     page 1 →  1 2 3 4 5 6 …
 *     page 4 →  … 2 3 4 5 6 7 …
 *     page 9 →  … 4 5 6 7 8 9
 *
 * Centring (rather than a window that only slides when the current page reaches an edge)
 * keeps roughly equal context on either side of where the visitor is, and guarantees the
 * button count never grows with the result set.
 */
final class PaginationRenderer
{
    /**
     * Renders the pagination nav, or an empty string when there is only one page.
     *
     * @param  int    $current_page 1-based page currently displayed.
     * @param  int    $total_pages  Total number of pages in the result set.
     * @param  Config $config       Instance configuration (controls numbered buttons).
     * @return string
     */
    public function render(int $current_page, int $total_pages, Config $config): string
    {
        if ($total_pages <= 1) {
            return '';
        }

        $current_page = max(1, min($current_page, $total_pages));

        $html = '<nav class="ajax-post-search__pagination" aria-label="'
            . esc_attr__('Results pagination', 'loop-grid-search') . '">';

        $html .= $this->render_step_button(
            'prev',
            $current_page - 1,
            $config->pagination_prev_label(),
            $current_page <= 1
        );

        if ($config->shows_page_numbers()) {
            $html .= '<span class="ajax-post-search__pages">';

            foreach ($this->page_sequence($current_page, $total_pages, $config->pagination_max_numbers()) as $entry) {
                $html .= null === $entry
                    ? '<span class="ajax-post-search__ellipsis" aria-hidden="true">&hellip;</span>'
                    : $this->render_number_button($entry, $entry === $current_page);
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

        $html .= $this->render_step_button(
            'next',
            $current_page + 1,
            $config->pagination_next_label(),
            $current_page >= $total_pages
        );

        $html .= '</nav>';

        /**
         * Filters the rendered pagination markup.
         *
         * @param string $html
         * @param int    $current_page
         * @param int    $total_pages
         * @param Config $config
         */
        return (string) apply_filters('lgs_pagination_html', $html, $current_page, $total_pages, $config);
    }

    /**
     * Renders the Previous or Next button.
     *
     * `disabled` plus `aria-disabled` covers both the real interaction state and screen
     * readers that surface aria-disabled preferentially.
     *
     * @param  string $direction 'prev' or 'next'.
     * @param  int    $page      Target page number.
     * @param  string $label     Visible button label.
     * @param  bool   $disabled  Whether the step is unavailable.
     * @return string
     */
    private function render_step_button(string $direction, int $page, string $label, bool $disabled): string
    {
        return sprintf(
            '<button type="button" class="ajax-post-search__page ajax-post-search__page--%1$s" data-lgs-page="%2$d"%3$s>%4$s</button>',
            esc_attr($direction),
            max(1, $page),
            $disabled ? ' disabled aria-disabled="true"' : '',
            esc_html($label)
        );
    }

    /**
     * Renders a single numbered page button.
     *
     * @param  int  $page       The page this button targets.
     * @param  bool $is_current Whether this is the page on screen.
     * @return string
     */
    private function render_number_button(int $page, bool $is_current): string
    {
        return sprintf(
            '<button type="button" class="ajax-post-search__page ajax-post-search__page--number%1$s" data-lgs-page="%2$d"%3$s>'
            . '<span class="screen-reader-text">%4$s </span>%2$d</button>',
            $is_current ? ' is-current' : '',
            $page,
            $is_current ? ' aria-current="page" aria-disabled="true"' : '',
            esc_html__('Page', 'loop-grid-search')
        );
    }

    /**
     * Builds the visible page sequence, using null to mark an ellipsis.
     *
     * The window holds exactly $max pages (or every page, when there are fewer), is centred
     * on the current page, and slides rather than growing. When $max is even the extra slot
     * falls after the current page, which reads better with a Next button on the right.
     *
     * Worked example — 9 pages, limit 6:
     *
     *   current 1 → start 1, end 6  → [1,2,3,4,5,6,null]
     *   current 4 → start 2, end 7  → [null,2,3,4,5,6,7,null]
     *   current 9 → start 4, end 9  → [null,4,5,6,7,8,9]
     *
     * @param  int $current Current page, already clamped to 1..$total by the caller.
     * @param  int $total   Total pages (always >= 2 here).
     * @param  int $max     Maximum numbered buttons to show at once.
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
