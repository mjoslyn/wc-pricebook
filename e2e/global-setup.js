// @ts-check
const { chromium } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE_URL = 'http://localhost:8888';
const AUTH_FILE = path.join( __dirname, '.auth', 'admin.json' );

/**
 * Reset server-side state so the cart test starts deterministically, then log in
 * as the admin (a manager) and persist the auth cookies for the test run.
 */
module.exports = async () => {
	const cli = ( cmd ) => {
		try {
			execSync( `npx wp-env run cli wp ${ cmd }`, { stdio: 'ignore' } );
		} catch ( e ) {
			// Meta may not exist yet; ignore.
		}
	};

	// Start the manager from a clean slate: no switcher selection and no assigned
	// pricing/visibility role (so the baseline price is MSRP), with an empty cart
	// (persistent cart meta + all WooCommerce sessions).
	cli( 'user meta delete admin pricebook_switcher_role' );
	cli( 'user meta delete admin pricebook_pricing_role' );
	cli( 'user meta delete admin pricebook_visibility_role' );
	cli( 'user meta delete admin _woocommerce_persistent_cart_1' );
	cli( 'db query "DELETE FROM wp_woocommerce_sessions"' );

	fs.mkdirSync( path.dirname( AUTH_FILE ), { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL: BASE_URL } );
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.click( '#wp-submit' ),
	] );
	await page.context().storageState( { path: AUTH_FILE } );
	await browser.close();
};
