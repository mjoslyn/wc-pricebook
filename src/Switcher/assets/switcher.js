/* global jQuery, wcPricebookSwitcher */
( function ( $ ) {
	'use strict';

	if ( typeof wcPricebookSwitcher === 'undefined' ) {
		return;
	}

	var cfg = wcPricebookSwitcher;

	function notify( message, type ) {
		var bg = type === 'success' ? '#00a32a' : type === 'error' ? '#d63638' : '#0073aa';
		var el = document.createElement( 'div' );
		el.className = 'wc-pricebook-notification';
		el.textContent = message;
		el.style.cssText =
			'position:fixed;top:40px;right:20px;background:' + bg +
			';color:#fff;padding:12px 20px;border-radius:4px;z-index:999999;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,.2);';
		document.body.appendChild( el );
		setTimeout( function () {
			if ( el.parentNode ) {
				el.parentNode.removeChild( el );
			}
		}, 3000 );
	}

	function updateButton( role ) {
		var label = cfg.labels[ role ] || cfg.labels.msrp || 'MSRP';
		var btn = document.querySelector( '#wp-admin-bar-wc-pricebook-switcher .ab-item' );
		if ( btn ) {
			btn.innerHTML = '<span class="ab-icon dashicons dashicons-money-alt"></span> ' + label;
		}
		document.querySelectorAll( '.wc-pricebook-role-item' ).forEach( function ( item ) {
			var itemRole = item.id.replace( 'wp-admin-bar-wc-pricebook-role-', '' );
			var link = item.querySelector( '.ab-item' );
			if ( ! link ) {
				return;
			}
			link.textContent = cfg.labels[ itemRole ] + ( itemRole === role ? ' ✓' : '' );
			item.classList.toggle( 'current-role', itemRole === role );
		} );
	}

	function switchUser( userId ) {
		$( '#wp-admin-bar-wc-pricebook-switcher' ).addClass( 'loading' );
		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			data: { action: cfg.switchUser, user: userId, nonce: cfg.nonce },
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					notify( 'Pricing view updated. Refreshing…', 'success' );
					setTimeout( function () {
						window.location.reload();
					}, 800 );
				} else {
					notify( 'Error switching pricing view', 'error' );
				}
			} )
			.fail( function () {
				notify( 'Network error', 'error' );
			} )
			.always( function () {
				$( '#wp-admin-bar-wc-pricebook-switcher' ).removeClass( 'loading' );
			} );
	}

	function renderResults( items ) {
		var $list = $( '.wc-pricebook-modal__results' );
		$list.empty();
		if ( ! items.length ) {
			$list.append( $( '<li class="wc-pricebook-modal__empty"></li>' ).text( cfg.i18n.noResults ) );
			return;
		}
		items.forEach( function ( item ) {
			$list.append(
				$( '<li class="wc-pricebook-modal__result"></li>' ).attr( 'data-user', item.id ).text( item.text )
			);
		} );
	}

	function searchUsers( term ) {
		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'GET',
			data: { action: cfg.searchUser, q: term, nonce: cfg.nonce },
		} )
			.done( function ( response ) {
				renderResults( ( response && response.data ) || [] );
			} )
			.fail( function () {
				notify( 'Customer search failed', 'error' );
			} );
	}

	function buildModal() {
		if ( document.getElementById( 'wc-pricebook-modal' ) ) {
			return;
		}
		var $modal = $(
			'<div class="wc-pricebook-modal-overlay" id="wc-pricebook-modal">' +
				'<div class="wc-pricebook-modal" role="dialog" aria-modal="true">' +
					'<div class="wc-pricebook-modal__head">' +
						'<h2 class="wc-pricebook-modal__title"></h2>' +
						'<button type="button" class="wc-pricebook-modal__close">&times;</button>' +
					'</div>' +
					'<p class="wc-pricebook-modal__current" hidden>' +
						'<span></span> <strong></strong> ' +
						'<a href="#" class="wc-pricebook-modal__clear"></a>' +
					'</p>' +
					'<input type="search" class="wc-pricebook-modal__search" autocomplete="off">' +
					'<ul class="wc-pricebook-modal__results"></ul>' +
				'</div>' +
			'</div>'
		);

		$modal.find( '.wc-pricebook-modal__title' ).text( cfg.i18n.title );
		$modal.find( '.wc-pricebook-modal__close' ).attr( 'aria-label', cfg.i18n.close );
		$modal.find( '.wc-pricebook-modal__search' ).attr( 'placeholder', cfg.i18n.placeholder );

		if ( cfg.impersonating && cfg.impersonatingLabel ) {
			$modal.find( '.wc-pricebook-modal__current' ).prop( 'hidden', false );
			$modal.find( '.wc-pricebook-modal__current span' ).text( cfg.i18n.previewing + ':' );
			$modal.find( '.wc-pricebook-modal__current strong' ).text( cfg.impersonatingLabel );
			$modal.find( '.wc-pricebook-modal__clear' ).text( cfg.i18n.clear );
		}

		$( 'body' ).append( $modal );
	}

	function openModal() {
		buildModal();
		$( '#wc-pricebook-modal' ).addClass( 'is-open' );
		setTimeout( function () {
			$( '.wc-pricebook-modal__search' ).trigger( 'focus' );
		}, 50 );
	}

	function closeModal() {
		$( '#wc-pricebook-modal' ).removeClass( 'is-open' );
	}

	function switchRole( role ) {
		// When previewing as a customer, allow re-selecting the same role to clear it.
		if ( ! role || ( role === cfg.currentRole && ! cfg.impersonating ) ) {
			return;
		}
		$( '#wp-admin-bar-wc-pricebook-switcher' ).addClass( 'loading' );

		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			data: { action: cfg.action, role: role, nonce: cfg.nonce },
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					cfg.currentRole = role;
					updateButton( role );
					notify( 'Pricing view updated. Refreshing…', 'success' );
					setTimeout( function () {
						window.location.reload();
					}, 800 );
				} else {
					notify( 'Error switching pricing view', 'error' );
				}
			} )
			.fail( function () {
				notify( 'Network error', 'error' );
			} )
			.always( function () {
				$( '#wp-admin-bar-wc-pricebook-switcher' ).removeClass( 'loading' );
			} );
	}

	$( function () {
		$( document ).on( 'click', '.wc-pricebook-role-item .ab-item', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var item = $( this ).closest( '.wc-pricebook-role-item' );
			var role = item.attr( 'id' ).replace( 'wp-admin-bar-wc-pricebook-role-', '' );
			switchRole( role );
		} );

		// Open the modal from the toolbar item.
		$( document ).on( 'click', '.wc-pricebook-user-switch-trigger .ab-item', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			openModal();
		} );

		var timer;
		$( document ).on( 'input', '.wc-pricebook-modal__search', function () {
			var q = $.trim( this.value );
			clearTimeout( timer );
			if ( q.length < 2 ) {
				$( '.wc-pricebook-modal__results' ).empty();
				return;
			}
			timer = setTimeout( function () {
				searchUsers( q );
			}, 250 );
		} );

		$( document ).on( 'click', '.wc-pricebook-modal__result', function () {
			switchUser( $( this ).attr( 'data-user' ) );
		} );

		$( document ).on( 'click', '.wc-pricebook-modal__clear', function ( e ) {
			e.preventDefault();
			switchUser( 0 );
		} );

		// Close on the × button or a click on the backdrop (but not the dialog body).
		$( document ).on( 'click', '.wc-pricebook-modal__close, .wc-pricebook-modal-overlay', function ( e ) {
			if ( e.target === this ) {
				closeModal();
			}
		} );

		$( document ).on( 'keyup', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeModal();
			}
		} );
	} );
} )( jQuery );
