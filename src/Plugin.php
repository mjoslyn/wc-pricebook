<?php
/**
 * Plugin container and bootstrap.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

use WCPricebook\Admin\Settings;
use WCPricebook\Admin\UserProfile;
use WCPricebook\Admin\ProductMeta;
use WCPricebook\Switcher\Switcher;
use WCPricebook\Flowchart\Flowchart;
use WCPricebook\ProductPrices\ProductPrices;
use WCPricebook\Export\ExportModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central singleton wiring services and modules together.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Context.
	 *
	 * @var Context
	 */
	private $context;

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	private $rules;

	/**
	 * Price engine.
	 *
	 * @var PriceEngine
	 */
	private $engine;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get (and lazily build) the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build core services.
	 */
	private function __construct() {
		$this->config  = new Config();
		$this->context = new Context( $this->config );
		$this->rules   = new Rules( $this->config );
		$this->engine  = new PriceEngine( $this->config, $this->context, $this->rules );
	}

	/**
	 * Config accessor.
	 *
	 * @return Config
	 */
	public function config() {
		return $this->config;
	}

	/**
	 * Context accessor.
	 *
	 * @return Context
	 */
	public function context() {
		return $this->context;
	}

	/**
	 * Engine accessor (public API for hosts/tests).
	 *
	 * @return PriceEngine
	 */
	public function engine() {
		return $this->engine;
	}

	/**
	 * Register hooks and modules. Idempotent.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new WooHooks( $this->config, $this->context, $this->engine ) )->register();
		( new Shortcodes( $this->config, $this->context, $this->engine ) )->register();

		if ( is_admin() ) {
			( new Settings( $this->config ) )->register();
			( new UserProfile( $this->config, $this->context ) )->register();
			// The product-pricing editor is optional: a host that already manages the
			// tier price meta with another tool (e.g. a JetEngine/ACF meta box) should
			// disable it so the two editors don't fight over the same meta keys on save.
			if ( $this->config->module_enabled( 'product_meta' ) ) {
				( new ProductMeta( $this->config ) )->register();
			}
		}

		if ( $this->config->module_enabled( 'switcher' ) ) {
			( new Switcher( $this->config, $this->context, $this->engine ) )->register();
		}

		if ( $this->config->module_enabled( 'flowchart' ) ) {
			( new Flowchart( $this->config, $this->context, $this->engine, $this->rules ) )->register();
		}

		if ( $this->config->module_enabled( 'product_prices' ) ) {
			( new ProductPrices( $this->config, $this->context, $this->engine ) )->register();
		}

		// Pricelist CSV export (WP-CLI + cron + settings "Send now"). Always registered:
		// cron and WP-CLI run with no admin context, and the schedule syncs on save.
		( new ExportModule( $this->config, $this->engine ) )->register();

		// Product-catalog PDF (shortcode + download endpoint). Always constructed but
		// self-gates on the wc_pricebook_catalog_pdf_enabled filter, so it stays inert
		// unless opted in. The "Catalog PDF" module toggle feeds that filter; a host can
		// still force it on with its own filter (this only turns it on, never off).
		add_filter(
			'wc_pricebook_catalog_pdf_enabled',
			function ( $enabled ) {
				return $this->config->module_enabled( 'catalog_pdf' ) ? true : $enabled;
			}
		);
		( new \WCPricebook\CatalogPdf\CatalogPdf( $this->config, $this->context, $this->engine ) )->register();
	}

	/**
	 * Activation: seed default config and flush rewrite rules.
	 *
	 * @return void
	 */
	public static function on_activation() {
		if ( false === get_option( Config::OPTION, false ) ) {
			add_option( Config::OPTION, Config::defaults() );
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: flush rewrite rules.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( ExportModule::CRON_HOOK );
		// Drop any in-progress background export (queued batches + its state).
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( ExportModule::BATCH_HOOK );
		}
		delete_option( ExportModule::STATE_OPTION );
		delete_option( ExportModule::PRODUCTS_OPTION );
		flush_rewrite_rules();
	}
}
