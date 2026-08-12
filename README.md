# Loop Grid Search for Elementor

AJAX keyword / date / taxonomy search and filtering for any post type, rendered **server-side** through an Elementor loop template. Ships as a drag-and-drop **Loop Grid Search** Elementor widget and as a shortcode.

- **Requires:** WordPress 6.0+, PHP 8.1+
- **Recommended:** Elementor (for the widget and template rendering)
- **Optional:** Elementor Pro (only to *author* Loop Item templates), ACF (only to *manage* the custom field)
- **Tested with:** WordPress 6.9.5, Elementor 4.1.4, Elementor Pro 4.1.2, ACF 6.8.5

---

## Table of contents

- [What it does](#what-it-does)
- [Quick start](#quick-start)
- [Elementor setup](#elementor-setup)
- [Shortcode reference](#shortcode-reference)
- [Pagination](#pagination)
- [How the title OR custom field search works](#how-the-title-or-custom-field-search-works)
- [Combining filters](#combining-filters)
- [Changing the post type, custom field, or taxonomy later](#changing-the-post-type-custom-field-or-taxonomy-later)
- [Result rendering](#result-rendering)
- [Architecture](#architecture)
- [The AJAX endpoint](#the-ajax-endpoint)
- [Security model](#security-model)
- [Front-end behaviour](#front-end-behaviour)
- [CSS reference](#css-reference)
- [Filter reference](#filter-reference)
- [Performance notes](#performance-notes)
- [Compatibility notes and known limitations](#compatibility-notes-and-known-limitations)
- [Updates](#updates)
- [Changelog](#changelog)

---

## What it does

One interface with four controls and an AJAX-updated result grid:

| Control | Behaviour |
|---|---|
| **Keyword** | Live search, debounced ~400 ms, Enter searches immediately. Matches **any combination** of post title / excerpt / content **OR** any number of custom fields — all OR-ed together. Post Title is on by default. |
| **Month / Year** | Built from the dates that actually exist for the selected post type. Nothing is hard-coded. Newest month first. |
| **Taxonomy term** | Any public taxonomy — `post_tag` by default, or a custom one such as `resource_type`. |
| **Clear Filters** | Resets keyword, date, term, sort and pagination, then reloads the unfiltered first page. |
| **Sort** *(optional)* | Newest / Oldest / Title A–Z / Title Z–A. Off by default. |
| **Pagination** | Previous / Next, with or without numbered pages. Button text is configurable and the number list truncates to a sliding window so it never runs off the page. |

The page never reloads — for searching, filtering, clearing, sorting or paging. Every response is finished HTML rendered on the server in a single request.

---

## Quick start

1. Activate the plugin.
2. Edit a page with Elementor, search the widget panel for **Loop Grid Search**, and drag it in.
3. Set **Post Type**, **Also Search Custom Field** (default `excerpt`), and **Taxonomy** (default `post_tag`).
4. Optionally pick a **Result Template** under *Content ▸ Results* to render each card with Elementor.
5. Publish.

Or, anywhere a shortcode works:

```
[ajax_post_search]
```

---

## Elementor setup

### Using the widget

The widget appears in the panel under a **Loop Grid Search** category (and in **General**). Its controls:

**Content ▸ Query**

| Control | Default | Notes |
|---|---|---|
| Post Type | `post` | Public post types only; `attachment` excluded. |
| Results Per Page | `9` | 1–100. |
| Default Order By | Published Date | Date / Title / Last Modified / Menu Order / Post ID. |
| Default Direction | Descending | This is the order **Clear Filters** returns to. |

**Content ▸ Keyword Search**

| Control | Type | Default | Notes |
|---|---|---|---|
| Show Keyword Field | Toggle | On | |
| **Search In** | Multi-select | `Post Title` | Built-in post fields: **Post Title**, **Post Excerpt**, **Post Content**. Pick any combination. |
| **Also Search Custom Fields** | Multi-select | — | **ACF fields, discovered automatically**, listed as `Label — meta_key (Post Type)`. Only text-style types appear. |
| **Additional Meta Keys** | Text | `excerpt` | Comma-separated meta keys for fields ACF does not manage, or when ACF is not installed. |
| Field Label / Placeholder | Text | Search / Search… | |

All three search controls are OR-ed together. Out of the box that is **post title OR the `excerpt` meta field**, matching the plugin's original behaviour.

The **Also Search Custom Fields** picker only lists ACF field types whose stored value *is* the searchable text — Text, Textarea, WYSIWYG, Email, URL, Number, Range, Select, Radio, Button Group. Types that store a serialized array or a foreign ID (Relationship, Post Object, Image, Gallery, Repeater, Group, Flexible Content, Link, Taxonomy, User) are deliberately excluded: a `LIKE` against their raw storage either never matches or matches nonsense, and listing them would be a promise the search cannot keep. Use the `lgs_search_field_options` filter to override that judgement either way.

If no ACF fields are detected, the control says so and points you at **Additional Meta Keys** instead.

> **ACF is not required at query time.** It is used only to *discover* field names for this picker. The search itself reads post meta directly with `$wpdb`, so a site with no ACF works fine — type the meta keys by hand.

**Content ▸ Filters** — visibility toggles and labels for the Month/Year filter, the taxonomy filter, the optional sort dropdown, and the Clear button.

**Content ▸ Results**

| Control | Default | Notes |
|---|---|---|
| Result Template | Built-in PHP card | Grouped dropdown of your Elementor library, **Loop Item templates first**. |
| No Results Message | "No results found matching your search." | |

**Content ▸ Pagination**

| Control | Type | Default | Notes |
|---|---|---|---|
| **Pagination Style** | Select | Previous / Next + Page Numbers | The alternative, **Previous / Next Only**, shows a "Page 2 of 5" counter instead of numbered buttons. |
| **Previous Button Text** | Text | Previous | |
| **Next Button Text** | Text | Next | |
| **Max Page Numbers Shown** | Number | `6` | 3–50. Beyond this many pages the list truncates with an ellipsis. Only shown in the numbered style. |

See [Pagination](#pagination) below for exactly how truncation behaves.

**Style ▸ Results Grid** — responsive **Columns** and **Gap**. These write CSS custom properties, so they cascade normally.

### Building the result template

1. **Templates ▸ Theme Builder ▸ Loop Item ▸ Add New** (Elementor Pro), or **Templates ▸ Saved Templates** for a plain section/container.
2. Design one card using dynamic widgets — Post Title, Featured Image, Post Excerpt, ACF field, Post URL, taxonomy terms. They all resolve against the correct result post; see [Result rendering](#result-rendering).
3. Save, then select it in the widget's **Result Template** dropdown (or pass its ID as `elementor_template_id` to the shortcode).

A Loop Item template is the natural choice, but any Elementor-built template works — the plugin only asks that the post was built with Elementor.

### Using the shortcode inside Elementor

Drop Elementor's **Shortcode** widget onto the page and put `[ajax_post_search …]` inside it. Everything behaves identically; only the Style-tab grid controls are unavailable (use the `columns` and `gap` attributes instead).

---

## Shortcode reference

Both tags are identical:

```
[loop_grid_search]
[ajax_post_search]
```

Full example:

```
[ajax_post_search
    post_type="post"
    search_in="post_title,post_excerpt"
    acf_search_field="excerpt,summary"
    taxonomy="post_tag"
    posts_per_page="9"
    elementor_template_id="1234"
    columns="3"
    gap="24"
    show_sort="yes"]
```

Both list attributes accept a single value too, so the original one-field form still works unchanged:

```
[ajax_post_search post_type="post" acf_search_field="excerpt" taxonomy="post_tag" posts_per_page="9"]
```

### Attributes

| Attribute | Default | Description |
|---|---|---|
| `post_type` | `post` | Any public post type. |
| `search_in` | `post_title` | Comma-separated built-in fields: `post_title`, `post_excerpt`, `post_content`. (`search_columns` is a synonym.) |
| `acf_search_field` | `excerpt` | Comma-separated meta keys OR-ed with the fields above. Empty = built-in fields only. (`meta_search_field` and `search_meta_keys` are synonyms.) |
| `taxonomy` | `post_tag` | Any public taxonomy. Empty disables the term filter. |
| `posts_per_page` | `9` | Clamped to 1–100. |
| `elementor_template_id` | — | Elementor template post ID. Omit to use the PHP card. (`template_id` is a synonym.) |
| `orderby` | `date` | `date`, `title`, `modified`, `menu_order`, `ID`. |
| `order` | `DESC` | `DESC` or `ASC`. |
| `columns` | `3` | 1–8. |
| `gap` | `24` | Grid gap in pixels, 0–200. |
| `pagination_mode` | `numbers` | `numbers` = Previous/Next + page numbers. `prev_next` = Previous/Next + a page counter. |
| `pagination_max_numbers` | `6` | 3–50. Max numbered buttons before the list truncates with an ellipsis. |
| `pagination_prev_label` | `Previous` | Previous button text. |
| `pagination_next_label` | `Next` | Next button text. |
| `pagination_numbers` | — | Legacy boolean spelling. `no` is equivalent to `pagination_mode="prev_next"`. |
| `show_keyword` | `yes` | |
| `show_date` | `yes` | |
| `show_taxonomy` | `yes` | |
| `show_sort` | `no` | |
| `show_clear` | `yes` | |
| `keyword_label` | Search | |
| `keyword_placeholder` | Search… | |
| `date_label` | Date | |
| `date_all_label` | All Dates | |
| `taxonomy_label` | Tag | |
| `taxonomy_all_label` | All Tags | |
| `sort_label` | Sort By | |
| `clear_label` | Clear Filters | |
| `no_results_text` | No results found matching your search. | |

Invalid values degrade to the default rather than erroring — a typo in a shortcode never takes a page down.

---

## How the title OR custom field search works

### Why the obvious approach is wrong

The tempting version is `s` plus a `meta_query`:

```php
// DOES NOT WORK — requires BOTH to match.
new WP_Query([
    's'          => 'solar',
    'meta_query' => [[ 'key' => 'excerpt', 'compare' => 'LIKE', 'value' => 'solar' ]],
]);
```

WordPress builds the search clause and the meta clause as two independent WHERE fragments and joins them with `AND`:

```sql
AND ( post_title LIKE '%solar%' OR post_excerpt LIKE … OR post_content LIKE … )
AND ( postmeta.meta_key = 'excerpt' AND postmeta.meta_value LIKE '%solar%' )
```

A post whose *title* contains "solar" but whose ACF field does not is therefore excluded. No `relation` spans the search clause and the meta clause, so the two can never be OR-ed through the public API.

### What this plugin does instead

`s` is never set. The keyword travels on a private query var and a tightly scoped `posts_clauses` filter appends one self-contained group to the WHERE clause. With the default configuration (title + the `excerpt` field):

```sql
AND (
  ( wp_posts.post_title LIKE '%solar%'
    OR EXISTS ( SELECT 1 FROM wp_postmeta AS lgs_meta
                WHERE lgs_meta.post_id  = wp_posts.ID
                  AND lgs_meta.meta_key IN ('excerpt')
                  AND lgs_meta.meta_value LIKE '%solar%' ) )
)
```

Searching `solar` returns a post when **either** side matches. Neither side is required.

Every field you select simply joins the same `OR` chain. Title + excerpt + content, plus the `excerpt` and `summary` meta fields:

```sql
AND (
  ( wp_posts.post_title   LIKE '%solar%'
    OR wp_posts.post_excerpt LIKE '%solar%'
    OR wp_posts.post_content LIKE '%solar%'
    OR EXISTS ( SELECT 1 FROM wp_postmeta AS lgs_meta
                WHERE lgs_meta.post_id  = wp_posts.ID
                  AND lgs_meta.meta_key IN ('excerpt', 'summary')
                  AND lgs_meta.meta_value LIKE '%solar%' ) )
)
```

Five deliberate design points:

**`EXISTS`, not `JOIN`.** A `LEFT JOIN` on `wp_postmeta` duplicates a post row per matching meta row, which would need a `GROUP BY` to de-duplicate and would distort `SQL_CALC_FOUND_ROWS` (and therefore the page count). A correlated `EXISTS` subquery returns each post exactly once and resolves through `wp_postmeta`'s `post_id` index.

**One `EXISTS` for all meta keys.** However many custom fields you select, they become a single `meta_key IN (…)` subquery rather than one subquery per field. Adding fields is close to free: the correlated lookup still visits only the current post's meta rows.

**Multi-word keywords are AND across terms, OR across fields.** Searching `solar panels` requires *both* words to appear, and each word may appear in *either* the title or the custom field. This mirrors WordPress core's own multi-term search behaviour and reduces to the single-field OR case for a one-word query. Terms are capped at 10.

**The filter's lifetime is one query.** `KeywordSearch::run()` adds the filter, runs exactly one `WP_Query`, and removes the filter in a `finally` block — so it is gone even if rendering throws. The callback also verifies the query carries the plugin's private query var, so any unrelated query running inside that window is returned byte-identical.

**Every value is bound; column names are allowlisted.** The keyword goes through `$wpdb->esc_like()` and is bound with `$wpdb->prepare()`; meta keys are bound as parameters too. Column names *cannot* be bound as SQL parameters, so they are re-checked against `FieldRegistry::SEARCHABLE_COLUMNS` inside `KeywordSearch` itself — independently of `Config` — before being interpolated. `post_password` and anything else is dropped there even if it somehow reached that far. Otherwise only the `$wpdb->posts` / `$wpdb->postmeta` table names are interpolated.

See [`src/Query/KeywordSearch.php`](src/Query/KeywordSearch.php) and [`src/Support/FieldRegistry.php`](src/Support/FieldRegistry.php).

---

## Combining filters

All filters compose with `AND`:

```
Keyword: solar     Month: August 2026     Tag: Commercial
```

becomes

```sql
(   wp_posts.post_title LIKE '%solar%'
 OR EXISTS ( postmeta row: meta_key='excerpt' AND meta_value LIKE '%solar%' ) )
AND  post_date is in 2026-08                    -- WP_Query date_query
AND  post has term "Commercial" in post_tag     -- WP_Query tax_query
```

Changing **any** filter resets pagination to page 1. Changing the **page** preserves the keyword, month/year, term and sort.

---

## Changing the post type, custom field, or taxonomy later

All three are configuration, in one place per instance.

**Widget:** *Content ▸ Query ▸ Post Type*, *Content ▸ Keyword Search ▸ Search In / Also Search Custom Fields / Additional Meta Keys*, *Content ▸ Filters ▸ Taxonomy*.

**Shortcode:**

```
[ajax_post_search post_type="resource" acf_search_field="summary,body" taxonomy="resource_type"]
```

Nothing else has to change:

- The **Month / Year** options are generated from the new post type's actual publish dates.
- The **taxonomy dropdown** is generated from the new taxonomy's terms.
- The **keyword search** binds the new meta keys as query parameters.
- The **ACF field picker** re-discovers fields and promotes the ones bound to the new post type to the top of the list.
- The **built-in card** tries each searchable meta key in order and shows the first one with a value as its summary, falling back to the WordPress excerpt.

Three rules worth knowing:

- Only **public** post types and **public** taxonomies are accepted. An unregistered or private slug falls back to the default.
- A custom field is a **meta key**, not an ACF field *key* (`field_abc123`). For an ACF field named `excerpt`, use `excerpt`.
- At most **20** meta keys per instance, so a crafted payload cannot generate an unbounded `IN()` clause.

To search built-in fields only, clear the custom fields:

```
[ajax_post_search acf_search_field=""]
```

To search custom fields only, clear the built-in ones:

```
[ajax_post_search search_in="" acf_search_field="excerpt,summary"]
```

Clearing **both** falls back to Post Title — a keyword box with nothing behind it reads as a broken search rather than a configuration choice.

---

## Pagination

### Two styles

| Style | Renders |
|---|---|
| **Previous / Next + Page Numbers** *(default)* | `‹ Previous  1 2 3 4 5 6 …  Next ›` |
| **Previous / Next Only** | `‹ Previous   Page 2 of 5   Next ›` |

Both styles share the same Previous and Next buttons, whose text you set with **Previous Button Text** / **Next Button Text** (or the `pagination_prev_label` / `pagination_next_label` shortcode attributes).

Previous is genuinely `disabled` on page 1 and Next on the last page — not merely styled to look inert — and the current page carries `aria-current="page"`.

### Number truncation

**Max Page Numbers Shown** (default `6`) caps how many numbered buttons are on screen at once. The visible window is **centred on the current page** and clamped at both ends, with an ellipsis marking each truncated side. The button count never grows with the result set.

With 9 pages of results and a limit of 6:

| Current page | Rendered |
|---|---|
| 1 | `1 2 3 4 5 6 …` |
| 2 | `1 2 3 4 5 6 …` |
| 3 | `1 2 3 4 5 6 …` |
| 4 | `… 2 3 4 5 6 7 …` |
| 5 | `… 3 4 5 6 7 8 …` |
| 6 | `… 4 5 6 7 8 9` |
| 7–9 | `… 4 5 6 7 8 9` |

The window slides once the current page moves past the centre, so there is always roughly equal context on either side of where the visitor is. When the total page count is at or under the limit, every page is listed and no ellipsis appears.

The ellipsis is a non-interactive `<span aria-hidden="true">`; Previous / Next and the visible numbers are the navigation. This keeps the control's tab order short and predictable.

Guaranteed for every combination of limit, total pages and current page:

- exactly `min(limit, total)` numbers are shown
- the current page is always among them
- the numbers are always contiguous and in range
- a leading ellipsis appears **exactly when** pages are hidden before the window, and a trailing one **exactly when** pages are hidden after it

### Truncating in the shortcode

```
[ajax_post_search
    pagination_mode="numbers"
    pagination_max_numbers="6"
    pagination_prev_label="‹ Newer"
    pagination_next_label="Older ›"]
```

Previous / Next only:

```
[ajax_post_search pagination_mode="prev_next"]
```

---

## Result rendering

```
Shortcode / widget config
        ↓
AJAX request (one)
        ↓
WP_Query (paginated)
        ↓
Server-side rendering, per result
        ↓
Elementor template  ─or─  PHP card
        ↓
Finished HTML in one JSON response
```

### With an Elementor template

Each result is rendered through Elementor's public, documented render API:

```php
\Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, false );
```

This is the same call behind Elementor Pro's own **Template** widget and the `[elementor-template]` shortcode — verified against the installed Elementor 4.1.4 source. The plugin uses **no** private or minified Elementor JavaScript, and **never** touches the undocumented `/wp-json/elementor-pro/v1/refresh-loop` endpoint.

### WordPress post context

`WP_Query::the_post()` sets `$GLOBALS['post']` and calls `setup_postdata()` before each card renders. That is exactly the context Elementor dynamic tags and widgets expect, and the same mechanism Elementor Pro's Loop Grid relies on — so **Post Title, Featured Image, Post Excerpt, ACF fields, Post URL and taxonomy terms all resolve against the current result post**.

Because a shortcode normally runs *inside* the page's main loop, the surrounding `$GLOBALS['post']` is captured before the loop and restored afterwards, on top of calling `wp_reset_postdata()` — even if rendering throws. The search query is a secondary `WP_Query`; the main query is never touched and no conditional tag is affected.

### Rendering a template once per post: the two real costs

Both are called out in code comments as well.

**CSS duplication.** Inside an AJAX request Elementor forces its `$with_css` flag on and prints the template stylesheet *inline*, so a naive loop would emit the same `<style>` block once per card. The plugin uses Elementor's public `elementor/frontend/builder_content/before_print_css` filter to let only the **first** card print it. Each AJAX response stays self-contained without repeating kilobytes of CSS.

**Per-post render cost.** Each call re-walks the template's element tree. This is the same work Elementor Pro's Loop Grid does per item, and it is bounded by `posts_per_page` — never by the total result count.

There is also a deliberate benefit to rendering **page 1 server-side on the initial page load**: it lets Elementor's conditional asset loader see every widget the loop template uses during the normal page lifecycle, so those widgets' CSS and JS land in `<head>`/footer as usual and are already present when later pages arrive over AJAX.

### Without an Elementor template

The built-in PHP card renders a featured image (with WordPress's responsive `srcset` and native lazy loading), the linked title, the date, the configured taxonomy's terms, a trimmed summary from the searched custom field (falling back to the post excerpt), and a "Read more" link.

**Customise it by copying one file into your theme:**

```
your-theme/loop-grid-search/result-card.php
```

The plugin's copy at [`templates/result-card.php`](templates/result-card.php) is the starting point. It receives `$config` (the instance configuration) and `$lgs_post` (the current post), with the loop context already set up. Or point anywhere with the `lgs_result_card_template` filter.

---

## Architecture

```
loop-grid-search-for-elementor/
├── loop-grid-search-for-elementor.php   Main file: PHP 8.1 guard, constants, autoloader bootstrap
├── src/
│   ├── Autoloader.php                   PSR-4 autoloader (LoopGridSearch\ → src/), no Composer
│   ├── Plugin.php                       Singleton composition root; boots all components
│   ├── DependencyChecker.php             Elementor / Elementor Pro / ACF detection + notices
│   ├── Support/
│   │   ├── Config.php                   Validated + HMAC-signed instance configuration
│   │   ├── Criteria.php                 Validated visitor filters (keyword, date, term, page, sort)
│   │   ├── FieldRegistry.php            Searchable column allowlist + ACF field discovery
│   │   ├── DateOptions.php              Month/Year options via one DISTINCT query
│   │   └── TaxonomyOptions.php          Term options via get_terms()
│   ├── Query/
│   │   ├── QueryBuilder.php             Config + Criteria → WP_Query args; runs and page-clamps
│   │   └── KeywordSearch.php            The title-OR-meta posts_clauses filter
│   ├── Render/
│   │   ├── InterfaceRenderer.php        Composes the whole instance (shared by both entry points)
│   │   ├── FiltersRenderer.php          Keyword field, date select, term select, sort, clear
│   │   ├── ResultsRenderer.php          Result loop, post context, Elementor / PHP card dispatch
│   │   └── PaginationRenderer.php       Prev / numbers / Next with disabled + current states
│   ├── Ajax/
│   │   └── SearchEndpoint.php           wp_ajax_lgs_search / wp_ajax_nopriv_lgs_search
│   ├── Frontend/
│   │   └── AssetManager.php             Registers + conditionally enqueues the one CSS and one JS
│   ├── Shortcode/
│   │   └── SearchShortcode.php          [loop_grid_search] and [ajax_post_search]
│   ├── Widget/
│   │   ├── WidgetManager.php            Panel category + widget registration
│   │   └── LoopGridSearchWidget.php     The "Loop Grid Search" Elementor widget
│   └── Updates/
│       └── GitHubUpdater.php            GitHub release checks and update package handling
├── templates/
│   └── result-card.php                  Default PHP card; theme-overridable
├── assets/
│   ├── css/loop-grid-search.css         Layout, loading state, a11y helpers — nothing opinionated
│   └── js/loop-grid-search.js           Debounce, AbortController, multi-instance controller
└── stubs/
    └── elementor-stubs.php              IDE-only Elementor stubs; never loaded at runtime
```

Everything lives under the `LoopGridSearch\` namespace, constants are prefixed `LGS_`, hooks and filters are prefixed `lgs_`, and CSS classes use the `ajax-post-search` block. No global functions are declared.

The shortcode and the widget are both thin adapters over the same `Config` → `InterfaceRenderer` path, so the two entry points produce identical markup and cannot drift apart.

---

## The AJAX endpoint

WordPress-native admin-ajax, registered for both logged-in and anonymous visitors:

```
POST /wp-admin/admin-ajax.php     action=lgs_search
```

### Why admin-ajax rather than a REST route

Both are viable. admin-ajax is the better fit here:

- The response is **HTML**, not a resource representation. There is no entity to model, no collection to expose, and no schema worth publishing — REST buys nothing.
- `wp_send_json_success()` / `wp_send_json_error()` already produce the needed envelope with correct headers and status codes.
- `admin-ajax.php` is `nocache_headers()`-guarded and treated as uncacheable by every common page-cache and CDN configuration. A public `GET` REST route is exactly the kind of URL a CDN **will** cache — which would silently serve one visitor's filtered results to another.
- Nonce handling is one call, with no `permission_callback` ambiguity for what is deliberately a public, read-only endpoint.

### Request

| Field | Notes |
|---|---|
| `action` | `lgs_search` |
| `nonce` | `lgs_search` nonce |
| `keyword` | Free text; sanitised and length-capped at 200 characters |
| `date` | `YYYY-MM`, or empty |
| `term` | Term ID, or empty |
| `sort` | `newest` \| `oldest` \| `title_asc` \| `title_desc`, or empty |
| `paged` | 1-based page number |
| `config[…]` | The server-signed instance configuration, echoed back verbatim |

### Response

```json
{
  "success": true,
  "data": {
    "html":            "<div class=\"ajax-post-search__list\">…</div>",
    "pagination_html": "<nav class=\"ajax-post-search__pagination\">…</nav>",
    "current_page":    1,
    "total_pages":     4,
    "total_results":   31
  }
}
```

Errors use `wp_send_json_error()` with a status code and a human-readable message:

| Code | Status | Meaning |
|---|---|---|
| `lgs_invalid_nonce` | 403 | Nonce missing or expired. |
| `lgs_invalid_config` | 400 | Configuration signature failed to verify, or a signed value no longer passes validation. |

---

## Security model

The endpoint is public because it only ever exposes **published** posts in a **public** post type — the same data the site's own archive pages show. Every request is nonetheless fully validated.

### 1. Nonce

`wp_verify_nonce()` against the `lgs_search` action, on every request, before anything else runs.

### 2. Configuration integrity — the query scope is never taken on trust

The AJAX endpoint has to know which post type, meta key, taxonomy, page size and template to use. It does **not** believe the browser about any of them.

At render time the server validates each value and then signs the set with `wp_hash()` (HMAC keyed by the site's `wp-config.php` salts, which are never exposed to the client). The browser echoes the values plus the signature back; `Config::from_client_array()` recomputes the signature and rejects anything that does not verify with `hash_equals()`. Because the signing key never leaves the server, **any accepted payload is provably one this site emitted**.

An attacker therefore cannot point the endpoint at a private post type, add `post_password` to the searched columns, read an arbitrary meta key (`_wp_secret`, a password hash, an API token), swap in an unrelated Elementor template, or raise `posts_per_page` to exhaust memory.

Only the ten fields the endpoint actually needs are signed and transmitted. Labels, visibility toggles and layout values stay on the server entirely.

**Canonical-form check.** Before the signature is compared, the received values must already be in canonical form. Some tampering would otherwise survive normalisation without changing the signed string — appending `post_password` to `search_columns`, for instance, is dropped by the column allowlist, so the payload would still verify and simply be stripped. That is harmless, but "sometimes silently corrected, sometimes rejected" is a confusing property to reason about. Requiring canonical form makes the guarantee uniform and easy to state: **an accepted payload is byte-identical to one this server emitted.** Non-canonical list order, duplicated keys and zero-padded numbers are all rejected too.

After the signature verifies, every value is **re-validated against the live WordPress registry** anyway — the post type must still exist and be public, the taxonomy must still exist and be public — because a site's configuration can change after a page (or its cached HTML) was generated.

### 3. Visitor input

| Input | Treatment |
|---|---|
| `keyword` | `sanitize_text_field()`, whitespace collapsed, capped at 200 chars and 10 terms; `$wpdb->esc_like()` + `$wpdb->prepare()` |
| `date` | Must match `^\d{4}-\d{2}$` exactly, year 1900–2200, month 1–12; anything else means "All Dates" |
| `term` | `absint()`, then verified to be a real term **in the configured taxonomy** |
| `paged` | `absint()`, floored at 1, then clamped to the real page count |
| `sort` | Restricted to a fixed four-entry preset map; no arbitrary `orderby` can reach `WP_Query` |

No SQL fragment, meta key, `orderby` value or post type from a request ever reaches a query without passing one of these gates.

### 4. Output

All rendered values pass through `esc_html()`, `esc_attr()`, `esc_url()` or `wp_kses_post()`. The per-instance configuration is embedded in an inert `<script type="application/json">` block, JSON-encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` so it cannot break out of the element.

---

## Front-end behaviour

Modern vanilla JavaScript — `fetch()` and `AbortController`, no jQuery dependency. One shared script file serves any number of instances.

### Multiple instances

Each `.ajax-post-search` root gets its own controller with its own state, in-flight request and abort controller, discovered by scanning the DOM. Nothing per-instance is printed as JavaScript: each instance's configuration lives in its own inert JSON block. A `MutationObserver` picks up instances added after first paint — which covers the Elementor editor re-rendering a widget on every setting change, without coupling to Elementor's frontend JS internals.

### Race conditions

Stale-response protection is belt-and-braces:

1. Each new request **aborts** the previous one via `AbortController`, so the browser stops waiting on it at the network layer.
2. Every request also carries a **monotonically increasing token**. A response whose token is not the latest is discarded — covering the window where a request had already resolved before `abort()` could take effect.

Typing `s → so → sol → sola → solar` therefore always paints the results for `solar`, whatever order the five responses arrive in.

### Loading state

While a request is in flight:

- `ajax-post-search--loading` is added to the instance root.
- `aria-busy="true"` is set on the results region.
- Existing results **stay in the DOM and fade** rather than being cleared, so the page never collapses and reflows mid-search.
- A spinner is drawn over the results area (slowed under `prefers-reduced-motion`).
- Pagination is dimmed and `pointer-events`-disabled, so a second click cannot double-fire.

A polite `role="status"` live region announces "Searching…", then "N results found."

### Empty results

The server always returns rendered markup — the empty state is a real message, never an empty string — so zero results can never blank the area or throw a JavaScript error.

### Accessibility

- Every control has a real `<label for="…">` bound to a per-instance unique id.
- The filter bar is a `<form role="search">` with no `action`, so a no-JavaScript submit reloads the page harmlessly rather than navigating somewhere broken.
- Pagination uses `<button>` elements with genuine `disabled` (plus `aria-disabled`) and `aria-current="page"`.
- After a page change, focus moves to the results region and the viewport scrolls to it; a keyword search leaves the caret in the input.
- Page 1 is rendered server-side, so the interface is useful before — and without — any JavaScript.

---

## CSS reference

[`assets/css/loop-grid-search.css`](assets/css/loop-grid-search.css) is deliberately unopinionated: layout, loading state and accessibility primitives only. No colours, no typography, no borders, no spacing beyond the grid gap.

```
.ajax-post-search                 instance root
.ajax-post-search__filters        filter bar (a <form role="search">)
.ajax-post-search__field          one label + control pair
.ajax-post-search__label          <label> element
.ajax-post-search__keyword        keyword <input type="search">
.ajax-post-search__date           Month / Year <select>
.ajax-post-search__taxonomy       taxonomy term <select>
.ajax-post-search__sort           sort <select> (optional)
.ajax-post-search__clear          Clear Filters <button>
.ajax-post-search__results        results region (aria-busy toggles here)
.ajax-post-search__list           results grid
.ajax-post-search__item           one result cell
.ajax-post-search__no-results     empty-state message
.ajax-post-search__error          request-failure message
.ajax-post-search__pagination     <nav> holding the page controls
.ajax-post-search__page           any page button
.ajax-post-search__page--prev     Previous button
.ajax-post-search__page--next     Next button
.ajax-post-search__page--number   numbered page button (+ .is-current)
.ajax-post-search__pages          wrapper around the numbered buttons
.ajax-post-search__ellipsis       truncation marker (aria-hidden)
.ajax-post-search__page-count     "Page 2 of 5" text, Previous/Next-only style
.ajax-post-search--loading        set on the root while a request is in flight
```

Two custom properties drive the grid:

| Property | Default |
|---|---|
| `--lgs-columns` | `3` |
| `--lgs-gap` | `24px` |

There are **no inline `style` attributes**. Per-instance defaults are emitted in a scoped `<style>` element using a `:where()` selector, i.e. **zero specificity** — a single class of your own overrides them, no `!important` needed, and the Elementor widget's responsive Columns/Gap CSS wins automatically.

---

## Filter reference

| Filter | Arguments | Purpose |
|---|---|---|
| `lgs_query_args` | `$args, $config, $criteria` | Adjust the `WP_Query` arguments before the search runs. |
| `lgs_keyword_where` | `$where, $terms, $columns, $meta_keys` | Replace the generated keyword SQL. **Callbacks must escape and prepare their own values.** |
| `lgs_search_field_options` | `$options, $post_type` | Add or remove fields in the widget's ACF search-field picker. |
| `lgs_date_options` | `$options, $post_type` | Alter the Month / Year dropdown options. |
| `lgs_taxonomy_options` | `$options, $taxonomy, $post_type` | Alter the term dropdown options. |
| `lgs_result_card_template` | `$template, $config` | Point the PHP card at any absolute path. |
| `lgs_results_html` | `$html, $query, $config` | Wrap or replace the rendered result list. |
| `lgs_no_results_html` | `$html, $config` | Replace the empty-state markup. |
| `lgs_pagination_html` | `$html, $current_page, $total_pages, $config` | Replace the pagination markup. |
| `lgs_filters_html` | `$html, $config, $criteria, $instance_id` | Replace the filter bar markup. |
| `lgs_interface_html` | `$html, $config, $instance_id` | Wrap or replace the whole interface. |
| `lgs_ajax_response` | `$payload, $query, $config, $criteria` | Add fields to the AJAX response payload. |
| `lgs_search_debounce_ms` | `$ms` (default `400`) | Change the keyword debounce delay. |

Example — expose an ACF field type the picker excludes by default (a Checkbox field, whose serialized value a `LIKE` can still match in practice):

```php
add_filter( 'lgs_search_field_options', function ( array $options, string $post_type ): array {
    $options['certifications'] = 'Certifications — certifications (Checkbox)';
    return $options;
}, 10, 2 );
```

Example — narrow the term dropdown to terms that actually have posts in the queried post type:

```php
add_filter( 'lgs_taxonomy_options', function ( array $options, string $taxonomy, string $post_type ): array {
    if ( 'resource_type' !== $taxonomy ) {
        return $options;
    }

    return array_filter( $options, static function ( $name, $term_id ) use ( $taxonomy, $post_type ) {
        return (bool) get_posts( [
            'post_type'      => $post_type,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [ [ 'taxonomy' => $taxonomy, 'terms' => [ (int) $term_id ] ] ],
        ] );
    }, ARRAY_FILTER_USE_BOTH );
}, 10, 3 );
```

---

## Performance notes

**One request, one response.** There is no "1 request for posts + 1 REST request per featured image" pattern. Everything — including `<img srcset>` markup — is rendered server-side and returned in a single AJAX response.

**Only one page of posts is ever queried.** `WP_Query` pagination is used properly (`posts_per_page` + `paged`). The plugin never fetches all posts to display one page.

**The Month / Year list costs one lean query.** A single `SELECT DISTINCT YEAR(post_date), MONTH(post_date)` over `wp_posts` — the same approach WordPress core takes in `wp_get_archives()`. It is covered by core's `type_status_date` composite index, so MySQL can satisfy it from the index without touching row data. No post objects are loaded, and the result is memoised per request so rendering the filter bar never repeats it.

**Term options come from the term cache.** `get_terms()` with `fields => 'id=>name'` — no post objects.

**Post meta and term caches are primed in bulk.** `update_post_meta_cache` and `update_post_term_cache` are on, so the card or loop template resolves thumbnails, ACF values and taxonomy terms from two priming queries rather than per-post lookups.

**Assets load only where they are needed.** The single CSS file and single JS file are registered once and enqueued only when an instance actually renders. Pages without a search interface download neither.

**Keyword search cost.** The correlated `EXISTS` resolves through `wp_postmeta`'s `post_id` index, so per candidate row it inspects only that post's meta rows. The remaining cost is the unavoidable `LIKE '%…%'` — a leading wildcard cannot use a B-tree index, in this plugin or in WordPress core's own search. For a very large corpus (roughly 100k+ posts) consider a MySQL `FULLTEXT` index or a dedicated search service, wired in through the `lgs_keyword_where` filter.

---

## Compatibility notes and known limitations

**Full-page caching and nonces.** The `lgs_search` nonce is baked into cached HTML and is valid for 24 hours. If a page is cached for longer than that, a visitor may load stale HTML whose nonce has expired; the endpoint then returns `lgs_invalid_nonce` and the interface shows "Your session has expired. Please reload the page and try again." Keep the page cache TTL under 24 hours, or exclude pages carrying a search instance from full-page caching.

**`hide_empty` is per-taxonomy, not per-post-type.** WordPress term counts are not split by post type. When a taxonomy is shared across post types, a term whose only posts belong to a *different* post type still appears in the dropdown (and yields no results). That is a WordPress data-model limitation; use `lgs_taxonomy_options` to narrow the list — see the example above.

**Elementor widget assets inside a loop template.** Widgets that need their own CSS/JS are covered because page 1 renders server-side during the normal page lifecycle, which lets Elementor's conditional asset loader enqueue them. A widget that appears *only* on later pages (an alternate template, a conditional element) could arrive without its assets. If you hit that, either keep the loop template's widget set uniform or disable Elementor's *Improved Asset Loading* experiment.

**Nested Elementor templates inside a loop template.** The print-once CSS guard also suppresses a *nested* template's inline stylesheet from card 2 onward. Harmless in practice, because the initial page render has already enqueued it as a real stylesheet.

**ACF is not required at query time.** The keyword search reads post meta directly with `$wpdb`, and the built-in card reads it with `get_post_meta()`. ACF is used only to *discover* field names for the widget's picker and to *manage* the field in the admin. Consequently the picker lists only field types whose stored value is the searchable text; relationship, post object, image, gallery, repeater, group, flexible content, link, taxonomy and user fields are excluded because a `LIKE` against their raw storage either never matches or matches nonsense. Override with `lgs_search_field_options` if you know what a particular field's storage looks like.

**Repeater and Group sub-fields cannot be searched by name.** ACF stores them under generated keys (`specs_0_label`), so no fixed meta key addresses them. They are omitted from the picker rather than offered and silently failing.

**Searching Post Content is the most expensive option.** `post_content` is a `longtext` column, so a leading-wildcard `LIKE` over it scans full row data rather than a compact index. It is off by default for that reason. On a large corpus prefer title plus a short custom field, or move to `FULLTEXT` via `lgs_keyword_where`.

**Sticky posts are ignored** (`ignore_sticky_posts`), so a sticky post is not prepended to page 1 and cannot break the per-page count or the "newest first" contract.

**`orderby` excludes `rand`** deliberately: random ordering produces unstable pagination, where the same post can appear on two pages.

**Password-protected posts** are returned by the query if published, but Elementor's `get_builder_content_for_display()` renders nothing for them (`post_password_required()`). The built-in card renders the title and a link, which is standard WordPress archive behaviour.

**PHP 8.1 is required.** The main plugin file contains a version guard written in PHP 5-compatible syntax; all 8.1+ code lives in separately-required files, so an older runtime shows an admin notice instead of a fatal parse error.

**Elementor is optional.** Without it, the shortcode still works with the PHP card; the widget and template rendering are simply unavailable, and an admin notice says so. Elementor Pro is needed only to *author* Loop Item templates — rendering a saved one requires Elementor core alone.

---

## Updates

The plugin includes a GitHub-based updater pointed at
[Magellan-Web-Dev/Loop-Grid-Search-For-Elementor](https://github.com/Magellan-Web-Dev/Loop-Grid-Search-For-Elementor).

WordPress checks the latest published GitHub release, caches the response for 12 hours, and shows the normal plugin update UI when a newer release is available. The Plugins screen also adds a **Check for updates** row action, which is nonce-protected and requires the `update_plugins` capability.

GitHub archives extract into a version-stamped folder, so an `upgrader_post_install` filter (priority 10, before WordPress's own reactivation at priority 15) moves the installed directory back to the canonical `loop-grid-search-for-elementor` name.

---

## Changelog

### 1.2.0
- **Pagination Style** control: *Previous / Next + Page Numbers* (default) or *Previous / Next Only* with a "Page 2 of 5" counter.
- **Previous / Next button text** is now configurable, in the widget and via `pagination_prev_label` / `pagination_next_label`.
- **Page number truncation.** New **Max Page Numbers Shown** control (default 6, range 3–50). The visible window is centred on the current page and clamped at both ends, with an ellipsis on each truncated side — so 9 pages at a limit of 6 renders `1 2 3 4 5 6 …` on page 1 and `… 2 3 4 5 6 7 …` on page 4. The button count never grows with the result set.
- Pagination controls moved into their own **Content ▸ Pagination** panel section.
- The old `pagination_numbers="no"` shortcode attribute still works and maps onto `pagination_mode="prev_next"`.
- **Note when updating:** the signed configuration payload gained four fields, so any page already sitting in a full-page cache will report "this search could not be verified" until that page is regenerated. Flush the page cache after updating.

### 1.1.0
- **Multi-field keyword search.** The keyword now matches any combination of built-in post fields (**Search In**: Post Title / Post Excerpt / Post Content, title by default) OR any number of custom fields — all OR-ed together in one clause.
- **Visual ACF field picker.** New `FieldRegistry` discovers ACF fields and offers them in the widget as `Label — meta_key (Post Type)`, with fields bound to the selected post type promoted to the top. Only text-style field types are listed; the rest are excluded rather than offered and silently failing.
- **Additional Meta Keys** control for meta not managed by ACF, or sites without ACF.
- Several meta keys now compile to a single `meta_key IN (…)` subquery, so extra fields cost almost nothing.
- Column names are re-validated against an allowlist inside `KeywordSearch` itself, independently of `Config`, before any interpolation.
- Configuration payloads must now arrive in canonical form, so tampering is rejected uniformly instead of being silently normalised in some cases.
- New `lgs_search_field_options` filter; `lgs_keyword_where` now receives `$columns` and `$meta_keys`.
- The built-in card tries each searchable meta key in order for its summary.
- Existing `[ajax_post_search acf_search_field="excerpt"]` shortcodes keep working unchanged; the attribute now also accepts a comma-separated list.

### 1.0.0
- Initial release.
- `[loop_grid_search]` / `[ajax_post_search]` shortcode and the drag-and-drop **Loop Grid Search** Elementor widget, both over one shared renderer.
- Keyword search matching post title **OR** a configured custom field, via a scoped `posts_clauses` filter using a correlated `EXISTS` subquery.
- Month / Year filter generated from real publish dates; taxonomy filter for any public taxonomy; optional sort dropdown; Clear Filters.
- Debounced live search with `AbortController` plus request-token stale-response protection.
- Server-side result rendering through an Elementor template (with correct `setup_postdata()` post context) or a theme-overridable PHP card.
- AJAX pagination with Previous / Next / numbered pages, disabled and current states, and out-of-range page clamping.
- HMAC-signed per-instance configuration so the AJAX endpoint never trusts the query scope from the browser.
- GitHub release update checks with a manual "Check for updates" row action.

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
