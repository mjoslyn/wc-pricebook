/**
 * Pricebook settings page behavior: nav tabs, collapsible summary cards, and the
 * add/remove repeater.
 *
 * All tab panels live inside one <form>, so switching tabs only shows/hides
 * panels (CSS); every field still posts together on Save. Cards collapse to a
 * live one-line summary and expand to edit.
 *
 * No build step: plain DOM, no dependencies (jQuery is used only to re-init
 * WooCommerce enhanced-selects in newly added rows).
 */
( function () {
	'use strict';

	/* ---------------------------------------------------------------- Tabs */

	function initTabs() {
		var nav = document.querySelector( '[data-pricebook-tabs]' );
		if ( ! nav ) {
			return;
		}
		var tabs   = nav.querySelectorAll( '[data-pricebook-tab]' );
		var panels = document.querySelectorAll( '[data-pricebook-panel]' );

		function activate( slug ) {
			Array.prototype.forEach.call( tabs, function ( t ) {
				t.classList.toggle( 'nav-tab-active', t.getAttribute( 'data-pricebook-tab' ) === slug );
			} );
			Array.prototype.forEach.call( panels, function ( p ) {
				p.classList.toggle( 'is-active', p.getAttribute( 'data-pricebook-panel' ) === slug );
			} );
			try {
				window.sessionStorage.setItem( 'wcPricebookTab', slug );
			} catch ( e ) {}
		}

		Array.prototype.forEach.call( tabs, function ( t ) {
			t.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( t.getAttribute( 'data-pricebook-tab' ) );
			} );
		} );

		// Restore the last tab: URL hash wins, then the remembered tab.
		var initial = ( window.location.hash || '' ).replace( /^#/, '' );
		if ( ! initial ) {
			try {
				initial = window.sessionStorage.getItem( 'wcPricebookTab' ) || '';
			} catch ( e ) {}
		}
		if ( initial && nav.querySelector( '[data-pricebook-tab="' + initial + '"]' ) ) {
			activate( initial );
		}
	}

	/* ------------------------------------------------- Collapsible summaries */

	// Per repeater kind: which fields feed the one-line summary. `suffix` matches
	// the end of a field's name attribute (e.g. "[multiplier]").
	var SUMMARY = {
		tier: {
			title: { suffix: '[label]' },
			empty: 'New tier',
			meta: [
				{ suffix: '[key]', select: true, skipValues: [ '' ], cut: ' (', prefix: '(Role: ', after: ')' },
				{ suffix: '[base_meta]', select: true, skipValues: [ '' ] },
				{ suffix: '[multiplier]', prefix: '× ' },
				{ suffix: '[fallback_to]', select: true, prefix: 'Fallback to ' },
				{ suffix: '[override]', map: { '': 'lowest wins', when_priced: 'overrides if priced', always: 'always overrides' } }
			]
		},
		visibility: {
			title: { suffix: '[label]' },
			empty: 'New visibility role',
			meta: [
				{ suffix: '[hide]', select: true, skipValues: [ '' ] },
				{ suffix: '[match]', prefix: 'Match: ', upper: true }
			]
		},
		'role-price': {
			title: { suffix: '[role]', select: true },
			empty: 'New role price',
			meta: [
				{ suffix: '[price]', money: true },
				{ suffix: '[sale]', money: true, prefix: 'sale ' }
			]
		},
		bulk: {
			title: { suffix: '[role]', select: true },
			empty: 'New quantity break',
			meta: [
				{ suffix: '[min_qty]', prefix: 'qty ' },
				{ suffix: '[max_qty]', prefix: 'to ' },
				{ suffix: '[price]', money: true }
			]
		},
		'user-price': {
			title: { suffix: '[user-id]', select: true, cut: ' (#' },
			empty: 'New customer price',
			meta: [
				{ suffix: '[price]', money: true }
			]
		}
	};

	function fieldBySuffix( item, suffix ) {
		var els = item.querySelectorAll( '[name]' );
		for ( var i = 0; i < els.length; i++ ) {
			var name = els[ i ].getAttribute( 'name' ) || '';
			// Match a plain field ("[role]") or its multi-value array form ("[role][]").
			if ( name.slice( -suffix.length ) === suffix || name.slice( -( suffix.length + 2 ) ) === suffix + '[]' ) {
				return els[ i ];
			}
		}
		return null;
	}

	function displayValue( el, spec ) {
		if ( ! el ) {
			return '';
		}
		var val = el.value;
		if ( spec.skipValues && spec.skipValues.indexOf( val ) !== -1 ) {
			return '';
		}
		if ( spec.map ) {
			// Map raw field values to short summary labels (values not in the map
			// resolve to empty, i.e. omitted from the summary).
			return Object.prototype.hasOwnProperty.call( spec.map, val ) ? spec.map[ val ] : '';
		}
		if ( spec.select && el.tagName === 'SELECT' ) {
			var cut = function ( text ) {
				if ( spec.cut && text.indexOf( spec.cut ) !== -1 ) {
					text = text.split( spec.cut )[ 0 ];
				}
				return text.trim();
			};
			// Multi-select: join the selected option labels (empty when nothing chosen).
			if ( el.multiple ) {
				var texts = [];
				Array.prototype.forEach.call( el.selectedOptions || [], function ( opt ) {
					var t = cut( opt.text || '' );
					if ( t ) {
						texts.push( t );
					}
				} );
				return texts.join( ', ' );
			}
			// An empty selection (e.g. a "— Select —" placeholder) reads as no value.
			if ( val === '' || val == null ) {
				return '';
			}
			var opt = el.options[ el.selectedIndex ];
			return cut( opt ? opt.text : '' );
		}
		if ( spec.upper ) {
			return val ? String( val ).toUpperCase() : '';
		}
		return val ? String( val ).trim() : '';
	}

	function refreshSummary( item ) {
		var kindEl = item.closest( '[data-repeater-kind]' );
		var cfg    = kindEl ? SUMMARY[ kindEl.getAttribute( 'data-repeater-kind' ) ] : null;
		var box    = item.querySelector( '[data-repeater-summary]' );
		if ( ! cfg || ! box ) {
			return;
		}

		var titleSpec = cfg.title || { suffix: '[label]' };
		var title     = displayValue( fieldBySuffix( item, titleSpec.suffix ), titleSpec );
		var currency  = kindEl.getAttribute( 'data-currency' ) || '';

		var parts = [];
		cfg.meta.forEach( function ( spec ) {
			var value = displayValue( fieldBySuffix( item, spec.suffix ), spec );
			if ( ! value ) {
				return;
			}
			if ( spec.money ) {
				value = currency + value;
			}
			parts.push( ( spec.prefix || '' ) + value + ( spec.after || '' ) );
		} );

		box.innerHTML = '';
		var titleSpan = document.createElement( 'span' );
		titleSpan.className = 'wc-pricebook-repeater__summary-title' + ( title ? '' : ' is-empty' );
		titleSpan.textContent = title || cfg.empty;
		box.appendChild( titleSpan );

		if ( parts.length ) {
			var metaSpan = document.createElement( 'span' );
			metaSpan.className = 'wc-pricebook-repeater__summary-meta';
			metaSpan.textContent = parts.join( '  ·  ' );
			box.appendChild( metaSpan );
		}
	}

	function setOpen( item, open ) {
		item.classList.toggle( 'is-open', open );
		var toggle = item.querySelector( '[data-repeater-toggle]' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	/* -------------------------------------------------- Repeater add/remove */

	function initRepeater( repeater ) {
		var template = repeater.querySelector( '[data-repeater-template]' );
		var list     = repeater.querySelector( '[data-repeater-list]' );
		var addBtn   = repeater.querySelector( '[data-repeater-add]' );

		if ( ! template || ! list || ! addBtn ) {
			return;
		}

		// Existing rows: summarize and start collapsed for a scannable list.
		Array.prototype.forEach.call( list.querySelectorAll( '[data-repeater-item]' ), function ( item ) {
			refreshSummary( item );
			setOpen( item, false );
		} );

		// Start the index past any server-rendered rows to avoid name clashes.
		var nextIndex = list.querySelectorAll( '[data-repeater-item]' ).length;

		addBtn.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex++;

			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = html.trim();
			var row = wrapper.firstElementChild;
			if ( ! row ) {
				return;
			}
			list.appendChild( row );

			// Enhance any WooCommerce select in the new row. WC's handler only
			// touches selects not yet marked .enhanced, so existing rows are left
			// untouched.
			if ( window.jQuery && row.querySelector( '.wc-customer-search, .wc-product-search, .wc-enhanced-select' ) ) {
				window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
			}

			refreshSummary( row );
			setOpen( row, true ); // New rows open, ready to edit.

			var firstField = row.querySelector( '.wc-pricebook-repeater__body input, .wc-pricebook-repeater__body select, .wc-pricebook-repeater__body textarea' );
			if ( firstField ) {
				firstField.focus();
			}
		} );
	}

	/* ---------------------------------------------------- Delegated events */

	function onClick( event ) {
		var toggle = event.target.closest ? event.target.closest( '[data-repeater-toggle]' ) : null;
		if ( toggle ) {
			var openItem = toggle.closest( '[data-repeater-item]' );
			if ( openItem ) {
				setOpen( openItem, ! openItem.classList.contains( 'is-open' ) );
			}
			return;
		}

		var removeBtn = event.target.closest ? event.target.closest( '[data-repeater-remove]' ) : null;
		if ( removeBtn ) {
			event.preventDefault();
			var item = removeBtn.closest( '[data-repeater-item]' );
			if ( item ) {
				item.parentNode.removeChild( item );
			}
		}
	}

	// Keep a card's summary in sync as its fields change.
	function onFieldChange( event ) {
		var item = event.target.closest ? event.target.closest( '[data-repeater-item]' ) : null;
		if ( item ) {
			refreshSummary( item );
		}
	}

	// Show/hide a category set's checkboxes: hidden when "all" is selected.
	function onModeChange( event ) {
		var radio = event.target;
		if ( ! radio || radio.type !== 'radio' || ! radio.hasAttribute( 'data-catset-mode' ) ) {
			return;
		}
		var fieldset = radio.closest( '.wc-pricebook-catset' );
		if ( ! fieldset ) {
			return;
		}
		var categories = fieldset.querySelector( '[data-catset-categories]' );
		if ( categories ) {
			categories.hidden = ( radio.value === 'all' );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		Array.prototype.forEach.call( document.querySelectorAll( '[data-repeater]' ), initRepeater );
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'input', onFieldChange );
		document.addEventListener( 'change', function ( event ) {
			onFieldChange( event );
			onModeChange( event );
		} );
	} );
} )();
