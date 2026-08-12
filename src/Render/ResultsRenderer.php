<?php

declare(strict_types=1);

namespace LoopGridSearch\Render;

if (!defined('ABSPATH')) exit;

use LoopGridSearch\Support\Config;

/**
 * Renders the inner HTML of the results area — server-side, in one pass.
 *
 * The browser therefore receives finished markup (including `<img>` tags with real
 * `srcset` values) in a single AJAX response. There is no follow-up request per post and
 * no follow-up request per featured image.
 *
 * ## WordPress post context
 *
 * Elementor dynamic tags and widgets (Post Title, Featured Image, Post Excerpt, ACF
 * fields, Post URL, taxonomy terms) resolve against the global `$post` / `get_the_ID()`.
 * `WP_Query::the_post()` sets `$GLOBALS['post']` and calls `setup_postdata()`, which is
 * exactly the context those widgets expect — it is the same mechanism a theme's
 * `while ( have_posts() ) : the_post();` loop uses, and the same one Elementor Pro's own
 * Loop Grid relies on.
 *
 * Because a shortcode normally executes *inside* the page's main loop, the outer
 * `$GLOBALS['post']` (the page being viewed) is captured before the loop and restored
 * afterwards, in addition to calling `wp_reset_postdata()`. The search query object itself
 * is a secondary `WP_Query`; the main query is never touched.
 *
 * ## Rendering an Elementor template once per post
 *
 * `Plugin::$instance->frontend->get_builder_content_for_display()` is Elementor's public,
 * documented render API — the same call behind Elementor Pro's Template widget and the
 * `[elementor-template]` shortcode. It is used here per result, which has two costs worth
 * knowing about:
 *
 *  1. **CSS duplication.** Inside an AJAX request Elementor forces its `$with_css` flag on
 *     and prints the template's stylesheet inline, so a naive loop would emit the same
 *     `<style>` block once per card. The `elementor/frontend/builder_content/before_print_css`
 *     filter (a public Elementor filter) is used to let only the first card print it, which
 *     keeps each AJAX response self-contained without repeating kilobytes of CSS.
 *     Caveat: if the loop template itself embeds *another* Elementor template, that nested
 *     stylesheet is also suppressed from card 2 onward — harmless here, because the initial
 *     page render (below) has already enqueued it as a real stylesheet.
 *
 *  2. **Per-post render cost.** Each call re-walks the template's element tree. This is the
 *     same work Elementor Pro's Loop Grid does per item, so the cost is comparable to a
 *     native loop grid of the same size; it is bounded by `posts_per_page`, never by the
 *     total result count.
 *
 * The shortcode/widget deliberately renders page 1 server-side on the initial page load.
 * That matters beyond first paint: it lets Elementor's conditional asset loader see every
 * widget used by the loop template during the normal page lifecycle, so those widgets' CSS
 * and JS are enqueued in `<head>`/footer as usual and are already present when later pages
 * arrive over AJAX.
 */
final class ResultsRenderer
{
    /**
     * Renders the result list (or the empty-state message) for a finished query.
     *
     * @param  \WP_Query $query  A secondary query produced by {@see \LoopGridSearch\Query\QueryBuilder}.
     * @param  Config    $config The validated instance configuration.
     * @return string HTML for the inside of .ajax-post-search__results.
     */
    public function render(\WP_Query $query, Config $config): string
    {
        if (!$query->have_posts()) {
            return $this->render_empty($config);
        }

        // Capture the surrounding post context so nothing after this shortcode sees a
        // mutated global. Restored in the finally block below.
        $outer_post = $GLOBALS['post'] ?? null;

        $template_id = $config->template_id();
        $css_guard   = $template_id > 0 ? $this->add_css_print_guard() : null;

        $html = '<div class="ajax-post-search__list">';

        try {
            while ($query->have_posts()) {
                // Sets $GLOBALS['post'] and runs setup_postdata() for this result.
                $query->the_post();

                $card = $template_id > 0
                    ? $this->render_elementor_template($template_id)
                    : $this->render_fallback_card($config);

                if ('' === trim($card)) {
                    continue;
                }

                $html .= '<div class="ajax-post-search__item">' . $card . '</div>';
            }
        } finally {
            if (null !== $css_guard) {
                remove_filter('elementor/frontend/builder_content/before_print_css', $css_guard);
            }

            // Restore the main query's post pointer…
            wp_reset_postdata();

            // …and, for the case where this shortcode ran outside the main loop, put the
            // exact post object we found back in place.
            if ($outer_post instanceof \WP_Post) {
                $GLOBALS['post'] = $outer_post;
                setup_postdata($outer_post);
            }
        }

        $html .= '</div>';

        /**
         * Filters the rendered results markup.
         *
         * @param string    $html
         * @param \WP_Query $query
         * @param Config    $config
         */
        return (string) apply_filters('lgs_results_html', $html, $query, $config);
    }

    /**
     * Renders the "nothing found" message.
     *
     * Uses role="status" so assistive technology announces the change when the results
     * area is swapped, and never returns an empty string — the JavaScript therefore has
     * markup to insert for zero results and cannot fail on an undefined value.
     *
     * @param  Config $config
     * @return string
     */
    private function render_empty(Config $config): string
    {
        $html = '<p class="ajax-post-search__no-results" role="status">'
            . esc_html($config->no_results_text())
            . '</p>';

        /**
         * Filters the empty-results markup.
         *
         * @param string $html
         * @param Config $config
         */
        return (string) apply_filters('lgs_no_results_html', $html, $config);
    }

    /**
     * Renders one Elementor template for the current global post.
     *
     * `$with_css` is passed as false so that on a normal page render Elementor enqueues the
     * template stylesheet properly instead of inlining it per card. Elementor overrides the
     * flag to true by itself when `wp_doing_ajax()` is true, which is what makes an AJAX
     * response self-contained.
     *
     * @param  int $template_id A post ID already validated as "built with Elementor".
     * @return string
     */
    private function render_elementor_template(int $template_id): string
    {
        if (!class_exists('\Elementor\Plugin')) {
            return '';
        }

        $elementor = \Elementor\Plugin::$instance;

        if (!isset($elementor->frontend)) {
            return '';
        }

        return (string) $elementor->frontend->get_builder_content_for_display($template_id, false);
    }

    /**
     * Renders the PHP fallback card for the current global post.
     *
     * Looks for a theme override first, so the card can be customised without touching the
     * plugin:
     *
     *   your-theme/loop-grid-search/result-card.php
     *
     * The template receives $config (the Config instance) and $lgs_post (the current
     * WP_Post) in scope.
     *
     * @param  Config $config
     * @return string
     */
    private function render_fallback_card(Config $config): string
    {
        $template = locate_template(['loop-grid-search/result-card.php']);

        if ('' === $template) {
            $template = LGS_PATH . 'templates/result-card.php';
        }

        /**
         * Filters the absolute path of the fallback card template.
         *
         * @param string $template Absolute path to the PHP template file.
         * @param Config $config
         */
        $template = (string) apply_filters('lgs_result_card_template', $template, $config);

        if (!is_file($template)) {
            return '';
        }

        $lgs_post = $GLOBALS['post'] ?? null;

        ob_start();
        // Both variables are consumed by the included template.
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * Registers a filter that lets only the first rendered card print the template CSS.
     *
     * @return callable The registered callback, so the caller can remove it again.
     */
    private function add_css_print_guard(): callable
    {
        $printed = false;

        $guard = static function (mixed $with_css) use (&$printed): mixed {
            if ($printed) {
                return false;
            }

            $printed = true;

            return $with_css;
        };

        add_filter('elementor/frontend/builder_content/before_print_css', $guard);

        return $guard;
    }
}
