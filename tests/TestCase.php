<?php
/**
 * Base test case: resets the in-memory store and wires the services.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use WCPricebook\Config;
use WCPricebook\Context;
use WCPricebook\Rules;
use WCPricebook\PriceEngine;

/**
 * Shared setup for engine/context tests.
 */
abstract class TestCase extends BaseTestCase {

	/**
	 * Config.
	 *
	 * @var Config
	 */
	protected $config;

	/**
	 * Context.
	 *
	 * @var Context
	 */
	protected $context;

	/**
	 * Rules.
	 *
	 * @var Rules
	 */
	protected $rules;

	/**
	 * Engine.
	 *
	 * @var PriceEngine
	 */
	protected $engine;

	/**
	 * Reset state and build services from the test config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Store::reset();
		Store::$options[ Config::OPTION ] = $this->config_array();

		$this->config  = new Config();
		$this->context = new Context( $this->config );
		$this->rules   = new Rules( $this->config );
		$this->engine  = new PriceEngine( $this->config, $this->context, $this->rules );
	}

	/**
	 * The plugin configuration used by tests. A representative real-store shape
	 * (dealer tiers + operator + rules).
	 *
	 * @return array<string,mixed>
	 */
	protected function config_array() {
		return array(
			'tiers'             => array(
				'dealer'          => array(
					'key'         => 'dealer',
					'label'       => 'Dealer Base',
					'price_meta'  => 'dealer_price',
					'sale_meta'   => 'dealer_sale_price',
					'multiplier'  => 1.0,
					'base_meta'   => '',
					'fallback_to' => 'msrp',
				),
				// "half" is computed off MSRP (no stored price); "quarter" layers off
				// the (non-MSRP) "half" tier, so it chains off half's resolved price.
				'half'            => array(
					'key'         => 'half',
					'label'       => 'Half',
					'multiplier'  => 0.5,
					'base_meta'   => 'msrp',
					'fallback_to' => 'msrp',
				),
				'quarter'         => array(
					'key'         => 'quarter',
					'label'       => 'Quarter',
					'multiplier'  => 0.5,
					'base_meta'   => 'half',
					'fallback_to' => 'msrp',
				),
				'dealer_4'        => array(
					'key'         => 'dealer_4',
					'label'       => 'Dealer 4%',
					'price_meta'  => 'dealer_4_price',
					'sale_meta'   => 'dealer_4_sale_price',
					'multiplier'  => 0.96,
					'base_meta'   => 'dealer',
					'fallback_to' => 'msrp',
				),
				'operator'        => array(
					'key'         => 'operator',
					'label'       => 'Operator',
					'price_meta'  => 'operator_price',
					'sale_meta'   => 'operator_sale_price',
					'multiplier'  => 1.0,
					'base_meta'   => '',
					'fallback_to' => 'msrp',
					'override'    => 'when_priced',
				),
				'forced_dealer'   => array(
					'key'                => 'forced_dealer',
					'label'              => 'Forced Dealer',
					'price_meta'         => 'dealer_price',
					'sale_meta'          => 'dealer_sale_price',
					'multiplier'         => 1.0,
					'base_meta'          => 'dealer',
					'fallback_to'        => 'msrp',
					'override'           => 'always',
					'pricing_categories' => array( 'mode' => 'include', 'categories' => array( 777 ) ),
				),
				'forced_msrp'     => array(
					'key'                => 'forced_msrp',
					'label'              => 'Forced MSRP',
					'price_meta'         => '',
					'sale_meta'          => '',
					'multiplier'         => 1.0,
					'base_meta'          => '',
					'fallback_to'        => 'msrp',
					'override'           => 'always',
					'pricing_categories' => array( 'mode' => 'include', 'categories' => array( 777 ) ),
				),
				'scoped_inc'      => array(
					'key'                   => 'scoped_inc',
					'label'                 => 'Scoped (include)',
					'price_meta'            => 'scoped_price',
					'sale_meta'             => 'scoped_sale_price',
					'multiplier'            => 1.0,
					'base_meta'             => '',
					'fallback_to'           => 'msrp',
					'pricing_categories'    => array( 'mode' => 'include', 'categories' => array( 555 ) ),
				),
				'plain'           => array(
					'key'   => 'plain',
					'label' => 'Plain',
					// No explicit price_meta: the engine reads a derived key.
				),
				'scoped_exc'      => array(
					'key'                   => 'scoped_exc',
					'label'                 => 'Scoped (exclude)',
					'price_meta'            => 'scoped2_price',
					'sale_meta'             => 'scoped2_sale_price',
					'multiplier'            => 1.0,
					'base_meta'             => '',
					'fallback_to'           => 'msrp',
					'pricing_categories'    => array( 'mode' => 'exclude', 'categories' => array( 666 ) ),
				),
			),
			'rules'             => array(
				'skip_matrix'       => array( 'bindings' => array( array( 'taxonomy' => 'product_flag', 'terms' => array( 478 ) ) ) ),
				'no_tier_discount'  => array( 'bindings' => array( array( 'taxonomy' => 'product_flag', 'terms' => array( 363 ) ) ) ),
				'force_visible'     => array( 'bindings' => array( array( 'taxonomy' => 'product_flag', 'terms' => array( 432 ) ) ) ),
			),
			'visibility_roles'  => array(
				'region_a' => array(
					'key'        => 'region_a',
					'label'      => 'Region A',
					'roles'      => array( 'dealer' ),
					'match'      => 'any',
					'categories' => array( 'mode' => 'include', 'categories' => array( 555 ) ),
					'hide'       => 'product',
				),
				// The former price_requires_tier gate, now a Hide-Pricing visibility role:
				// MSRP customers (no tier) see "Call for Price" on category 999.
				'gate'     => array(
					'key'        => 'gate',
					'label'      => 'Gate',
					'roles'      => array( Context::MSRP_CUSTOMER ),
					'match'      => 'any',
					'categories' => array( 'mode' => 'include', 'categories' => array( 999 ) ),
					'hide'       => 'pricing',
				),
			),
			'modules'           => array(
				'switcher'  => true,
				'flowchart' => false,
			),
		);
	}

	/**
	 * Helper: set product meta.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string,mixed>  $meta       Meta map.
	 * @return void
	 */
	protected function set_meta( $product_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			Store::$post_meta[ $product_id ][ $key ] = $value;
		}
	}

	/**
	 * Helper: assign taxonomy terms to a product.
	 *
	 * @param int               $product_id Product ID.
	 * @param string            $taxonomy   Taxonomy.
	 * @param array<int,int>    $terms      Term IDs.
	 * @return void
	 */
	protected function set_terms( $product_id, $taxonomy, array $terms ) {
		Store::$post_terms[ $product_id ][ $taxonomy ] = $terms;
	}
}
