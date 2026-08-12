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
 *   clear          →  reset all state            ┘
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

		// Current filter state. Seeded from the DOM so a server-rendered preselection (or a
		// browser restoring form values on back-navigation) is respected.
		this.state = {
			keyword: this.keywordInput ? this.keywordInput.value : '',
			date: this.dateSelect ? this.dateSelect.value : '',
			term: this.taxonomySelect ? this.taxonomySelect.value : '',
			sort: this.sortSelect ? this.sortSelect.value : '',
			paged: 1
		};

		this.requestToken = 0;
		this.controller = null;
		this.debounceTimer = null;
		this.pendingFocus = false;

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
				self.request( { resetPage: true } );
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
				self.request( { resetPage: true } );
			} );

			// Covers the native clear ("×") button on type="search" in Safari/Chrome, which
			// fires `search` rather than a keystroke.
			this.keywordInput.addEventListener( 'search', function () {
				self.cancelDebounce();
				self.syncKeywordFromInput();
				self.request( { resetPage: true } );
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
				// Any filter change invalidates the current page position.
				self.request( { resetPage: true } );
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
				var button = event.target.closest( '[data-lgs-page]' );

				if ( ! button || ! self.paginationWrap.contains( button ) ) {
					return;
				}

				event.preventDefault();

				if ( button.disabled || 'true' === button.getAttribute( 'aria-disabled' ) ) {
					return;
				}

				var page = parseInt( button.getAttribute( 'data-lgs-page' ), 10 );

				// Ignore a click on the page already displayed — no request, no flicker.
				if ( ! page || page === self.state.paged ) {
					return;
				}

				self.state.paged = page;
				self.cancelDebounce();
				self.pendingFocus = true;
				self.request( { resetPage: false } );
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
			self.request( { resetPage: true } );
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
		this.request( { resetPage: true } );
	};

	/**
	 * Issues one AJAX request and applies its response.
	 *
	 * @param {{resetPage: boolean}} options Whether to jump back to page 1 first.
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
		}

		if ( this.paginationWrap ) {
			this.paginationWrap.innerHTML = 'string' === typeof data.pagination_html ? data.pagination_html : '';
		}

		if ( 'number' === typeof data.current_page && data.current_page > 0 ) {
			// Trust the server's page number: it clamps an out-of-range request back into
			// the real result set, and the pagination markup reflects the clamped value.
			this.state.paged = data.current_page;
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
	window.LoopGridSearch = { init: init, initAll: initAll };
})();
