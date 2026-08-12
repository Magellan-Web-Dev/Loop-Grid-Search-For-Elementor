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
 * ## Loop Item templates need a different render call
 *
 * Elementor has two distinct stylesheet pipelines, and picking the wrong one silently drops
 * the template's CSS:
 *
 *  • Ordinary documents (section, container, page) use `Elementor\Core\Files\CSS\Post`,
 *    whose `FILE_PREFIX` is `post-`, producing `post-{id}.css` / handle `elementor-post-{id}`.
 *
 *  • **Loop Item** documents use Elementor Pro's `…\LoopBuilder\Files\Css\Loop`, whose
 *    `FILE_PREFIX` is `loop-`, producing `loop-{id}.css` / handle `loop-{id}`.
 *
 * Both classes share the same `_elementor_css` post meta key. So when a Loop Item template
 * is saved, Pro writes `loop-{id}.css` and stores the meta — and a generic
 * `get_builder_content_for_display()` call then reads that same meta, sees status "file",
 * and enqueues `post-{id}.css`, **a file Elementor never generated**. The request 404s and
 * the loop item renders unstyled.
 *
 * On top of that, Pro's `Loop` document contributes two things the generic path never emits:
 * the document's own **Custom CSS** setting (prepended by `Loop_CSS::print_all_css()`), and
 * **per-post dynamic CSS** — the styles produced by dynamic tags, whose selectors are
 * rewritten from `.elementor-{post}` to `.e-loop-item-{post}` and therefore differ for every
 * result in the loop.
 *
 * The fix is to use the exact entry point Pro's own Loop Grid uses. Its skin renders each
 * item through `Theme_Document::print_content()`, which is a thin `echo $this->get_content()`
 * — and `Loop::get_content()` installs Pro's `prevent_inline_css_printing` filter, echoes the
 * correct `loop-{id}` stylesheet once per request, then returns the markup. Calling
 * `$document->get_content()` therefore gets identical CSS to a native Loop Grid, without
 * reimplementing any of it. {@see render_loop_item()}.
 *
 * ## Rendering an Elementor template once per post
 *
 * For every other document type, `Plugin::$instance->frontend->get_builder_content_for_display()`
 * is Elementor's public, documented render API — the same call behind Elementor Pro's
 * Template widget and the `[elementor-template]` shortcode. It has two costs worth knowing
 * about:
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
     * Document type string of an Elementor Pro Loop Item template.
     *
     * Matches `ElementorPro\Modules\LoopBuilder\Documents\Loop::DOCUMENT_TYPE`. Compared as
     * a string rather than with `instanceof` so nothing here has to reference — or load — a
     * Pro class that may not exist.
     *
     * @var string
     */
    private const LOOP_DOCUMENT_TYPE = 'loop-item';

    /**
     * Fully-qualified name of Pro's Loop CSS file class, used only for per-post dynamic CSS.
     *
     * Guarded by class_exists()/method_exists() at every call site, so a Pro version that
     * moves or renames it degrades to "no per-post dynamic styles" instead of fataling.
     *
     * @var string
     */
    private const LOOP_CSS_CLASS = '\ElementorPro\Modules\LoopBuilder\Files\Css\Loop';

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

        // Resolved once, not per result: deciding which render path to take needs a
        // document lookup, and the answer cannot change mid-loop.
        $loop_document = $template_id > 0 ? $this->resolve_loop_document($template_id) : null;

        // The print-once guard applies only to the generic template path. Loop Item
        // documents install Pro's own `prevent_inline_css_printing` filter and dedupe their
        // stylesheet internally, so adding ours on top would do nothing but consume its one
        // permitted print on the first card.
        $css_guard = ($template_id > 0 && null === $loop_document)
            ? $this->add_css_print_guard()
            : null;

        $html = '<div class="ajax-post-search__list">';

        try {
            while ($query->have_posts()) {
                // Sets $GLOBALS['post'] and runs setup_postdata() for this result.
                $query->the_post();

                if (null !== $loop_document) {
                    $card = $this->render_loop_item($loop_document, $template_id);
                } elseif ($template_id > 0) {
                    $card = $this->render_elementor_template($template_id);
                } else {
                    $card = $this->render_fallback_card($config);
                }

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
     * Returns the Elementor document for a template ID when — and only when — it is a
     * Pro Loop Item template.
     *
     * Returns null for every other document type (and whenever Elementor or Pro is not
     * available), which routes the caller to the generic template path.
     *
     * @param  int $template_id
     * @return object|null The Loop document instance, or null.
     */
    private function resolve_loop_document(int $template_id): ?object
    {
        if (!class_exists('\Elementor\Plugin')) {
            return null;
        }

        $elementor = \Elementor\Plugin::$instance;

        if (!isset($elementor->documents) || !method_exists($elementor->documents, 'get_doc_for_frontend')) {
            return null;
        }

        $document = $elementor->documents->get_doc_for_frontend($template_id);

        // get_content() is the render entry point; get_type() identifies the document.
        if (!is_object($document) || !method_exists($document, 'get_content') || !method_exists($document, 'get_type')) {
            return null;
        }

        return self::LOOP_DOCUMENT_TYPE === (string) $document::get_type() ? $document : null;
    }

    /**
     * Renders one Loop Item template for the current global post.
     *
     * Mirrors what Elementor Pro's Loop Grid skin does per item:
     *
     *   1. print the per-post dynamic CSS (differs for every result), then
     *   2. render the document through `get_content()`, which echoes the `loop-{id}`
     *      stylesheet the first time it runs and returns the item markup.
     *
     * Both of those steps `echo` directly rather than returning a string, so the whole call
     * is buffered here and the captured CSS is prepended to the returned markup. That keeps
     * an AJAX response self-contained: the stylesheet travels with the first card.
     *
     * @param  object $document    A Loop Item document, from {@see resolve_loop_document()}.
     * @param  int    $template_id The template post ID.
     * @return string
     */
    private function render_loop_item(object $document, int $template_id): string
    {
        ob_start();

        try {
            $this->print_loop_dynamic_css($template_id, (int) get_the_ID());

            $markup = (string) $document->get_content();
        } catch (\Throwable $e) {
            // This path touches Elementor Pro internals for the per-post dynamic CSS, so a
            // future Pro release could break it. Failing soft keeps one malformed template
            // from taking down the whole page — but stay loud while WP_DEBUG is on, so the
            // breakage is visible during development rather than silently swallowed.
            ob_end_clean();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                throw $e;
            }

            return '';
        }

        return (string) ob_get_clean() . $markup;
    }

    /**
     * Prints the dynamic-tag-driven CSS for one result post.
     *
     * This is the piece that makes per-post styling work — a background image pulled from an
     * ACF field, a colour driven by a dynamic tag, and so on. Pro generates it per post and
     * rewrites the selectors from `.elementor-{post}` to `.e-loop-item-{post}`, which is why
     * it cannot be printed once and reused like the template stylesheet.
     *
     * Silently does nothing when Elementor Pro is absent or has moved the class.
     *
     * @param  int $template_id The Loop Item template post ID.
     * @param  int $post_id     The result post being rendered.
     * @return void
     */
    private function print_loop_dynamic_css(int $template_id, int $post_id): void
    {
        $css_class = self::LOOP_CSS_CLASS;

        if ($post_id <= 0 || !class_exists($css_class) || !method_exists($css_class, 'create')) {
            return;
        }

        $css_file = $css_class::create($template_id);

        if (is_object($css_file) && method_exists($css_file, 'print_dynamic_css')) {
            $css_file->print_dynamic_css($post_id, $template_id);
        }
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
