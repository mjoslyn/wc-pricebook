<?php
/**
 * Shortcodes tests: bulk-pricing visibility follows the effective pricing view.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

/**
 * @covers \WCPricebook\Shortcodes
 */
class ShortcodesTest extends TestCase {

	/**
	 * @return \WCPricebook\Shortcodes
	 */
	private function shortcodes() {
		return new \WCPricebook\Shortcodes( $this->config, $this->context, $this->engine );
	}

	/**
	 * @return void
	 */
	private function seed_dealer_bulk_product() {
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'_pricebook_bulk_pricing' => array( 'dealer' => array( array( 'min_qty' => 10, 'price' => '65' ) ) ),
			)
		);
	}

	public function test_bulk_applies_for_current_user_with_bulk_tier() {
		$this->seed_dealer_bulk_product();
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$this->assertSame( '1', $this->shortcodes()->render_bulk_applies( array( 'product' => 10 ) ) );
	}

	public function test_bulk_hidden_for_user_without_bulk_tier() {
		$this->seed_dealer_bulk_product();
		Store::add_user( 6, array( 'customer' ), array() );
		Store::$current_user = 6;

		$this->assertSame( '', $this->shortcodes()->render_bulk_applies( array( 'product' => 10 ) ) );
	}

	public function test_bulk_follows_manager_switcher_role() {
		// A manager who is NOT a dealer, previewing as 'dealer' via the admin-bar
		// switcher, must see the bulk table — the shortcode reflects the switcher
		// role, not the manager's own roles.
		$this->seed_dealer_bulk_product();
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		$sc = $this->shortcodes();

		// No switcher: the manager holds no bulk tier of their own -> hidden.
		$this->assertSame( '', $sc->render_bulk_applies( array( 'product' => 10 ) ) );

		// Switcher set to 'dealer' (a role with a bulk break) -> shown.
		Store::$user_meta[9]['pricebook_switcher_role'] = 'dealer';
		$this->assertSame( '1', $sc->render_bulk_applies( array( 'product' => 10 ) ) );
	}
}
