/**
 * Repeater behavior for the Pricebook settings page.
 *
 * Generic add/remove rows: any container marked [data-repeater] holds rows
 * marked [data-repeater-item], an inert <template data-repeater-template> with
 * one blank row, an [data-repeater-add] button, and per-row
 * [data-repeater-remove] buttons. New rows substitute the running index into the
 * "__INDEX__" placeholder in field names so each row posts under a unique key.
 *
 * No build step: plain DOM, no dependencies.
 */
( function () {
	'use strict';

	/**
	 * Wire a single repeater container.
	 *
	 * @param {HTMLElement} repeater Container element.
	 */
	function initRepeater( repeater ) {
		var template = repeater.querySelector( '[data-repeater-template]' );
		var list     = repeater.querySelector( '[data-repeater-list]' );
		var addBtn   = repeater.querySelector( '[data-repeater-add]' );

		if ( ! template || ! list || ! addBtn ) {
			return;
		}

		// Start the index past any server-rendered rows to avoid name clashes.
		var nextIndex = list.querySelectorAll( '[data-repeater-item]' ).length;

		addBtn.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex++;

			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = html.trim();
			var row = wrapper.firstElementChild;
			if ( row ) {
				list.appendChild( row );

				// Enhance any WooCommerce select (customer/product search or a plain
				// enhanced-select) in the new row. WC's handler only touches selects
				// not yet marked .enhanced, so re-triggering leaves existing rows
				// untouched.
				if ( window.jQuery && row.querySelector( '.wc-customer-search, .wc-product-search, .wc-enhanced-select' ) ) {
					window.jQuery( document.body ).trigger( 'wc-enhanced-select-init' );
				}

				var firstField = row.querySelector( 'input, select, textarea' );
				if ( firstField ) {
					firstField.focus();
				}
			}
		} );
	}

	/**
	 * Remove the row a clicked remove-button belongs to (event delegation so it
	 * works for dynamically added rows too).
	 *
	 * @param {Event} event Click event.
	 */
	function onClick( event ) {
		var btn = event.target.closest ? event.target.closest( '[data-repeater-remove]' ) : null;
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var item = btn.closest( '[data-repeater-item]' );
		if ( item ) {
			item.parentNode.removeChild( item );
		}
	}

	/**
	 * Show/hide a category set's checkboxes based on its selected mode: the list is
	 * hidden when "all" is chosen. Delegated so it works for added rows too.
	 *
	 * @param {Event} event Change event.
	 */
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
		var repeaters = document.querySelectorAll( '[data-repeater]' );
		Array.prototype.forEach.call( repeaters, initRepeater );
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'change', onModeChange );
	} );
} )();
