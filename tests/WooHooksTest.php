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
		// And the get_price filter must HONOR it (the mini-cart reads the per-unit price
		// through this filter before calculate_totals runs) instead of recomputing the
		// per-unit dealer price (70). This is the mismatch users saw.
		$this->assertSame( '55', (string) $this->hooks->get_price( $restored['data']->get_price(), $restored['data'] ) );

		// Below the break -> the plain per-unit price.
		$below    = new FakeProduct( 10, 'simple' );
		$restored = $this->hooks->set_cart_item_price_from_session( array( 'data' => $below ), array( 'quantity' => 5 ), 'key' );
		$this->assertSame( '70', (string) $restored['data']->get_price() );
		$this->assertSame( '70', (string) $this->hooks->get_price( $restored['data']->get_price(), $restored['data'] ) );
	}

	/**
	 * A retail (no-tier) customer viewing product 20 in category 999, where the 'gate'
	 * visibility role hides pricing ("Call for Price").
	 *
	 * @return FakeProduct
	 */
	private function gated_product() {
		Store::add_user( 8, array( 'customer' ), array() );
		Store::$current_user = 8;
		$this->set_meta( 20, array( '_regular_price' => '100' ) );
		$this->set_terms( 20, 'product_cat', array( 999 ) );
		return new FakeProduct( 20 );
	}

	public function test_hidden_price_does_not_leak_through_the_regular_price() {
		$product = $this->gated_product();

		$this->assertSame( '', (string) $this->hooks->get_price( '100', $product ) );
		// Previously returned the MSRP ("100") here.
		$this->assertSame( '', (string) $this->hooks->get_regular_price( '100', $product ) );
		$this->assertSame( '', (string) $this->hooks->get_sale_price( '', $product ) );
	}

	public function test_hidden_price_does_not_leak_through_bulk_pricing() {
		$product = $this->gated_product();
		// A quantity break aimed at MSRP customers on the same product.
		$this->set_meta(
			20,
			array(
				'_pricebook_bulk_pricing' => array(
					\WCPricebook\Context::MSRP_CUSTOMER => array( array( 'min_qty' => 1, 'max_qty' => 0, 'price' => '80' ) ),
				),
			)
		);

		$this->assertTrue( $this->engine->price_hidden( 20 ) );
		$this->assertSame( '', (string) $this->engine->from_price( null, $product ) );
		$this->assertSame( '', (string) $this->engine->effective_price_qty( null, $product, 5 ) );
		$this->assertSame( 'HTML', $this->hooks->bulk_price_html( 'HTML', $product ) );
	}

	public function test_force_visible_rule_keeps_the_price_and_bulk_pricing() {
		$product = $this->gated_product();
		$this->set_terms( 20, 'product_flag', array( 432 ) ); // force_visible.

		$this->assertFalse( $this->engine->price_hidden( 20 ) );
		$this->assertSame( '100', (string) $this->hooks->get_regular_price( '100', $product ) );
	}

	public function test_variation_price_is_blanked_when_parent_price_is_hidden() {
		$this->gated_product();
		$variation = new class() {
			public function get_id() {
				return 21;
			}
			public function get_parent_id() {
				return 20;
			}
			public function get_type() {
				return 'variation';
			}
		};

		$this->assertSame( '', $this->hooks->hide_variation_price( '45', $variation ) );

		// A variation of a product whose price is not hidden is left untouched.
		$this->set_terms( 20, 'product_cat', array() );
		$this->assertSame( '45', $this->hooks->hide_variation_price( '45', $variation ) );
	}

	public function test_hidden_products_are_removed_from_product_queries() {
		// The 'region_a' visibility role hides category 555 from dealers (user 5).
		$this->set_terms( 30, 'product_cat', array( 555 ) );
		$this->set_terms( 31, 'product_cat', array( 123 ) );
		// Product 32 is in the hidden category but forces itself visible to dealers.
		$this->set_terms( 32, 'product_cat', array( 555 ) );
		$this->set_meta( 32, array( \WCPricebook\Context::FORCE_VISIBLE_ROLES_META => array( 'dealer' ) ) );

		$query = $this->fake_query( array( 'post_type' => 'product' ) );
		$this->hooks->filter_product_visibility( $query );
		$this->assertSame( array( 30 ), $query->get( 'post__not_in' ) );

		// post__in wins over post__not_in in WP_Query, so it is narrowed directly.
		$query = $this->fake_query( array( 'post_type' => 'product', 'post__in' => array( 30, 31 ) ) );
		$this->hooks->filter_product_visibility( $query );
		$this->assertSame( array( 31 ), $query->get( 'post__in' ) );

		$query = $this->fake_query( array( 'post_type' => 'product', 'post__in' => array( 30 ) ) );
		$this->hooks->filter_product_visibility( $query );
		$this->assertSame( array( 0 ), $query->get( 'post__in' ) );

		// Queries for other post types are not touched.
		$query = $this->fake_query( array( 'post_type' => 'nav_menu_item' ) );
		$this->hooks->filter_product_visibility( $query );
		$this->assertSame( '', $query->get( 'post__not_in' ) );

		// Hidden products are neither visible in loops nor purchasable.
		$this->assertFalse( $this->hooks->product_is_visible( true, 30 ) );
		$this->assertTrue( $this->hooks->product_is_visible( true, 32 ) );
		$this->assertFalse( $this->hooks->is_purchasable( true, new FakeProduct( 30 ) ) );
		$this->assertTrue( $this->hooks->is_purchasable( true, new FakeProduct( 31 ) ) );
	}

	public function test_managers_see_the_whole_catalog() {
		$this->set_terms( 30, 'product_cat', array( 555 ) );
		Store::add_user( 1, array( 'administrator', 'dealer' ), array( 'manage_woocommerce', 'dealer' ) );
		Store::$current_user = 1;

		$query = $this->fake_query( array( 'post_type' => 'product' ) );
		$this->hooks->filter_product_visibility( $query );
		$this->assertSame( '', $query->get( 'post__not_in' ) );
		$this->assertTrue( $this->hooks->product_is_visible( true, 30 ) );
	}

	/**
	 * A minimal WP_Query stand-in exposing get()/set().
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @return object
	 */
	private function fake_query( array $vars ) {
		return new class( $vars ) {
			/** @var array<string,mixed> */
			private $vars;

			/**
			 * @param array<string,mixed> $vars Query vars.
			 */
			public function __construct( array $vars ) {
				$this->vars = $vars;
			}

			/**
			 * @param string $key Query var.
			 * @return mixed
			 */
			public function get( $key ) {
				return $this->vars[ $key ] ?? '';
			}

			/**
			 * @param string $key   Query var.
			 * @param mixed  $value Value.
			 * @return void
			 */
			public function set( $key, $value ) {
				$this->vars[ $key ] = $value;
			}
		};
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
