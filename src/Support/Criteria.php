<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Immutable, validated set of the filters a visitor has chosen.
 *
 * Where {@see Config} is what the *site author* configured, Criteria is what the
 * *visitor* asked for. Every value originates from untrusted request input, so each
 * one is sanitised and range-checked here, once, before it can reach a query.
 *
 * Nothing in this class is ever interpolated into SQL:
 *   • keyword  → bound with $wpdb->prepare() + $wpdb->esc_like() in KeywordSearch
 *   • year/month → passed to WP_Query's date_query, which builds its own SQL
 *   • terms    → each verified to be a real term in one of the *configured* taxonomies,
 *                then passed to tax_query as an integer
 *   • page     → absint, floor of 1
 */
final class Criteria
{
    /** @var int Maximum keyword length accepted, to bound the generated SQL. */
    private const MAX_KEYWORD_LENGTH = 200;

    /**
     * Sort presets the browser may request, mapped to WP_Query orderby/order pairs.
     *
     * Sorting is not a security boundary (any visitor may reorder public results), so
     * unlike the query scope in Config this does not need to be signed — but it *is*
     * restricted to this fixed map so no arbitrary orderby value can reach WP_Query.
     *
     * @var array<string, array{orderby: string, order: string}>
     */
    private const SORT_PRESETS = [
        'newest'     => ['orderby' => 'date',  'order' => 'DESC'],
        'oldest'     => ['orderby' => 'date',  'order' => 'ASC'],
        'title_asc'  => ['orderby' => 'title', 'order' => 'ASC'],
        'title_desc' => ['orderby' => 'title', 'order' => 'DESC'],
    ];

    /**
     * @param string             $keyword Trimmed search phrase ('' when not searching).
     * @param int                $year    Four-digit year, or 0 for "all dates".
     * @param int                $month   Month number 1–12, or 0 for "all dates".
     * @param array<string, int> $terms   Verified term ID per taxonomy slug, in the
     *                                    instance's configured taxonomy order. A taxonomy
     *                                    with no selection is absent, never present as 0.
     * @param int                $page    1-based page number.
     * @param string             $sort    Key of {@see SORT_PRESETS}, or '' to use the Config default.
     */
    private function __construct(
        private readonly string $keyword,
        private readonly int $year,
        private readonly int $month,
        private readonly array $terms,
        private readonly int $page,
        private readonly string $sort,
    ) {}

    /**
     * Returns the unfiltered, first-page criteria used for the initial server render.
     *
     * @return self
     */
    public static function initial(): self
    {
        return new self('', 0, 0, [], 1, '');
    }

    /**
     * Builds Criteria from an untrusted request array.
     *
     * @param  array<string, mixed> $raw    Unslashed request data ($_POST).
     * @param  Config               $config The validated instance configuration, used to
     *                                      scope term validation to the right taxonomies.
     * @return self
     */
    public static function from_request(array $raw, Config $config): self
    {
        $date = self::parse_date($raw['date'] ?? '');

        return new self(
            self::parse_keyword($raw['keyword'] ?? ''),
            $date['year'],
            $date['month'],
            self::parse_terms($raw, $config),
            max(1, absint(is_scalar($raw['paged'] ?? 1) ? $raw['paged'] ?? 1 : 1)),
            self::parse_sort($raw['sort'] ?? '')
        );
    }

    // ── Accessors ─────────────────────────────────────────────────────────────────

    /** @return string Trimmed keyword, or '' when no keyword search is active. */
    public function keyword(): string
    {
        return $this->keyword;
    }

    /** @return bool */
    public function has_keyword(): bool
    {
        return '' !== $this->keyword;
    }

    /**
     * Splits the keyword into individual search terms.
     *
     * Each term must match (title OR meta) — see KeywordSearch for the SQL. Capped at
     * ten terms so a pathological query string cannot generate unbounded SQL.
     *
     * @return list<string>
     */
    public function keyword_terms(): array
    {
        if ('' === $this->keyword) {
            return [];
        }

        $terms = preg_split('/\s+/u', $this->keyword, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice(is_array($terms) ? $terms : [$this->keyword], 0, 10);
    }

    /** @return int Four-digit year, or 0 for "all dates". */
    public function year(): int
    {
        return $this->year;
    }

    /** @return int Month 1–12, or 0 for "all dates". */
    public function month(): int
    {
        return $this->month;
    }

    /** @return bool */
    public function has_date(): bool
    {
        return $this->year > 0 && $this->month > 0;
    }

    /**
     * Every selected term, keyed by taxonomy slug, in configured dropdown order.
     *
     * @return array<string, int>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    /**
     * Returns the verified term ID selected in one taxonomy, or 0 for "all terms".
     *
     * @param  string $taxonomy
     * @return int
     */
    public function term_for(string $taxonomy): int
    {
        return (int) ($this->terms[$taxonomy] ?? 0);
    }

    /**
     * First selected term ID across all taxonomies, or 0 when nothing is selected.
     *
     * Kept under the original single-term name for templates and filter callbacks written
     * against it; {@see term_for()} is the one to use when several dropdowns are in play.
     *
     * @return int
     */
    public function term_id(): int
    {
        foreach ($this->terms as $term_id) {
            return $term_id;
        }

        return 0;
    }

    /** @return bool True when at least one taxonomy dropdown has a term selected. */
    public function has_term(): bool
    {
        return [] !== $this->terms;
    }

    /**
     * @param  string $taxonomy
     * @return bool True when this taxonomy's dropdown has a term selected.
     */
    public function has_term_in(string $taxonomy): bool
    {
        return isset($this->terms[$taxonomy]);
    }

    /** @return int 1-based page number. */
    public function page(): int
    {
        return $this->page;
    }

    /** @return string Sort preset key, or '' when the Config default applies. */
    public function sort(): string
    {
        return $this->sort;
    }

    /**
     * Resolves the effective orderby/order pair for this request.
     *
     * @param  Config $config Supplies the default when no preset was requested.
     * @return array{orderby: string, order: string}
     */
    public function resolve_order(Config $config): array
    {
        if ('' !== $this->sort && isset(self::SORT_PRESETS[$this->sort])) {
            return self::SORT_PRESETS[$this->sort];
        }

        return ['orderby' => $config->orderby(), 'order' => $config->order()];
    }

    /**
     * Returns a copy of this Criteria pinned to a different page.
     *
     * Used to clamp an out-of-range page request back into the real result set.
     *
     * @param  int $page
     * @return self
     */
    public function with_page(int $page): self
    {
        return new self($this->keyword, $this->year, $this->month, $this->terms, max(1, $page), $this->sort);
    }

    /**
     * Returns the preset key that matches an orderby/order pair, or '' if none does.
     *
     * Lets the sort dropdown preselect the option that reflects the instance's configured
     * default (for example "Oldest First" when the widget is set to date/ASC), instead of
     * silently showing whichever option happens to come first.
     *
     * @param  string $orderby
     * @param  string $order
     * @return string
     */
    public static function preset_for(string $orderby, string $order): string
    {
        foreach (self::SORT_PRESETS as $key => $preset) {
            if ($preset['orderby'] === $orderby && $preset['order'] === strtoupper($order)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Returns the sort preset keys and their translated labels for the sort dropdown.
     *
     * @return array<string, string>
     */
    public static function sort_options(): array
    {
        return [
            'newest'     => __('Newest First', 'loop-grid-search'),
            'oldest'     => __('Oldest First', 'loop-grid-search'),
            'title_asc'  => __('Title A–Z', 'loop-grid-search'),
            'title_desc' => __('Title Z–A', 'loop-grid-search'),
        ];
    }

    // ── Parsers ───────────────────────────────────────────────────────────────────

    /**
     * Sanitises and length-limits the keyword.
     *
     * @param  mixed $value
     * @return string
     */
    private static function parse_keyword(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $keyword = sanitize_text_field((string) $value);
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');

        if (mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD_LENGTH);
        }

        return $keyword;
    }

    /**
     * Parses a "YYYY-MM" month selection into a year/month pair.
     *
     * Anything that is not an exactly-formatted, in-range month resolves to
     * [0, 0], i.e. "All Dates".
     *
     * @param  mixed $value
     * @return array{year: int, month: int}
     */
    private static function parse_date(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            return ['year' => 0, 'month' => 0];
        }

        $year  = (int) $matches[1];
        $month = (int) $matches[2];

        if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) {
            return ['year' => 0, 'month' => 0];
        }

        return ['year' => $year, 'month' => $month];
    }

    /**
     * Resolves the selected term for each taxonomy the instance is configured with.
     *
     * Two request spellings are accepted:
     *
     *   terms[<taxonomy>] = <term id>   one entry per dropdown (what the script sends)
     *   term              = <term id>   the original single-dropdown spelling, which names
     *                                   the first configured taxonomy
     *
     * The *configured* taxonomy list drives the loop, not the request, so a request naming a
     * taxonomy this instance does not filter on is ignored outright rather than reaching
     * tax_query — the same posture Config takes for the query scope. The result is keyed in
     * configured order, which is what makes the URL this produces deterministic.
     *
     * @param  array<string, mixed> $raw
     * @param  Config               $config
     * @return array<string, int>
     */
    private static function parse_terms(array $raw, Config $config): array
    {
        $taxonomies = $config->taxonomies();
        $requested  = [];

        if (isset($raw['terms']) && is_array($raw['terms'])) {
            foreach ($raw['terms'] as $taxonomy => $value) {
                $requested[sanitize_key((string) $taxonomy)] = $value;
            }
        }

        if (isset($raw['term']) && [] !== $taxonomies && !isset($requested[$taxonomies[0]])) {
            $requested[$taxonomies[0]] = $raw['term'];
        }

        $terms = [];

        foreach ($taxonomies as $taxonomy) {
            $term_id = self::parse_term($requested[$taxonomy] ?? 0, $taxonomy);

            if ($term_id > 0) {
                $terms[$taxonomy] = $term_id;
            }
        }

        return $terms;
    }

    /**
     * Verifies that a term ID exists inside the configured taxonomy.
     *
     * Returning 0 for anything unverifiable means an invalid term simply shows the
     * unfiltered result set rather than leaking the existence of private terms.
     *
     * @param  mixed  $value
     * @param  string $taxonomy
     * @return int
     */
    private static function parse_term(mixed $value, string $taxonomy): int
    {
        if (!is_scalar($value)) {
            return 0;
        }

        $term_id = absint($value);

        if ($term_id <= 0 || '' === $taxonomy) {
            return 0;
        }

        $term = get_term($term_id, $taxonomy);

        return ($term instanceof \WP_Term) ? $term->term_id : 0;
    }

    /**
     * Restricts the requested sort to the fixed preset map.
     *
     * @param  mixed $value
     * @return string
     */
    private static function parse_sort(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $sort = sanitize_key((string) $value);

        return isset(self::SORT_PRESETS[$sort]) ? $sort : '';
    }
}
