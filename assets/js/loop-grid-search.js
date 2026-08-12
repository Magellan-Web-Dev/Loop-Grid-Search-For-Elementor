/**
 * Loop Grid Search for Elementor — frontend controller.
 *
 * Modern vanilla JavaScript: no jQuery (the plugin adds no jQuery dependency of its own, and
 * the surrounding project has no convention requiring it), fetch() for transport and
 * AbortController for cancellation.
 *
 * One copy of this file serves every instance on the page. Each `.ajax-post-search` root
 * owns a LoopGridSearchInstance with its own state, its own in-flight request and its own
 * abort controller, so two search widgets on one page never interfere.
 *
 * Request lifecycle
 * -----------------
 *   keyword input  →  debounced (default 400ms)  ┐
 *   Enter key      →  immediate                  │
 *   select change  →  immediate, page reset to 1 ├→ request()
 *   pagination     →  immediate, page preserved  │
 *   clear          →  reset all state            │
 *   Back / Forward →  state re-read from the URL ┘
 *
 * URL state (SEO pagination)
 * --------------------------
 * The server renders pagination as real links — `?lgs_page=2` — so crawlers can reach
 * every page and a reload or a shared URL reproduces what was on screen. Here that means:
 *
 *   • A plain left-click on a page link is intercepted: preventDefault(), then the same
 *     AJAX swap as before. No reload, no flash, filter state preserved.
 *   • A modified click (Cmd/Ctrl/Shift/Alt, or middle-click) is left entirely alone, so
 *     "open in new tab" works on a page link exactly as it does on any other link.
 *   • After the response lands, the address bar is brought into step with what is on
 *     screen: pushState for a page change (Back should return to the previous page of
 *     results), replaceState for a filter change (typing must not flood the history).
 *   • popstate re-reads the URL, syncs the form controls to it and re-runs the search,
 *     so Back and Forward move through the pages the visitor actually saw.
 *
 * All of that is skipped when the instance has SEO pagination switched off, in which case
 * the server emits the old button markup and the URL is never touched.
 *
 * Stale-response protection is belt-and-braces:
 *   1. Each new request aborts the previous one via AbortController, so the browser stops
 *      waiting on it at the network layer.
 *   2. Every request also carries a monotonically increasing token. A response whose token
 *      is not the latest is discarded, which covers the window where a request had already
 *      resolved before abort() could take effect.
 *
 * Typing "s → so → sol → sola → solar" therefore always paints the results for "solar",
 * regardless of the order the five responses arrive in.
 */
(function () {
	'use strict';

	var SETTINGS = window.LGS_Settings || {};
	var DEBOUNCE_MS = typeof SETTINGS.debounce === 'number' && SETTINGS.debounce > 0 ? SETTINGS.debounce : 400;
	var I18N = SETTINGS.i18n || {};

	var ROOT_SELECTOR = '.ajax-post-search[data-lgs-instance]';
	var LOADING_CLASS = 'ajax-post-search--loading';

	/**
	 * Query parameter names, supplied by PHP so both sides always agree. The literals are
	 * only a fallback for the case where the settings object failed to print.
	 */
	var PARAMS = SETTINGS.params || {
		page: 'lgs_page',
		keyword: 'lgs_q',
		date: 'lgs_date',
		term: 'lgs_term',
		sort: 'lgs_sort'
	};

	/**
	 * Whether this browser can be asked to rewrite the address bar without navigating.
	 */
	var CAN_WRITE_URL = 'function' === typeof window.URL &&
		!! ( window.history && window.history.pushState && window.history.replaceState );

	/**
	 * Tracks which roots already have a controller attached, so a re-scan of the DOM
	 * (Elementor editor re-render, a page builder injecting markup) never double-binds.
	 * A WeakMap lets removed nodes be garbage collected.
	 */
	var initialised = new WeakMap();

	/**
	 * Controller for a single search instance.
	 *
	 * @param {HTMLElement} root The `.ajax-post-search` wrapper element.
	 * @constructor
	 */
	function LoopGridSearchInstance( root ) {
		this.root = root;
		this.config = readConfig( root );

		this.results = root.querySelector( '.ajax-post-search__results' );
		this.paginationWrap = root.querySelector( '.ajax-post-search__pagination-wrap' );
		this.status = root.querySelector( '.ajax-post-search__status' );

		this.keywordInput = root.querySelector( '.ajax-post-search__keyword' );
		this.dateSelect = root.querySelector( '.ajax-post-search__date' );
		this.taxonomySelect = root.querySelector( '.ajax-post-search__taxonomy' );
		this.sortSelect = root.querySelector( '.ajax-post-search__sort' );
		this.clearButton = root.querySelector( '.ajax-post-search__clear' );
		this.form = root.querySelector( '.ajax-post-search__filters' );

		// Whether this instance owns the page URL. The value rides along in the signed
		// config block, so there is nothing extra to print per instance.
		this.usesUrlState = CAN_WRITE_URL && '1' === String( this.config.seo_pagination || '' );

		// Current filter state. Seeded from the DOM so a server-rendered preselection (or a
		// browser restoring form values on back-navigation) is respected. The page number
		// comes from the wrapper, which the server stamps with the page it actually served
		// — landing on ?lgs_page=3 must not leave the script thinking it is showing page 1.
		this.state = {
			keyword: this.keywordInput ? this.keywordInput.value : '',
			date: this.dateSelect ? this.dateSelect.value : '',
			term: this.taxonomySelect ? this.taxonomySelect.value : '',
			sort: this.sortSelect ? this.sortSelect.value : '',
			paged: readPage( root.getAttribute( 'data-lgs-current-page' ) )
		};

		this.requestToken = 0;
		this.controller = null;
		this.debounceTimer = null;
		this.pendingFocus = false;
		this.pendingHistory = null;

		this.bindEvents();
	}

	/**
	 * Attaches every listener this instance needs.
	 *
	 * Pagination uses event delegation on the wrapper, because the pagination markup is
	 * replaced wholesale on every response and directly bound listeners would be lost.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.bindEvents = function () {
		var self = this;

		if ( this.form ) {
			// The filter bar is a real <form> so it reads as a search landmark and degrades
			// gracefully without JS. With JS, it must never navigate.
			this.form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				self.cancelDebounce();
				self.syncKeywordFromInput();
				self.request( { resetPage: true, history: 'replace' } );
			} );
		}

		if ( this.keywordInput ) {
			this.keywordInput.addEventListener( 'input', function () {
				self.syncKeywordFromInput();
				self.scheduleDebounced();
			} );

			this.keywordInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' !== event.key ) {
					return;
				}

				// Enter searches immediately rather than waiting out the debounce.
				event.preventDefault();
				self.cancelDebounce();
				self.syncKeywordFromInput();
				self.request( { resetPage: true, history: 'replace' } );
			} );

			// Covers the native clear ("×") button on type="search" in Safari/Chrome, which
			// fires `search` rather than a keystroke.
			this.keywordInput.addEventListener( 'search', function () {
				self.cancelDebounce();
				self.syncKeywordFromInput();
				self.request( { resetPage: true, history: 'replace' } );
			} );
		}

		[
			[ this.dateSelect, 'date' ],
			[ this.taxonomySelect, 'term' ],
			[ this.sortSelect, 'sort' ]
		].forEach( function ( pair ) {
			var element = pair[ 0 ];
			var key = pair[ 1 ];

			if ( ! element ) {
				return;
			}

			element.addEventListener( 'change', function () {
				self.state[ key ] = element.value;
				self.cancelDebounce();
				// Any filter change invalidates the current page position. The URL is
				// replaced rather than pushed: a filter change is a correction to where
				// the visitor already is, not a place to come Back to.
				self.request( { resetPage: true, history: 'replace' } );
			} );
		} );

		if ( this.clearButton ) {
			this.clearButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				self.clear();
			} );
		}

		if ( this.paginationWrap ) {
			this.paginationWrap.addEventListener( 'click', function ( event ) {
				var control = event.target.closest( '[data-lgs-page]' );

				if ( ! control || ! self.paginationWrap.contains( control ) ) {
					return;
				}

				// Let the browser own modified clicks: Cmd/Ctrl-click, Shift-click and
				// middle-click must open the page of results the href points at, which is
				// the whole reason these are links rather than buttons.
				if ( isModifiedClick( event ) ) {
					return;
				}

				event.preventDefault();

				if ( control.disabled || 'true' === control.getAttribute( 'aria-disabled' ) ) {
					return;
				}

				var page = parseInt( control.getAttribute( 'data-lgs-page' ), 10 );

				// Ignore a click on the page already displayed — no request, no flicker.
				if ( ! page || page === self.state.paged ) {
					return;
				}

				self.state.paged = page;
				self.cancelDebounce();
				self.pendingFocus = true;
				// A page change is a step the visitor should be able to reverse with Back.
				self.request( { resetPage: false, history: 'push' } );
			} );
		}

		if ( this.usesUrlState ) {
			window.addEventListener( 'popstate', function () {
				self.cancelDebounce();
				self.readUrlState();
				// No history write: the browser has already moved the entry pointer, and
				// writing here would corrupt the very entry being restored.
				self.request( { resetPage: false, history: null } );
			} );
		}
	};

	/**
	 * Copies the keyword input's value into state, normalising whitespace.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.syncKeywordFromInput = function () {
		this.state.keyword = this.keywordInput ? this.keywordInput.value : '';
	};

	/**
	 * Restarts the debounce timer for keyword input.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.scheduleDebounced = function () {
		var self = this;

		this.cancelDebounce();

		this.debounceTimer = window.setTimeout( function () {
			self.debounceTimer = null;
			self.request( { resetPage: true, history: 'replace' } );
		}, DEBOUNCE_MS );
	};

	/**
	 * Cancels any pending debounced request.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.cancelDebounce = function () {
		if ( null !== this.debounceTimer ) {
			window.clearTimeout( this.debounceTimer );
			this.debounceTimer = null;
		}
	};

	/**
	 * Resets every filter and reloads the unfiltered first page.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.clear = function () {
		if ( this.keywordInput ) {
			this.keywordInput.value = '';
		}

		if ( this.dateSelect ) {
			this.dateSelect.value = '';
		}

		if ( this.taxonomySelect ) {
			this.taxonomySelect.value = '';
		}

		if ( this.sortSelect ) {
			// Back to the first option, which the server renders as the configured default
			// sort order (newest first unless the instance says otherwise).
			this.sortSelect.selectedIndex = 0;
		}

		this.state = {
			keyword: '',
			date: '',
			term: '',
			sort: this.sortSelect ? this.sortSelect.value : '',
			paged: 1
		};

		this.cancelDebounce();
		this.request( { resetPage: true, history: 'replace' } );
	};

	/**
	 * Reads the visitor's state back out of the current URL and into the form controls.
	 *
	 * Used on Back/Forward, where the browser has restored a URL but nothing else: the
	 * document is the one already on screen, so the controls and the internal state have
	 * to be brought to the URL by hand before the search is re-run.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.readUrlState = function () {
		var params = new window.URL( window.location.href ).searchParams;

		this.state.keyword = params.get( PARAMS.keyword ) || '';
		this.state.paged = readPage( params.get( PARAMS.page ) );

		if ( this.keywordInput ) {
			this.keywordInput.value = this.state.keyword;
		}

		this.state.date = setSelectValue( this.dateSelect, params.get( PARAMS.date ) );
		this.state.term = setSelectValue( this.taxonomySelect, params.get( PARAMS.term ) );
		this.state.sort = setSelectValue( this.sortSelect, params.get( PARAMS.sort ) );
	};

	/**
	 * Returns this instance's default sort, i.e. the first option the server rendered.
	 *
	 * @return {string}
	 */
	LoopGridSearchInstance.prototype.defaultSort = function () {
		return this.sortSelect && this.sortSelect.options.length ? this.sortSelect.options[ 0 ].value : '';
	};

	/**
	 * Rewrites the address bar to match what is currently on screen.
	 *
	 * Every parameter this plugin owns is cleared first, so a filter that has been switched
	 * off leaves no trace behind; anything else already in the query string (a campaign
	 * tag, another plugin's parameter) is preserved untouched. Page 1 writes no page
	 * parameter at all, keeping the first page of results at one canonical address.
	 *
	 * @param {string} mode 'push' for a new history entry, 'replace' to amend the current one.
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.syncUrl = function ( mode ) {
		if ( ! this.usesUrlState ) {
			return;
		}

		var url = new window.URL( window.location.href );
		var params = url.searchParams;

		[ PARAMS.page, PARAMS.keyword, PARAMS.date, PARAMS.term, PARAMS.sort ].forEach( function ( name ) {
			params.delete( name );
		} );

		if ( this.state.keyword ) {
			params.set( PARAMS.keyword, this.state.keyword );
		}

		if ( this.state.date ) {
			params.set( PARAMS.date, this.state.date );
		}

		if ( this.state.term ) {
			params.set( PARAMS.term, this.state.term );
		}

		// A sort that only restates the instance's default is left out, so the first
		// interaction on a search with sorting enabled does not stamp `lgs_sort=newest`
		// onto every URL the visitor shares for a choice they never made. The server
		// applies the same rule when it builds the pagination hrefs, and the first option
		// is exactly what it renders as the default.
		if ( this.state.sort && this.state.sort !== this.defaultSort() ) {
			params.set( PARAMS.sort, this.state.sort );
		}

		if ( this.state.paged > 1 ) {
			params.set( PARAMS.page, String( this.state.paged ) );
		}

		// The fragment belongs to the no-JavaScript path only: here the results are already
		// in view, and leaving #lgs-1 in the address bar would make an otherwise clean URL
		// look like an anchor link.
		url.hash = '';

		try {
			window.history[ 'push' === mode ? 'pushState' : 'replaceState' ]( {}, '', url.toString() );
		} catch ( error ) {
			// Some embedded and sandboxed contexts forbid history writes. The results on
			// screen are correct either way; only the address bar goes stale.
		}
	};

	/**
	 * Issues one AJAX request and applies its response.
	 *
	 * @param {{resetPage: boolean, history: (string|null)}} options
	 *        resetPage — jump back to page 1 first.
	 *        history   — 'push', 'replace', or null/omitted to leave the URL alone.
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.request = function ( options ) {
		var self = this;

		if ( ! SETTINGS.ajaxUrl || ! SETTINGS.action ) {
			return;
		}

		if ( options && options.resetPage ) {
			this.state.paged = 1;
		}

		// Deferred until the response lands, so the URL records the page the server
		// actually served — an out-of-range request is clamped back into the result set.
		this.pendingHistory = options && options.history ? options.history : null;

		// (1) Stop the previous request at the network layer.
		if ( this.controller ) {
			this.controller.abort();
		}

		// (2) Token guard for a response that resolved before abort() landed.
		this.requestToken += 1;

		var token = this.requestToken;
		var controller = 'undefined' !== typeof window.AbortController ? new window.AbortController() : null;

		this.controller = controller;
		this.setLoading( true );

		window
			.fetch( SETTINGS.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: this.buildBody(),
				signal: controller ? controller.signal : undefined
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					// Read the body anyway: the endpoint returns a JSON error payload with a
					// human-readable message for expired nonces and failed verification.
					return response.json().catch( function () {
						return null;
					} );
				}

				return response.json();
			} )
			.then( function ( payload ) {
				if ( token !== self.requestToken ) {
					// A newer request has already superseded this one.
					return;
				}

				self.apply( payload );
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					// Expected: superseded by a newer request.
					return;
				}

				if ( token !== self.requestToken ) {
					return;
				}

				self.showError();
			} )
			.finally( function () {
				if ( token === self.requestToken ) {
					self.controller = null;
					self.setLoading( false );
				}
			} );
	};

	/**
	 * Serialises the current state plus the server-signed instance config.
	 *
	 * The config values are echoed back exactly as the server emitted them, including the
	 * signature; the endpoint verifies that signature before trusting any of them.
	 *
	 * @return {URLSearchParams}
	 */
	LoopGridSearchInstance.prototype.buildBody = function () {
		var body = new URLSearchParams();

		body.set( 'action', SETTINGS.action );
		body.set( 'nonce', SETTINGS.nonce || '' );

		body.set( 'keyword', this.state.keyword || '' );
		body.set( 'date', this.state.date || '' );
		body.set( 'term', this.state.term || '' );
		body.set( 'sort', this.state.sort || '' );
		body.set( 'paged', String( this.state.paged || 1 ) );

		if ( this.usesUrlState ) {
			// admin-ajax.php has no idea which page this instance lives on, and it needs
			// one to build the hrefs for the replacement pagination markup. The server
			// accepts this only after checking it points at the same site.
			body.set( 'page_url', window.location.href );
			body.set( 'instance', this.root.id || '' );
		}

		Object.keys( this.config ).forEach( function ( key ) {
			body.set( 'config[' + key + ']', String( this.config[ key ] ) );
		}, this );

		return body;
	};

	/**
	 * Writes a successful response into the DOM.
	 *
	 * @param {Object|null} payload Parsed JSON body.
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.apply = function ( payload ) {
		if ( ! payload || true !== payload.success || ! payload.data ) {
			this.showError( payload && payload.data ? payload.data.message : null );
			return;
		}

		var data = payload.data;

		if ( this.results ) {
			// The server always sends markup — the empty state is a rendered message, never
			// an empty string — so zero results can never blank the area or throw here.
			this.results.innerHTML = 'string' === typeof data.html ? data.html : '';

			// Must run after the markup is in the document: Elementor's ready trigger walks
			// up the tree with closest(), which only works once the nodes are attached.
			initElementorElements( this.results );
		}

		if ( this.paginationWrap ) {
			this.paginationWrap.innerHTML = 'string' === typeof data.pagination_html ? data.pagination_html : '';
		}

		if ( 'number' === typeof data.current_page && data.current_page > 0 ) {
			// Trust the server's page number: it clamps an out-of-range request back into
			// the real result set, and the pagination markup reflects the clamped value.
			this.state.paged = data.current_page;
		}

		if ( this.pendingHistory ) {
			this.syncUrl( this.pendingHistory );
			this.pendingHistory = null;
		}

		this.announce( this.resultsMessage( data.total_results ) );

		if ( this.pendingFocus ) {
			this.pendingFocus = false;
			this.focusResults();
		}
	};

	/**
	 * Replaces the results area with an error message, leaving pagination untouched.
	 *
	 * @param {string|null} message Server-supplied message, if any.
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.showError = function ( message ) {
		var text = message || I18N.error || 'Something went wrong loading the results.';

		// Nothing was rendered, so there is nothing for the URL to describe.
		this.pendingHistory = null;

		if ( this.results ) {
			var paragraph = document.createElement( 'p' );

			paragraph.className = 'ajax-post-search__error';
			paragraph.setAttribute( 'role', 'alert' );
			// textContent, not innerHTML: the message may originate from a server response.
			paragraph.textContent = text;

			this.results.innerHTML = '';
			this.results.appendChild( paragraph );
		}

		this.announce( text );
	};

	/**
	 * Toggles the loading state.
	 *
	 * The existing results stay in the DOM and are faded by CSS rather than being cleared,
	 * so the page never collapses and reflows mid-search.
	 *
	 * @param {boolean} isLoading
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.setLoading = function ( isLoading ) {
		this.root.classList.toggle( LOADING_CLASS, isLoading );

		if ( this.results ) {
			this.results.setAttribute( 'aria-busy', isLoading ? 'true' : 'false' );
		}

		if ( isLoading ) {
			this.announce( I18N.searching || 'Searching…' );
		}
	};

	/**
	 * Builds the screen-reader result count message.
	 *
	 * @param {number} total
	 * @return {string}
	 */
	LoopGridSearchInstance.prototype.resultsMessage = function ( total ) {
		var count = 'number' === typeof total ? total : 0;

		if ( 0 === count ) {
			return I18N.noResults || 'No results found.';
		}

		if ( 1 === count ) {
			return I18N.oneResult || '1 result found.';
		}

		return ( I18N.results || '%d results found.' ).replace( '%d', String( count ) );
	};

	/**
	 * Publishes a message to the instance's polite live region.
	 *
	 * @param {string} message
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.announce = function ( message ) {
		if ( this.status ) {
			this.status.textContent = message;
		}
	};

	/**
	 * Moves focus and the viewport to the top of the refreshed results.
	 *
	 * Only used for pagination: a keyword search should leave the caret in the input.
	 *
	 * @return {void}
	 */
	LoopGridSearchInstance.prototype.focusResults = function () {
		if ( ! this.results ) {
			return;
		}

		// preventScroll keeps focus() from jumping abruptly; scrollIntoView then animates.
		try {
			this.results.focus( { preventScroll: true } );
		} catch ( error ) {
			this.results.focus();
		}

		if ( 'function' === typeof this.results.scrollIntoView ) {
			this.results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};

	/**
	 * Re-runs Elementor's frontend element handlers over freshly injected markup.
	 *
	 * Elementor initialises widgets exactly once, on page load: its DocumentHandler iterates
	 * every `.elementor-element` and calls `elementsHandler.runReadyTrigger()`, which fires
	 * the `frontend/element_ready/*` actions each widget's JS handler listens on. Markup that
	 * arrives later over AJAX is never seen by that pass.
	 *
	 * For most widgets that only costs interactivity, but for **Motion Effects** it hides the
	 * content outright. When an entrance animation is configured, PHP stamps
	 * `elementor-invisible` onto the widget wrapper (`element-base.php`), and Elementor's CSS
	 * defines `.elementor-invisible { visibility: hidden }`. The only thing that ever removes
	 * that class is `GlobalHandler.animate()`, which runs off `frontend/element_ready/global`.
	 * No ready trigger means the class is never removed and the widget stays invisible
	 * forever — the reason animated widgets disappeared after a search.
	 *
	 * The re-init below is the same sequence Elementor Pro itself performs after replacing
	 * loop content during AJAX pagination: run the ready trigger for every `.elementor-element`
	 * in the new subtree, then let Pro re-observe any lazy-loaded background images.
	 *
	 * @param {Element} scope Container holding the newly inserted markup.
	 * @return {void}
	 */
	function initElementorElements( scope ) {
		if ( ! scope || 'function' !== typeof scope.querySelectorAll ) {
			return;
		}

		var elements = scope.querySelectorAll( '.elementor-element' );

		if ( ! elements.length ) {
			return;
		}

		var frontend = window.elementorFrontend;
		var handler = frontend && frontend.elementsHandler;
		var canTrigger = !! ( handler && 'function' === typeof handler.runReadyTrigger );
		var triggered = false;

		if ( canTrigger ) {
			Array.prototype.forEach.call( elements, function ( element ) {
				try {
					handler.runReadyTrigger( element );
					triggered = true;
				} catch ( error ) {
					// One widget's handler throwing must not stop the rest from initialising.
				}
			} );
		}

		if ( ! triggered ) {
			// Elementor's API is absent or refused to run. Animations are a nice-to-have;
			// content being permanently invisible is not acceptable, so strip the class the
			// animation handler would have removed. Only reached when the blessed path
			// failed, so this never races Elementor's own timing.
			Array.prototype.forEach.call(
				scope.querySelectorAll( '.elementor-invisible' ),
				function ( element ) {
					element.classList.remove( 'elementor-invisible' );
				}
			);

			return;
		}

		// Elementor Pro lazy-loads background images through an IntersectionObserver that
		// only knows about elements present when it was created; this event asks it to pick
		// up the new ones. Mirrors Pro's own afterInsertPosts().
		var proConfig = window.ElementorProFrontendConfig;

		if ( proConfig && proConfig.settings && proConfig.settings.lazy_load_background_images ) {
			try {
				document.dispatchEvent( new Event( 'elementor/lazyload/observe' ) );
			} catch ( error ) {
				// Event constructor unavailable — lazy backgrounds simply stay unobserved.
			}
		}
	}

	/**
	 * True when a click carries a modifier the browser has its own meaning for.
	 *
	 * Cmd/Ctrl-click and middle-click open a link in a new tab, Shift-click in a new
	 * window, Alt-click downloads it. Intercepting any of those would break behaviour the
	 * visitor expects from every other link on the page.
	 *
	 * @param {MouseEvent} event
	 * @return {boolean}
	 */
	function isModifiedClick( event ) {
		return !! ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
			( 'number' === typeof event.button && 0 !== event.button ) );
	}

	/**
	 * Parses a page number, floored at 1.
	 *
	 * @param {string|null} value
	 * @return {number}
	 */
	function readPage( value ) {
		var page = parseInt( value, 10 );

		return page > 0 ? page : 1;
	}

	/**
	 * Sets a select to a value, falling back to its first option when the value is absent.
	 *
	 * The date and taxonomy selects both carry an explicit "All …" option with an empty
	 * value, so clearing them is a plain assignment. The sort select may not: when the
	 * instance's configured order has no matching preset there is no empty-valued option,
	 * and assigning '' would leave the control blank. Falling back to the first option
	 * lands on whatever the server renders as this instance's default.
	 *
	 * @param {HTMLSelectElement|null} select
	 * @param {string|null} value
	 * @return {string} The value the select actually ended up on.
	 */
	function setSelectValue( select, value ) {
		if ( ! select ) {
			return '';
		}

		select.value = value || '';

		if ( select.selectedIndex < 0 || select.value !== ( value || '' ) ) {
			select.selectedIndex = 0;
		}

		return select.value;
	}

	/**
	 * Reads and validates the signed configuration block inside a root element.
	 *
	 * @param {HTMLElement} root
	 * @return {Object} Flat map of string values, or an empty object when unreadable.
	 */
	function readConfig( root ) {
		var node = root.querySelector( 'script.ajax-post-search__config' );

		if ( ! node ) {
			return {};
		}

		try {
			var parsed = JSON.parse( node.textContent || '{}' );

			return parsed && 'object' === typeof parsed ? parsed : {};
		} catch ( error ) {
			return {};
		}
	}

	/**
	 * Initialises a single root element, once.
	 *
	 * @param {HTMLElement} root
	 * @return {void}
	 */
	function init( root ) {
		if ( ! root || initialised.has( root ) ) {
			return;
		}

		initialised.set( root, new LoopGridSearchInstance( root ) );
	}

	/**
	 * Initialises every uninitialised instance inside a scope.
	 *
	 * @param {ParentNode} [scope] Defaults to the whole document.
	 * @return {void}
	 */
	function initAll( scope ) {
		var context = scope && scope.querySelectorAll ? scope : document;

		Array.prototype.forEach.call( context.querySelectorAll( ROOT_SELECTOR ), init );
	}

	/**
	 * Watches for instances added after first paint.
	 *
	 * This covers the Elementor editor (which re-renders a widget's markup on every setting
	 * change) and any other script that injects content, without coupling to Elementor's
	 * frontend JavaScript internals.
	 *
	 * @return {void}
	 */
	function observe() {
		if ( 'undefined' === typeof window.MutationObserver || ! document.body ) {
			return;
		}

		new window.MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;

				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];

					if ( 1 !== node.nodeType ) {
						continue;
					}

					if ( node.matches && node.matches( ROOT_SELECTOR ) ) {
						init( node );
					}

					if ( node.querySelectorAll ) {
						initAll( node );
					}
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	/**
	 * Boots the module.
	 *
	 * @return {void}
	 */
	function boot() {
		initAll();
		observe();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Public surface, for themes that render an instance themselves.
	//
	// initElementor() is exposed because the problem it solves is not specific to this
	// plugin: any code that injects Elementor markup after page load has to re-run the
	// ready triggers, or widgets with entrance animations stay permanently hidden.
	window.LoopGridSearch = {
		init: init,
		initAll: initAll,
		initElementor: initElementorElements
	};
})();
