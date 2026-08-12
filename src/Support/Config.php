<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Immutable, validated configuration for a single search instance.
 *
 * A Config is the *server's* description of what a shortcode / widget instance is
 * allowed to query and render. It is created in two places:
 *
 *   • {@see from_attributes()}  — during shortcode / widget render, from author-supplied
 *                                 attributes. Every value is validated against the real
 *                                 WordPress registry (post types, taxonomies, templates)
 *                                 and falls back to a safe default when invalid.
 *
 *   • {@see from_client_array()} — during an AJAX request, from the payload the browser
 *                                 echoes back. Values are normalised deterministically,
 *                                 the HMAC signature is verified, and then every value is
 *                                 re-validated strictly (no fallbacks — a mismatch is a
 *                                 hard rejection).
 *
 * ## Why the signature exists
 *
 * The AJAX endpoint must know which post type, meta key and taxonomy to query, but it must
 * never *trust* the browser to tell it. Storing per-instance config in a transient would
 * work but adds cache churn and breaks on multisite object-cache flushes. Instead the
 * server signs the query-relevant fields with {@see wp_hash()} (HMAC-MD5 keyed by the
 * site's salts, which are never exposed to the client). The browser echoes the values and
 * the signature back; an attacker cannot forge a signature for a payload the server never
 * produced, so any accepted payload is provably one this site emitted.
 *
 * Only the fields the AJAX endpoint actually needs are signed and transmitted
 * ({@see SIGNED_KEYS}). Labels and visibility toggles are render-time only and stay
 * on the server.
 */
final class Config
{
    /**
     * Configuration keys that are transmitted to the browser and signed.
     *
     * Anything not on this list can never influence an AJAX query.
     *
     * @var list<string>
     */
    private const SIGNED_KEYS = [
        'post_type',
        'search_columns',
        'search_meta_keys',
        'taxonomy',
        'posts_per_page',
        'template_id',
        'orderby',
        'order',
        'pagination_mode',
        'pagination_max_numbers',
        'pagination_prev_label',
        'pagination_next_label',
        'no_results_text',
    ];

    /**
     * Allowed WP_Query "orderby" values.
     *
     * Deliberately excludes 'rand', which produces unstable pagination.
     *
     * @var list<string>
     */
    private const ALLOWED_ORDERBY = ['date', 'title', 'modified', 'menu_order', 'ID'];

    /**
     * Allowed pagination presentation modes.
     *
     * @var list<string>
     */
    private const ALLOWED_PAGINATION_MODES = ['numbers', 'prev_next'];

    /** @var int Fewest numbered buttons a truncated pagination may show. */
    private const MIN_PAGE_NUMBERS = 3;

    /** @var int Most numbered buttons a pagination may show. */
    private const MAX_PAGE_NUMBERS = 50;

    /** @var int Hard upper bound for posts_per_page, regardless of what an author enters. */
    private const MAX_POSTS_PER_PAGE = 100;

    /** @var int Hard upper bound on searchable meta keys, to bound the generated SQL. */
    private const MAX_SEARCH_META_KEYS = 20;

    /**
     * Default value for every recognised configuration key.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        // ── Query ────────────────────────────────────────────────────────────────
        'post_type'          => 'post',

        // Native wp_posts columns the keyword matches. Title only, by default.
        'search_columns'     => ['post_title'],

        // Post meta keys OR-ed with the columns above. The original brief's ACF
        // "excerpt" field is the default so the plugin works out of the box.
        'search_meta_keys'   => ['excerpt'],

        'taxonomy'           => 'post_tag',
        'posts_per_page'     => 9,
        'orderby'            => 'date',
        'order'              => 'DESC',

        // ── Rendering ────────────────────────────────────────────────────────────
        'template_id'        => 0,
        'columns'            => 3,
        'gap'                => 24,

        // ── Pagination ───────────────────────────────────────────────────────────
        // 'numbers'   → Previous / Next plus numbered page buttons
        // 'prev_next' → Previous / Next plus a "Page 2 of 5" counter
        'pagination_mode'        => 'numbers',

        // Maximum numbered buttons on screen at once. The window slides with the
        // current page and ellipses mark the truncated ends.
        'pagination_max_numbers' => 6,

        // Button text (filled with translated defaults by apply_label_defaults()).
        'pagination_prev_label'  => '',
        'pagination_next_label'  => '',

        // ── Filter bar visibility ────────────────────────────────────────────────
        'show_keyword'       => true,
        'show_date'          => true,
        'show_taxonomy'      => true,
        'show_sort'          => false,
        'show_clear'         => true,

        // ── Labels (render-time only, never sent to the browser) ─────────────────
        'keyword_label'        => '',
        'keyword_placeholder'  => '',
        'date_label'           => '',
        'date_all_label'       => '',
        'taxonomy_label'       => '',
        'taxonomy_all_label'   => '',
        'sort_label'           => '',
        'clear_label'          => '',
        'no_results_text'      => '',
    ];

    /**
     * @param array<string, mixed> $values Fully normalised and validated values.
     */
    private function __construct(private readonly array $values) {}

    // ── Factories ─────────────────────────────────────────────────────────────────

    /**
     * Builds a Config from author-supplied shortcode attributes or widget settings.
     *
     * Invalid values degrade to defaults rather than failing, so a typo in a shortcode
     * never takes a page down.
     *
     * @param  array<string, mixed> $atts Raw attributes; unknown keys are ignored.
     * @return self
     */
    public static function from_attributes(array $atts): self
    {
        $values = self::normalize($atts);

        // ── Post type: must exist and be publicly queryable ─────────────────────
        if (!self::is_valid_post_type($values['post_type'])) {
            $values['post_type'] = self::DEFAULTS['post_type'];
        }

        // ── Taxonomy: must exist and be public. Empty string disables the filter ─
        if ('' !== $values['taxonomy'] && !self::is_valid_taxonomy($values['taxonomy'])) {
            $values['taxonomy'] = self::is_valid_taxonomy(self::DEFAULTS['taxonomy'])
                ? self::DEFAULTS['taxonomy']
                : '';
        }
        if ('' === $values['taxonomy']) {
            $values['show_taxonomy'] = false;
        }

        // ── Template: must be a post that was actually built with Elementor ─────
        if (0 !== $values['template_id'] && !self::is_valid_template($values['template_id'])) {
            $values['template_id'] = 0;
        }

        // Fill in translatable label defaults that cannot live in a const array.
        $values = self::apply_label_defaults($values);

        return new self($values);
    }

    /**
     * Rebuilds a Config from the signed payload a browser echoed back.
     *
     * Returns null when the signature does not verify or when any value no longer
     * passes strict validation (for example, a post type that was unregistered after
     * the page was cached). Callers must treat null as a hard request failure.
     *
     * @param  array<string, mixed> $raw Unslashed $_POST['config'] data.
     * @return self|null
     */
    public static function from_client_array(array $raw): ?self
    {
        if (empty($raw['signature']) || !is_string($raw['signature'])) {
            return null;
        }

        // A form-encoded payload can nest arrays (config[post_type][]=x). The signed payload
        // is flat by construction, so anything non-scalar is malformed by definition and is
        // rejected here rather than being coerced (which would emit conversion notices).
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                return null;
            }
        }

        // Normalisation is purely deterministic (no registry lookups, no fallbacks),
        // so a legitimate payload round-trips to byte-identical signable values.
        $values   = self::normalize($raw);
        $signable = self::signable($values);

        // Canonical-form check, before the signature comparison.
        //
        // Some tampering survives normalisation without changing the signed string: adding
        // `post_password` to search_columns, for instance, is dropped by the column
        // allowlist, so the payload would still verify and simply be stripped. That is
        // harmless — the allowlist is the real boundary — but "sometimes silently corrected,
        // sometimes rejected" is a confusing security property to reason about.
        //
        // Requiring the received values to already be in canonical form makes the guarantee
        // uniform and easy to state: an accepted payload is byte-identical to one this
        // server emitted. Legitimate payloads always pass, because to_client_array() emits
        // exactly this canonical form and normalize() is idempotent on its own output.
        foreach ($signable as $key => $canonical) {
            if ($canonical !== (string) ($raw[$key] ?? '')) {
                return null;
            }
        }

        if (!hash_equals(self::sign($values), $raw['signature'])) {
            return null;
        }

        // Defence in depth: even a correctly signed payload is re-checked against the
        // live registry, because the site's configuration may have changed since the
        // page (or its cached HTML) was generated.
        if (!self::is_valid_post_type($values['post_type'])) {
            return null;
        }
        if ('' !== $values['taxonomy'] && !self::is_valid_taxonomy($values['taxonomy'])) {
            return null;
        }
        if (0 !== $values['template_id'] && !self::is_valid_template($values['template_id'])) {
            // A missing template is not a security problem — fall back to the PHP card.
            $values['template_id'] = 0;
        }

        $values = self::apply_label_defaults($values);

        return new self($values);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────────

    /** @return string Post type slug being queried. */
    public function post_type(): string
    {
        return (string) $this->values['post_type'];
    }

    /**
     * Native wp_posts columns the keyword is matched against.
     *
     * Always a subset of {@see FieldRegistry::SEARCHABLE_COLUMNS}.
     *
     * @return list<string>
     */
    public function search_columns(): array
    {
        return (array) $this->values['search_columns'];
    }

    /**
     * Post meta keys the keyword is matched against, OR-ed with the columns above.
     *
     * @return list<string>
     */
    public function search_meta_keys(): array
    {
        return (array) $this->values['search_meta_keys'];
    }

    /**
     * First searchable meta key, or '' when the search is column-only.
     *
     * Convenience for templates that want to display "the field that was searched" — the
     * bundled result card uses it as its summary source. Retained under the original single
     * -field name so a card template copied from an earlier version keeps working.
     *
     * @return string
     */
    public function meta_search_field(): string
    {
        return $this->search_meta_keys()[0] ?? '';
    }

    /** @return string Taxonomy slug used by the term dropdown, or '' when disabled. */
    public function taxonomy(): string
    {
        return (string) $this->values['taxonomy'];
    }

    /** @return int Results per page (1–100). */
    public function posts_per_page(): int
    {
        return (int) $this->values['posts_per_page'];
    }

    /** @return int Elementor template post ID, or 0 to use the PHP fallback card. */
    public function template_id(): int
    {
        return (int) $this->values['template_id'];
    }

    /** @return string Default WP_Query orderby value. */
    public function orderby(): string
    {
        return (string) $this->values['orderby'];
    }

    /** @return string Default sort direction, ASC or DESC. */
    public function order(): string
    {
        return (string) $this->values['order'];
    }

    /** @return int Grid column count used by the instance stylesheet. */
    public function columns(): int
    {
        return (int) $this->values['columns'];
    }

    /** @return int Grid gap in pixels. */
    public function gap(): int
    {
        return (int) $this->values['gap'];
    }

    /** @return string Pagination presentation mode: 'numbers' or 'prev_next'. */
    public function pagination_mode(): string
    {
        return (string) $this->values['pagination_mode'];
    }

    /** @return bool Whether numbered page buttons are rendered next to Previous/Next. */
    public function shows_page_numbers(): bool
    {
        return 'numbers' === $this->pagination_mode();
    }

    /**
     * Maximum numbered buttons visible at once before truncation kicks in.
     *
     * @return int Between MIN_PAGE_NUMBERS and MAX_PAGE_NUMBERS.
     */
    public function pagination_max_numbers(): int
    {
        return (int) $this->values['pagination_max_numbers'];
    }

    /** @return string Text of the Previous button. */
    public function pagination_prev_label(): string
    {
        return (string) $this->values['pagination_prev_label'];
    }

    /** @return string Text of the Next button. */
    public function pagination_next_label(): string
    {
        return (string) $this->values['pagination_next_label'];
    }

    /**
     * Returns any boolean or string configuration value by key.
     *
     * Used by the renderers for labels and visibility toggles, which are numerous
     * enough that individual accessors would be noise.
     *
     * @param  string $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** @return bool */
    public function show_keyword(): bool
    {
        return (bool) $this->values['show_keyword'];
    }

    /** @return bool */
    public function show_date(): bool
    {
        return (bool) $this->values['show_date'];
    }

    /** @return bool */
    public function show_taxonomy(): bool
    {
        return (bool) $this->values['show_taxonomy'] && '' !== $this->taxonomy();
    }

    /** @return bool */
    public function show_sort(): bool
    {
        return (bool) $this->values['show_sort'];
    }

    /** @return bool */
    public function show_clear(): bool
    {
        return (bool) $this->values['show_clear'];
    }

    /** @return string Message shown when a query returns no posts. */
    public function no_results_text(): string
    {
        return (string) $this->values['no_results_text'];
    }

    // ── Client payload ────────────────────────────────────────────────────────────

    /**
     * Returns the signed subset of this configuration for embedding in the page.
     *
     * All values are cast to strings because that is how the browser will send them
     * back — keeping the signature input byte-identical in both directions.
     *
     * @return array<string, string>
     */
    public function to_client_array(): array
    {
        $payload = self::signable($this->values);
        $payload['signature'] = self::sign($this->values);

        return $payload;
    }

    // ── Normalisation & signing ───────────────────────────────────────────────────

    /**
     * Coerces arbitrary input into the canonical value set.
     *
     * This method is intentionally free of database and registry lookups: it must
     * produce byte-identical output for the same input on both the render request and
     * the later AJAX request, otherwise signatures would not verify.
     *
     * @param  array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normalize(array $raw): array
    {
        $values = self::DEFAULTS;

        // ── Query ────────────────────────────────────────────────────────────────
        if (isset($raw['post_type'])) {
            $post_type = sanitize_key((string) $raw['post_type']);
            $values['post_type'] = '' !== $post_type ? $post_type : self::DEFAULTS['post_type'];
        }

        if (isset($raw['search_columns'])) {
            $values['search_columns'] = self::normalize_columns($raw['search_columns']);
        }

        if (isset($raw['search_meta_keys'])) {
            $values['search_meta_keys'] = self::normalize_meta_keys($raw['search_meta_keys']);
        }

        // A keyword box with nothing behind it can never match anything, which reads as a
        // broken search rather than a configuration mistake. Fall back to the post title.
        if ([] === $values['search_columns'] && [] === $values['search_meta_keys']) {
            $values['search_columns'] = ['post_title'];
        }

        if (isset($raw['taxonomy'])) {
            $values['taxonomy'] = sanitize_key((string) $raw['taxonomy']);
        }

        if (isset($raw['posts_per_page'])) {
            $per_page = absint($raw['posts_per_page']);
            $values['posts_per_page'] = max(1, min(self::MAX_POSTS_PER_PAGE, $per_page ?: self::DEFAULTS['posts_per_page']));
        }

        if (isset($raw['orderby'])) {
            $orderby = (string) $raw['orderby'];
            $values['orderby'] = in_array($orderby, self::ALLOWED_ORDERBY, true) ? $orderby : self::DEFAULTS['orderby'];
        }

        if (isset($raw['order'])) {
            $values['order'] = 'ASC' === strtoupper((string) $raw['order']) ? 'ASC' : 'DESC';
        }

        // ── Rendering ────────────────────────────────────────────────────────────
        if (isset($raw['template_id'])) {
            $values['template_id'] = absint($raw['template_id']);
        }

        if (isset($raw['columns'])) {
            $values['columns'] = max(1, min(8, absint($raw['columns']) ?: self::DEFAULTS['columns']));
        }

        if (isset($raw['gap'])) {
            $values['gap'] = max(0, min(200, absint($raw['gap'])));
        }

        // ── Pagination ───────────────────────────────────────────────────────────
        if (isset($raw['pagination_mode'])) {
            $mode = is_scalar($raw['pagination_mode']) ? (string) $raw['pagination_mode'] : '';

            $values['pagination_mode'] = in_array($mode, self::ALLOWED_PAGINATION_MODES, true)
                ? $mode
                : self::DEFAULTS['pagination_mode'];
        } elseif (isset($raw['pagination_numbers'])) {
            // Legacy boolean spelling, kept so existing shortcodes keep working. The
            // browser never sends this — pagination_mode is the signed key — so the AJAX
            // round trip stays deterministic.
            $values['pagination_mode'] = self::to_bool($raw['pagination_numbers']) ? 'numbers' : 'prev_next';
        }

        if (isset($raw['pagination_max_numbers'])) {
            $max = absint($raw['pagination_max_numbers']) ?: self::DEFAULTS['pagination_max_numbers'];

            $values['pagination_max_numbers'] = max(self::MIN_PAGE_NUMBERS, min(self::MAX_PAGE_NUMBERS, $max));
        }

        // ── Booleans ─────────────────────────────────────────────────────────────
        foreach (['show_keyword', 'show_date', 'show_taxonomy', 'show_sort', 'show_clear'] as $flag) {
            if (isset($raw[$flag])) {
                $values[$flag] = self::to_bool($raw[$flag]);
            }
        }

        // ── Text ─────────────────────────────────────────────────────────────────
        foreach (
            [
                'keyword_label', 'keyword_placeholder', 'date_label', 'date_all_label',
                'taxonomy_label', 'taxonomy_all_label', 'sort_label', 'clear_label',
                'pagination_prev_label', 'pagination_next_label', 'no_results_text',
            ] as $text_key
        ) {
            if (isset($raw[$text_key]) && is_scalar($raw[$text_key])) {
                $values[$text_key] = sanitize_text_field((string) $raw[$text_key]);
            }
        }

        return $values;
    }

    /**
     * Restricts a column list to the searchable wp_posts columns.
     *
     * The allowlist order is used as the output order, so the result is identical no matter
     * what order the values arrived in — a hard requirement for signature stability.
     *
     * @param  mixed $raw Array, or a comma-separated string (how the browser sends it).
     * @return list<string>
     */
    private static function normalize_columns(mixed $raw): array
    {
        $requested = self::to_list($raw);
        $columns   = [];

        foreach (FieldRegistry::SEARCHABLE_COLUMNS as $column) {
            if (in_array($column, $requested, true)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Validates, de-duplicates and orders a list of searchable meta keys.
     *
     * Keys are sorted so the list is order-independent, and capped so a crafted payload
     * cannot generate an unbounded IN() clause. The character-class check mirrors what
     * WordPress accepts for a meta key; the keys are bound as query parameters regardless.
     *
     * @param  mixed $raw Array, or a comma-separated string.
     * @return list<string>
     */
    private static function normalize_meta_keys(mixed $raw): array
    {
        $keys = [];

        foreach (self::to_list($raw) as $key) {
            if (preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $key)) {
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return array_slice($keys, 0, self::MAX_SEARCH_META_KEYS);
    }

    /**
     * Coerces an array or comma-separated string into a list of trimmed, non-empty strings.
     *
     * @param  mixed $raw
     * @return list<string>
     */
    private static function to_list(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) (is_scalar($raw) ? $raw : ''));
        $list  = [];

        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $value = trim((string) $item);

            if ('' !== $value) {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * Reduces a normalised value set to the exact string map that gets signed.
     *
     * Lists are joined with commas. Because {@see normalize_columns()} and
     * {@see normalize_meta_keys()} both impose a deterministic order, and no valid column
     * name or meta key can contain a comma, the join is lossless and round-trips exactly.
     *
     * @param  array<string, mixed> $values
     * @return array<string, string>
     */
    private static function signable(array $values): array
    {
        $signable = [];

        foreach (self::SIGNED_KEYS as $key) {
            $value = $values[$key] ?? '';

            if (is_array($value)) {
                $signable[$key] = implode(',', $value);
                continue;
            }

            $signable[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $signable;
    }

    /**
     * Produces the HMAC signature for a normalised value set.
     *
     * wp_hash() is keyed by the site's AUTH salts, so the signature cannot be
     * reproduced by anyone without filesystem access to wp-config.php.
     *
     * @param  array<string, mixed> $values
     * @return string
     */
    private static function sign(array $values): string
    {
        return wp_hash((string) wp_json_encode(self::signable($values)));
    }

    // ── Validation helpers ────────────────────────────────────────────────────────

    /**
     * True when the slug is a registered post type that is safe to expose publicly.
     *
     * @param  mixed $post_type
     * @return bool
     */
    private static function is_valid_post_type(mixed $post_type): bool
    {
        if (!is_string($post_type) || '' === $post_type) {
            return false;
        }

        $object = get_post_type_object($post_type);

        return null !== $object && ($object->public || $object->publicly_queryable);
    }

    /**
     * True when the slug is a registered, public taxonomy.
     *
     * @param  mixed $taxonomy
     * @return bool
     */
    private static function is_valid_taxonomy(mixed $taxonomy): bool
    {
        if (!is_string($taxonomy) || '' === $taxonomy) {
            return false;
        }

        $object = get_taxonomy($taxonomy);

        return false !== $object && ($object->public || $object->publicly_queryable);
    }

    /**
     * True when the post ID exists and was built with Elementor.
     *
     * Checks the _elementor_edit_mode meta directly rather than calling
     * Document::is_built_with_elementor(), so validation works even if Elementor is
     * not loaded yet and stays stable across Elementor versions.
     *
     * @param  int $template_id
     * @return bool
     */
    private static function is_valid_template(int $template_id): bool
    {
        if ($template_id <= 0 || null === get_post($template_id)) {
            return false;
        }

        return 'builder' === get_post_meta($template_id, '_elementor_edit_mode', true);
    }

    /**
     * Fills empty label values with their translated defaults.
     *
     * Cannot live in the DEFAULTS constant because __() calls are not allowed in a
     * constant expression and translations are not loaded when the class is compiled.
     *
     * Every default is passed through sanitize_text_field() — the same transform
     * normalize() applies — so that a translated default which happens to contain
     * characters sanitize_text_field() would alter still round-trips to an identical
     * signature on the following AJAX request. (no_results_text is a signed key.)
     *
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function apply_label_defaults(array $values): array
    {
        $defaults = [
            'keyword_label'       => __('Search', 'loop-grid-search'),
            'keyword_placeholder' => __('Search…', 'loop-grid-search'),
            'date_label'          => __('Date', 'loop-grid-search'),
            'date_all_label'      => __('All Dates', 'loop-grid-search'),
            'taxonomy_label'      => __('Tag', 'loop-grid-search'),
            'taxonomy_all_label'  => __('All Tags', 'loop-grid-search'),
            'sort_label'          => __('Sort By', 'loop-grid-search'),
            'clear_label'         => __('Clear Filters', 'loop-grid-search'),
            'pagination_prev_label' => __('Previous', 'loop-grid-search'),
            'pagination_next_label' => __('Next', 'loop-grid-search'),
            'no_results_text'     => __('No results found matching your search.', 'loop-grid-search'),
        ];

        foreach ($defaults as $key => $default) {
            if (!isset($values[$key]) || '' === $values[$key]) {
                $values[$key] = sanitize_text_field($default);
            }
        }

        return $values;
    }

    /**
     * Interprets the many truthy spellings that arrive from shortcodes, Elementor
     * switchers ("yes") and form-encoded AJAX payloads ("1").
     *
     * @param  mixed $value
     * @return bool
     */
    private static function to_bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }
}
