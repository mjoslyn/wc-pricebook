// @ts-check
const { expect } = require( '@playwright/test' );

const BASE_URL = 'http://localhost:8888';

/**
 * Read the cart's order total, tolerating both the Cart block and the classic
 * shortcode cart markup. The block renders totals asynchronously.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function orderTotalText( page ) {
	await page.goto( '/cart/', { waitUntil: 'domcontentloaded' } );

	const block = page.locator( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' ).first();
	const classic = page.locator( '.order-total .woocommerce-Price-amount' ).first();

	await Promise.race( [
		block.waitFor( { state: 'visible', timeout: 20000 } ).catch( () => {} ),
		classic.waitFor( { state: 'visible', timeout: 20000 } ).catch( () => {} ),
	] );

	if ( await block.count() ) {
		return ( await block.innerText() ).trim();
	}
	if ( await classic.count() ) {
		return ( await classic.innerText() ).trim();
	}
	throw new Error( 'Could not locate the cart order total.' );
}

/**
 * Open a fresh logged-in browser context for a user (no shared cart/session).
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} username
 * @param {string} [password]
 * @returns {Promise<{ context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page }>}
 */
async function loginAs( browser, username, password = 'password' ) {
	const context = await browser.newContext( { baseURL: BASE_URL } );
	const page = await context.newPage();
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
	// A failed login re-renders wp-login.php; leaving it confirms success.
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
	return { context, page };
}

/**
 * Read a single product page's displayed price text and whether it is on sale.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug Product slug.
 * @returns {Promise<{ priceText: string, onSale: boolean }>}
 */
async function productPriceInfo( page, slug ) {
	await page.goto( `/product/${ slug }/`, { waitUntil: 'domcontentloaded' } );
	const price = page.locator( '.wp-block-woocommerce-product-price, .product .price, .summary .price' ).first();
	await price.waitFor( { state: 'visible', timeout: 15000 } );
	const priceText = ( await price.innerText() ).replace( /\s+/g, ' ' ).trim();
	const onSale = ( await page.locator( '.onsale' ).count() ) > 0;
	return { priceText, onSale };
}

/**
 * The product slugs listed on a product archive page (Shop by default, or any
 * product taxonomy archive path).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [path] Archive path, e.g. '/shop/' or '/product-category/scoped/'.
 * @returns {Promise<string[]>}
 */
async function archiveProductSlugs( page, path = '/shop/' ) {
	await page.goto( path, { waitUntil: 'networkidle' } );
	const hrefs = await page.locator( 'a[href*="/product/"]' ).evaluateAll(
		( els ) => [ ...new Set( els.map( ( e ) => e.getAttribute( 'href' ) ) ) ]
	);
	return hrefs
		.map( ( href ) => ( href.match( /\/product\/([^/]+)\// ) || [] )[ 1 ] )
		.filter( Boolean );
}

module.exports = { orderTotalText, loginAs, productPriceInfo, archiveProductSlugs, BASE_URL };
