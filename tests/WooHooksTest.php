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

	public function test_bulk_cart_price_survives_the_get_price_filter() {
		// 10+ units get a dealer bulk price of 55, below the 70 per-unit dealer price.
		$this->set_meta(
			10,
			array(
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 0, 'price' => '55' ) ),
				),
			)
		);
		$product = new FakeProduct( 10, 'simple' );

		$this->hooks->apply_bulk_cart_pricing( $this->fake_cart( array( array( 'data' => $product, 'quantity' => 10 ) ) ) );

		// The line was repriced for its quantity...
		$this->assertSame( '55', (string) $product->get_price() );
		// ...and get_price honors that instead of recomputing the per-unit dealer price
		// (70), so the quantity break survives totals calculation. This is the bug the
		// cart-priced marker fixes: without it get_price would return '70' here.
		$this->assertSame( '55', (string) $this->hooks->get_price( $product->get_price(), $product ) );

		// Honoring is scoped to the cart-line instance: another instance of the same
		// product (e.g. rendered in the catalog) is still repriced to the per-unit price.
		$other = new FakeProduct( 10, 'simple' );
		$this->assertSame( '70', (string) $this->hooks->get_price( 999, $other ) );
	}

	public function test_bulk_cart_below_threshold_uses_the_per_unit_price() {
		// Same 10-unit break, but only 5 in the cart: no break applies.
		$this->set_meta(
			10,
			array(
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 0, 'price' => '55' ) ),
				),
			)
		);
		$product = new FakeProduct( 10, 'simple' );

		$this->hooks->apply_bulk_cart_pricing( $this->fake_cart( array( array( 'data' => $product, 'quantity' => 5 ) ) ) );

		$this->assertSame( '70', (string) $product->get_price() );
		$this->assertSame( '70', (string) $this->hooks->get_price( $product->get_price(), $product ) );
	}

	public function test_session_restore_is_quantity_aware_for_bulk() {
		// The mini-cart renders a line's per-unit price BEFORE calculate_totals runs, so
		// the session-restored price must already reflect the quantity break — otherwise
		// the per-unit line shows the non-bulk price while the subtotal shows the bulk one.
		$this->set_meta(
			10,
			array(
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 0, 'price' => '55' ) ),
				),
			)
		);

		// 13 units -> the bulk per-unit price (55), not the plain dealer price (70).
		$product  = new FakeProduct( 10, 'simple' );
		$restored = $this->hooks->set_cart_item_price_from_session( array( 'data' => $product ), array( 'quantity' => 13 ), 'key' );
		$this->assertSame( '55', (string) $restored['data']->get_price() );

		// Below the break -> the plain per-unit price.
		$below    = new FakeProduct( 10, 'simple' );
		$restored = $this->hooks->set_cart_item_price_from_session( array( 'data' => $below ), array( 'quantity' => 5 ), 'key' );
		$this->assertSame( '70', (string) $restored['data']->get_price() );
	}

	/**
	 * A minimal WC_Cart stand-in exposing the get_cart() shape apply_bulk_cart_pricing
	 * iterates: a list of items each with a 'data' product and a 'quantity'.
	 *
	 * @param array<int,array<string,mixed>> $items Cart items.
	 * @return object
	 */
	private function fake_cart( array $items ) {
		return new class( $items ) {
			/** @var array<int,array<string,mixed>> */
			private $items;

			/**
			 * @param array<int,array<string,mixed>> $items Cart items.
			 */
			public function __construct( array $items ) {
				$this->items = $items;
			}

			/**
			 * @return array<int,array<string,mixed>>
			 */
			public function get_cart() {
				return $this->items;
			}
		};
	}
}
