<?php
/**
 * Default result card.
 *
 * Used for every result when no Elementor template is selected. This is the file to copy
 * when you want to customise the markup without an Elementor template.
 *
 * ## Overriding
 *
 * Copy this file to your theme (or child theme) at:
 *
 *     your-theme/loop-grid-search/result-card.php
 *
 * ...and it is used instead, with no plugin changes and no risk of an update overwriting it.
 * Alternatively, point the plugin at any absolute path with the `lgs_result_card_template`
 * filter.
 *
 * ## Available in scope
 *
 * @var \LoopGridSearch\Support\Config $config   The instance configuration (post type, the
 *                                              meta key being searched, taxonomy, labels…).
 * @var \WP_Post|null                  $lgs_post The current result post.
 *
 * The WordPress loop context is already set up for this post — `the_title()`,
 * `get_permalink()`, `the_post_thumbnail()`, `get_the_date()` and ACF's `get_field()` all
 * resolve against it without any extra arguments.
 *
 * @package LoopGridSearch
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

if (!$lgs_post instanceof WP_Post) {
    return;
}

$permalink = (string) get_permalink($lgs_post);
$post_id   = (int) $lgs_post->ID;

/*
 * Prefer the same custom fields the keyword search targets, so what a visitor searched
 * against is what they see in the card. Each searchable meta key is tried in order and the
 * first non-empty one wins; if none has a value, the native WordPress excerpt is used.
 *
 * Read with get_post_meta() rather than ACF's get_field() so the card never depends on ACF
 * being installed. The values were primed in bulk by the query's meta cache, so this loop
 * costs no extra database queries.
 */
$summary = '';

foreach ($config->search_meta_keys() as $search_field) {
    $value = (string) get_post_meta($post_id, $search_field, true);

    if ('' !== trim($value)) {
        $summary = $value;
        break;
    }
}

if ('' === trim($summary)) {
    $summary = (string) get_the_excerpt($lgs_post);
}

// Terms from the configured taxonomy, for a small meta line under the title.
$terms = '' !== $config->taxonomy()
    ? get_the_terms($post_id, $config->taxonomy())
    : [];
?>
<article <?php post_class('ajax-post-search__card'); ?>>

	<?php if (has_post_thumbnail($post_id)) : ?>
		<a class="ajax-post-search__card-thumb" href="<?php echo esc_url($permalink); ?>" tabindex="-1" aria-hidden="true">
			<?php
			/*
			 * Rendered here, server-side, with WordPress's responsive srcset and native
			 * lazy loading. This is what removes the "one REST request per featured image"
			 * pattern entirely — the browser receives the finished <img> tag.
			 */
			the_post_thumbnail('medium_large', [
				'class'   => 'ajax-post-search__card-image',
				'loading' => 'lazy',
				'alt'     => '',
			]);
			?>
		</a>
	<?php endif; ?>

	<div class="ajax-post-search__card-body">

		<h3 class="ajax-post-search__card-title">
			<a class="ajax-post-search__card-link" href="<?php echo esc_url($permalink); ?>">
				<?php echo esc_html(get_the_title($lgs_post)); ?>
			</a>
		</h3>

		<p class="ajax-post-search__card-meta">
			<time datetime="<?php echo esc_attr((string) get_the_date('c', $lgs_post)); ?>">
				<?php echo esc_html((string) get_the_date('', $lgs_post)); ?>
			</time>

			<?php if (!is_wp_error($terms) && !empty($terms)) : ?>
				<span class="ajax-post-search__card-terms">
					<?php
					$names = array_map(
						static fn(WP_Term $term): string => $term->name,
						is_array($terms) ? $terms : []
					);

					echo esc_html(implode(', ', $names));
					?>
				</span>
			<?php endif; ?>
		</p>

		<?php if ('' !== trim($summary)) : ?>
			<div class="ajax-post-search__card-excerpt">
				<?php
				// wp_trim_words() strips tags itself, so an ACF wysiwyg or textarea value
				// arrives here as plain text ready for esc_html().
				echo esc_html(wp_trim_words($summary, 28));
				?>
			</div>
		<?php endif; ?>

		<a class="ajax-post-search__card-more" href="<?php echo esc_url($permalink); ?>">
			<?php echo esc_html__('Read more', 'loop-grid-search'); ?>
			<span class="screen-reader-text"><?php echo esc_html(get_the_title($lgs_post)); ?></span>
		</a>

	</div>

</article>
