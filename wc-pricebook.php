<?php
/**
 * Plugin Name:       WC Pricebook
 * Plugin URI:        https://github.com/mjoslyn/wc-pricebook
 * Description:       Role/tier-based pricing engine for WooCommerce. Configurable price tiers, rule-based product behaviors, and a manager pricing-view switcher.
 * Version:           0.5.12
 * Author:            Robot of the Future
 * Author URI:        https://github.com/mjoslyn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-pricebook
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Update URI:        https://github.com/mjoslyn/wc-pricebook
 * GitHub Plugin URI: mjoslyn/wc-pricebook
 * Primary Branch:    main
 *
 * @package WCPricebook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_PRICEBOOK_VERSION', '0.5.12' );
define( 'WC_PRICEBOOK_FILE', __FILE__ );
define( 'WC_PRICEBOOK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PRICEBOOK_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloading.
 *
 * Prefer Composer's autoloader when present, otherwise register a minimal PSR-4
 * autoloader so the plugin runs without a `composer install` step (e.g. in wp-env).
 */
if ( file_exists( WC_PRICEBOOK_DIR . 'vendor/autoload.php' ) ) {
	require WC_PRICEBOOK_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'WCPricebook\\';
			$len    = strlen( $prefix );
			if ( 0 !== strncmp( $class, $prefix, $len ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, $len ) );
			$file     = WC_PRICEBOOK_DIR . 'src/' . $relative . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	);
}

/**
 * Declare HPOS (custom order tables) compatibility — this plugin only filters
 * product prices and never touches order storage directly.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded so WooCommerce is detectable.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'WC Pricebook requires WooCommerce to be installed and active.', 'wc-pricebook' );
					echo '</p></div>';
				}
			);
			return;
		}

		\WCPricebook\Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( '\\WCPricebook\\Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( '\\WCPricebook\\Plugin', 'on_deactivation' ) );
