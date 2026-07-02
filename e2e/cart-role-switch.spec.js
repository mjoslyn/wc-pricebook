// @ts-check
const { test, expect } = require( '@playwright/test' );
const { orderTotalText } = require( './helpers' );

/**
 * Verifies the cart reprices when a manager switches pricing role via the admin
 * toolbar switcher.
 *
 * Fixture state (see global-setup.js + the dev site):
 *   - Product #10 "PB Test Product": MSRP $100, no explicit wholesale price.
 *   - One tier "Wholesale": multiplier 0.5 off the MSRP base role → $50.
 *   - Admin is a manager; switcher starts at MSRP.
 */

const PRODUCT_ID = 10;

test( 'cart reprices when switching pricing role via the toolbar', async ( { page } ) => {
	// Add the test product (global-setup starts from an empty persistent cart).
	await page.goto( `/?add-to-cart=${ PRODUCT_ID }`, { waitUntil: 'domcontentloaded' } );

	// At MSRP the order total is the regular price ($100).
	expect( await orderTotalText( page ) ).toContain( '100' );

	// Switch to the Wholesale role via the admin-bar switcher. The submenu is
	// hidden until hover, and the switcher uses a delegated click handler, so
	// dispatch the click directly. It saves the role and reloads the page.
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.locator( '#wp-admin-bar-wc-pricebook-role-wholesale a.ab-item' ).dispatchEvent( 'click' ),
	] );

	// The cart now reflects the Wholesale price ($50 = 0.5 x MSRP).
	const switched = await orderTotalText( page );
	expect( switched ).toContain( '50' );
	expect( switched ).not.toContain( '100' );

	// On the product page the sale badge reflects the tier, not a generic "Sale!".
	await page.goto( '/product/pb-test-product/', { waitUntil: 'domcontentloaded' } );
	const badge = page.locator( '.onsale' ).first();
	await expect( badge ).toBeVisible();
	await expect( badge ).toHaveText( /Dealer Price/ );
} );
