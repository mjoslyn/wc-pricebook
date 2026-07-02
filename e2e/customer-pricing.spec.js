// @ts-check
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const { orderTotalText, loginAs, productPriceInfo, archiveProductSlugs } = require( './helpers' );

/**
 * Storefront pricing for non-manager customers with an assigned pricing role,
 * including a tier layered on a non-MSRP tier.
 *
 * Fixtures (e2e/setup-fixtures.php, applied in beforeAll):
 *   - Product #10: MSRP $100.
 *   - "wholesale" tier: 0.5 x MSRP = $50.
 *   - "distributor" tier: 0.8 x the (non-MSRP) wholesale price = $40.
 *   - "PB Custom Product": MSRP $200 with an explicit wholesale price of $65.
 *   - Customers: pb_wholesale, pb_distributor, pb_mix (all assigned roles).
 */

const PRODUCT_ID = 10;

/** @type {number} Custom product ID, resolved from the fixtures output. */
let customProductId;
/** @type {number} "Scoped" product category term ID. */
let scopedTermId;

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	const file = '/var/www/html/wp-content/plugins/' + path.basename( path.resolve( __dirname, '..' ) ) + '/e2e/setup-fixtures.php';
	const out = execSync( `npx wp-env run cli wp eval-file ${ file }`, { encoding: 'utf8' } );
	customProductId = Number( ( out.match( /custom_product_id=(\d+)/ ) || [] )[ 1 ] || 0 );
	scopedTermId = Number( ( out.match( /scoped_term_id=(\d+)/ ) || [] )[ 1 ] || 0 );
} );

/**
 * Log a customer in, add the product, and return the cart order total.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} username
 * @returns {Promise<string>}
 */
async function cartTotalForCustomer( browser, username ) {
	const { context, page } = await loginAs( browser, username );
	try {
		// Non-managers do not get the pricing-view switcher.
		await expect( page.locator( '#wp-admin-bar-wc-pricebook-switcher' ) ).toHaveCount( 0 );

		await page.goto( `/?add-to-cart=${ PRODUCT_ID }`, { waitUntil: 'domcontentloaded' } );
		return await orderTotalText( page );
	} finally {
		await context.close();
	}
}

test( 'assigned wholesale customer is priced at the wholesale price', async ( { browser } ) => {
	const total = await cartTotalForCustomer( browser, 'pb_wholesale' );
	expect( total ).toContain( '50' );
	expect( total ).not.toContain( '100' );
} );

test( 'tier layered on a non-MSRP tier prices off that tier (distributor off wholesale)', async ( { browser } ) => {
	const total = await cartTotalForCustomer( browser, 'pb_distributor' );
	expect( total ).toContain( '40' );
	expect( total ).not.toContain( '100' );
} );

test( 'cart mixes a global-tier product and a custom-priced product', async ( { browser } ) => {
	expect( customProductId ).toBeGreaterThan( 0 );

	const { context, page } = await loginAs( browser, 'pb_mix' );
	try {
		// Product #10 is priced by the global wholesale tier (computed $50);
		// the custom product carries an explicit wholesale price ($65).
		await page.goto( `/?add-to-cart=${ PRODUCT_ID }`, { waitUntil: 'domcontentloaded' } );
		await page.goto( `/?add-to-cart=${ customProductId }`, { waitUntil: 'domcontentloaded' } );

		// Order total = $50 (global) + $65 (custom) = $115.
		expect( await orderTotalText( page ) ).toContain( '115' );
	} finally {
		await context.close();
	}
} );

test( 'include-mode tier prices only products in its category', async ( { browser } ) => {
	const { context, page } = await loginAs( browser, 'pb_catinc' );
	try {
		// In the scoped category → tier applies (50% off, on sale).
		const inCat = await productPriceInfo( page, 'pb-in-category' );
		expect( inCat.onSale ).toBe( true );
		expect( inCat.priceText ).toContain( '50' );

		// Outside the category → tier does not apply, plain MSRP, no discount.
		const outCat = await productPriceInfo( page, 'pb-out-category' );
		expect( outCat.onSale ).toBe( false );
		expect( outCat.priceText ).toContain( '100' );
		expect( outCat.priceText ).not.toContain( '50' );
	} finally {
		await context.close();
	}
} );

test( 'exclude-mode tier prices products outside its category', async ( { browser } ) => {
	const { context, page } = await loginAs( browser, 'pb_catexc' );
	try {
		// In the excluded category → tier does not apply, plain MSRP.
		const inCat = await productPriceInfo( page, 'pb-in-category' );
		expect( inCat.onSale ).toBe( false );
		expect( inCat.priceText ).toContain( '100' );
		expect( inCat.priceText ).not.toContain( '50' );

		// Outside the excluded category → tier applies (50% off, on sale).
		const outCat = await productPriceInfo( page, 'pb-out-category' );
		expect( outCat.onSale ).toBe( true );
		expect( outCat.priceText ).toContain( '50' );
	} finally {
		await context.close();
	}
} );

test( 'include-mode visibility role shows only products in its category', async ( { browser } ) => {
	const { context, page } = await loginAs( browser, 'pb_visinc' );
	try {
		const shop = await archiveProductSlugs( page );
		expect( shop ).toContain( 'pb-in-category' );
		expect( shop ).not.toContain( 'pb-out-category' );
		expect( shop ).not.toContain( 'pb-test-product' );

		// The category archive is also constrained: the included category shows.
		const scoped = await archiveProductSlugs( page, '/product-category/scoped/' );
		expect( scoped ).toContain( 'pb-in-category' );
	} finally {
		await context.close();
	}
} );

test( 'exclude-mode visibility role hides products in its category', async ( { browser } ) => {
	const { context, page } = await loginAs( browser, 'pb_visexc' );
	try {
		const shop = await archiveProductSlugs( page );
		expect( shop ).not.toContain( 'pb-in-category' );
		expect( shop ).toContain( 'pb-out-category' );
		expect( shop ).toContain( 'pb-test-product' );

		// The excluded category archive is empty for this user.
		const scoped = await archiveProductSlugs( page, '/product-category/scoped/' );
		expect( scoped ).not.toContain( 'pb-in-category' );
	} finally {
		await context.close();
	}
} );

test( 'price-gating UI binds a category so non-tier users see no price', async ( { browser } ) => {
	expect( scopedTermId ).toBeGreaterThan( 0 );

	// 1. Admin enables price gating for the Scoped category via the settings page.
	const admin = await loginAs( browser, 'admin' );
	try {
		await admin.page.goto( '/wp-admin/admin.php?page=wc-pricebook', { waitUntil: 'domcontentloaded' } );
		const checkbox = admin.page.locator(
			`input[name="wc_pricebook_config[rule_categories][price_requires_tier][]"][value="${ scopedTermId }"]`
		);
		await checkbox.check();
		await Promise.all( [
			admin.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			admin.page.locator( '#submit' ).click(),
		] );
		await expect( checkbox ).toBeChecked(); // persisted after save
	} finally {
		await admin.context.close();
	}

	// 2. A non-tier user sees no price on a product in the gated category.
	const guest = await loginAs( browser, 'pb_none' );
	try {
		await guest.page.goto( '/product/pb-in-category/', { waitUntil: 'networkidle' } );
		await expect(
			guest.page.locator( '.wp-block-woocommerce-product-price .woocommerce-Price-amount' )
		).toHaveCount( 0 );
	} finally {
		await guest.context.close();
	}

	// 3. A tier member still sees the price.
	const dealer = await loginAs( browser, 'pb_wholesale' );
	try {
		const info = await productPriceInfo( dealer.page, 'pb-in-category' );
		expect( info.priceText ).toContain( '50' );
	} finally {
		await dealer.context.close();
	}
} );
