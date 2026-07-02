<?php
/**
 * Tests for the WooCommerce price filters, focused on the bundle/composite container
 * guard: Pricebook must never reprice a bundle container (its own plugin owns that),
 * while still pricing ordinary products.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

use WCPricebook\WooHooks;

/**
 * @covers \WCPricebook\WooHooks
 */
class WooHooksTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var WooHooks
	 */
	private $hooks;

	/**
	 * Build the subject and a dealer current user against a product with a dealer price.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->hooks = new WooHooks( $this->config, $this->context, $this->engine );

		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
	}

	public function test_dynamically_priced_bundle_is_left_untouched() {
		// A bundle with individually-priced items is priced by Product Bundles.
		$bundle = new FakeProduct( 10, 'bundle', true );

		// Every price filter must pass the incoming value straight through.
		$this->assertSame( 999, $this->hooks->get_price( 999, $bundle ) );
		$this->assertSame( 999, $this->hooks->get_regular_price( 999, $bundle ) );
		$this->assertSame( 555, $this->hooks->get_sale_price( 555, $bundle ) );
		$this->assertSame( '<span>BUNDLE</span>', $this->hooks->bulk_price_html( '<span>BUNDLE</span>', $bundle ) );
	}

	public function test_statically_priced_bundle_gets_pricebook_pricing() {
		// A bundle with NO individually-priced items is priced by its own regular/sale
		// price, so Pricebook applies the dealer tier just like a normal product.
		$bundle = new FakeProduct( 10, 'bundle', false );
		$this->assertSame( '70', (string) $this->hooks->get_price( 999, $bundle ) );
	}

	public function test_dynamically_priced_composite_is_left_untouched() {
		$composite = new FakeProduct( 10, 'composite', true );
		$this->assertSame( 999, $this->hooks->get_price( 999, $composite ) );
	}

	public function test_simple_product_is_still_priced_by_the_engine() {
		// Contrast: a normal product is repriced to the dealer tier (not pass-through).
		$simple = new FakeProduct( 10, 'simple' );
		$this->assertSame( '70', (string) $this->hooks->get_price( 999, $simple ) );
	}
}
