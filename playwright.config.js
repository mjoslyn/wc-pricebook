// @ts-check
const { defineConfig } = require( '@playwright/test' );

/**
 * E2E config for the wp-env dev site (http://localhost:8888).
 *
 * Run with: npm run test:e2e   (wp-env must be running).
 */
module.exports = defineConfig( {
	testDir: './e2e',
	globalSetup: './e2e/global-setup.js',
	timeout: 60000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: 'http://localhost:8888',
		storageState: 'e2e/.auth/admin.json',
		headless: true,
		actionTimeout: 15000,
	},
} );
