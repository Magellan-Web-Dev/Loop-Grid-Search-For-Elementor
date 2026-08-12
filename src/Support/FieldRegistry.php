<?php

declare(strict_types=1);

namespace LoopGridSearch\Support;

if (!defined('ABSPATH')) exit;

/**
 * Discovers the fields a keyword search can usefully match against.
 *
 * Two sources:
 *
 *  • **Native post columns** — post_title, post_excerpt, post_content. These are real
 *    columns on wp_posts, so they are matched directly in the WHERE clause.
 *
 *  • **ACF fields** — discovered from the registered field groups so the Elementor panel
 *    can offer a real picker (labels, field group / post type context) instead of asking an
 *    author to type a meta key from memory.
 *
 * ACF is used here for *discovery only*, in the editor. The search query itself always
 * reads post meta directly with $wpdb, so a site with no ACF installed still works — meta
 * keys can be entered by hand in the widget's "Additional Meta Keys" control or in the
 * shortcode's `acf_search_field` attribute.
 *
 * ## Which ACF field types are offered
 *
 * Only field types whose stored value *is* the searchable text. Types that store a
 * serialized array or a foreign ID (relationship, post object, image, gallery, repeater,
 * group, flexible content, link, taxonomy, user) are deliberately excluded: a LIKE against
 * their raw storage either never matches or matches nonsense, and offering them in the
 * picker would be a promise the search cannot keep.
 */
final class FieldRegistry
{
    /**
     * Native wp_posts columns that may be searched.
     *
     * This is the single source of truth for the allowlist — {@see Config} validates
     * against it and {@see \LoopGridSearch\Query\KeywordSearch} re-checks it before any
     * column name is interpolated into SQL.
     *
     * @var list<string>
     */
    public const SEARCHABLE_COLUMNS = ['post_title', 'post_excerpt', 'post_content'];

    /**
     * ACF field types whose stored meta value is plain, searchable text.
     *
     * @var list<string>
     */
    private const SEARCHABLE_ACF_TYPES = [
        'text',
        'textarea',
        'wysiwyg',
        'email',
        'url',
        'number',
        'range',
        'select',
        'radio',
        'button_group',
    ];

    /**
     * Per-request cache of discovered ACF fields.
     *
     * @var array<string, array{label: string, location_label: string, type: string}>|null
     */
    private static ?array $acf_cache = null;

    /**
     * Returns the native column options for the "Search In" control.
     *
     * @return array<string, string> Column name => translated label.
     */
    public static function get_column_options(): array
    {
        return [
            'post_title'   => __('Post Title', 'loop-grid-search'),
            'post_excerpt' => __('Post Excerpt', 'loop-grid-search'),
            'post_content' => __('Post Content', 'loop-grid-search'),
        ];
    }

    /**
     * Returns discovered ACF fields as flat Select2 options.
     *
     * Elementor's Select2 control renders `data.options` as a flat list — unlike the plain
     * Select control it has no `groups` support — so the field group / post type context is
     * folded into the label instead:
     *
     *     excerpt  =>  "Excerpt — excerpt (Resource)"
     *
     * @param  string $post_type Post type the instance queries. Fields whose group is bound
     *                           to a different post type are listed last rather than hidden,
     *                           because ACF location rules can be more complex than a single
     *                           post_type rule.
     * @return array<string, string> Meta key => display label.
     */
    public static function get_meta_field_options(string $post_type = ''): array
    {
        $relevant = [];
        $other    = [];

        $post_type_label = '' !== $post_type ? self::post_type_label($post_type) : '';

        foreach (self::get_acf_fields() as $key => $data) {
            $label = sprintf(
                /* translators: 1: field label, 2: meta key, 3: field group / post type context */
                _x('%1$s — %2$s (%3$s)', 'ACF field picker option', 'loop-grid-search'),
                $data['label'],
                $key,
                $data['location_label']
            );

            if ('' !== $post_type_label && $data['location_label'] === $post_type_label) {
                $relevant[$key] = $label;
            } else {
                $other[$key] = $label;
            }
        }

        $options = $relevant + $other;

        /**
         * Filters the ACF fields offered in the search-field picker.
         *
         * Use this to expose a field type this plugin excludes by default, or to hide one.
         *
         * @param array<string, string> $options   Meta key => display label.
         * @param string                $post_type Post type the instance queries.
         */
        return (array) apply_filters('lgs_search_field_options', $options, $post_type);
    }

    /**
     * Returns true when at least one ACF field was discovered.
     *
     * Used to adapt the widget control's description text.
     *
     * @return bool
     */
    public static function has_acf_fields(): bool
    {
        return [] !== self::get_acf_fields();
    }

    /**
     * Returns every searchable ACF field, keyed by meta key.
     *
     * Cached for the lifetime of the request; ACF field group lookups are not free and the
     * widget panel asks for this list on every control registration.
     *
     * @return array<string, array{label: string, location_label: string, type: string}>
     */
    private static function get_acf_fields(): array
    {
        if (null !== self::$acf_cache) {
            return self::$acf_cache;
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            self::$acf_cache = [];

            return [];
        }

        $fields = [];

        foreach ((array) acf_get_field_groups() as $group) {
            if (!is_array($group)) {
                continue;
            }

            self::collect_fields(
                (array) acf_get_fields($group),
                self::resolve_group_location_label($group),
                $fields
            );
        }

        self::$acf_cache = $fields;

        return $fields;
    }

    /**
     * Walks an ACF field list, recursing into layout-only containers.
     *
     * Group and repeater sub-fields are skipped: ACF stores those under generated keys
     * (`parent_0_child`), so a fixed meta key cannot address them reliably. Tab, accordion
     * and message fields hold no value at all.
     *
     * @param  array<int, mixed>                                                          $fields
     * @param  string                                                                     $location_label
     * @param  array<string, array{label: string, location_label: string, type: string}>  $collected Accumulator, by reference.
     * @return void
     */
    private static function collect_fields(array $fields, string $location_label, array &$collected): void
    {
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['name']) || empty($field['type'])) {
                continue;
            }

            $type = (string) $field['type'];

            // Layout-only containers hold no value but may wrap real fields.
            if ('group' === $type || 'repeater' === $type || 'flexible_content' === $type) {
                continue;
            }

            if (!in_array($type, self::SEARCHABLE_ACF_TYPES, true)) {
                continue;
            }

            $key = (string) $field['name'];

            // Must be addressable as a meta key by the same rules Config enforces.
            if (!preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $key)) {
                continue;
            }

            $collected[$key] = [
                'label'          => (string) ($field['label'] ?: $key),
                'location_label' => $location_label,
                'type'           => $type,
            ];
        }
    }

    /**
     * Resolves a human-readable context label for an ACF field group.
     *
     * Scans the group's location rules for `post_type ==` conditions and returns the
     * singular post type label(s). Falls back to the group title when no post-type rule is
     * found (options pages, user forms, taxonomy term forms).
     *
     * @param  array<string, mixed> $group
     * @return string
     */
    private static function resolve_group_location_label(array $group): string
    {
        $post_types = [];

        foreach ((array) ($group['location'] ?? []) as $rule_group) {
            foreach ((array) $rule_group as $rule) {
                if (
                    isset($rule['param'], $rule['operator'], $rule['value']) &&
                    'post_type' === $rule['param'] &&
                    '==' === $rule['operator'] &&
                    '' !== (string) $rule['value']
                ) {
                    $post_types[self::post_type_label((string) $rule['value'])] = true;
                }
            }
        }

        if (!empty($post_types)) {
            return implode(', ', array_keys($post_types));
        }

        return (string) ($group['title'] ?? __('Custom Fields', 'loop-grid-search'));
    }

    /**
     * Returns a post type's singular label, falling back to a humanised slug.
     *
     * `labels` is always populated by register_post_type() in practice, but it is checked
     * rather than assumed: this runs against whatever object a third-party registration
     * produced, and a missing property here would surface as a PHP warning inside the
     * Elementor editor panel.
     *
     * @param  string $post_type
     * @return string
     */
    private static function post_type_label(string $post_type): string
    {
        $object = get_post_type_object($post_type);

        if ($object instanceof \WP_Post_Type && !empty($object->labels->singular_name)) {
            return (string) $object->labels->singular_name;
        }

        return ucwords(str_replace(['_', '-'], ' ', $post_type));
    }
}
