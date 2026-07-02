<?php
/**
 * Standalone unit-test bootstrap.
 *
 * The pricing engine is pure logic over a handful of WordPress accessor functions
 * (post meta, terms, user caps, options). Rather than spin up the full WP + WC test
 * stack, we back those accessors with an in-memory store ({@see Store}) so the
 * engine's correctness can be tested quickly and deterministically, driven by the
 * same config it uses in production.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WC_PRICEBOOK_DIR' ) ) {
	define( 'WC_PRICEBOOK_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WC_PRICEBOOK_URL' ) ) {
	define( 'WC_PRICEBOOK_URL', 'http://example.test/wp-content/plugins/wc-pricebook/' );
}
if ( ! defined( 'WC_PRICEBOOK_VERSION' ) ) {
	define( 'WC_PRICEBOOK_VERSION', 'test' );
}

// WordPress function/class shims (a functions file, so require it directly).
require_once __DIR__ . '/wp-shims.php';

// PSR-4 autoloader for both the plugin (WCPricebook\ -> src/) and the test
// support classes (WCPricebook\Tests\ -> tests/).
spl_autoload_register(
	static function ( $class ) {
		$map = array(
			'WCPricebook\\Tests\\' => __DIR__ . '/',
			'WCPricebook\\'        => WC_PRICEBOOK_DIR . 'src/',
		);
		foreach ( $map as $prefix => $base ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $class, $prefix, $len ) ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $class, $len ) );
			$file     = $base . $relative . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
	}
);
