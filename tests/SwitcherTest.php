<?php
/**
 * Switcher tests: which role the toolbar reports as current.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

use WCPricebook\Switcher\Switcher;

/**
 * @covers \WCPricebook\Switcher\Switcher
 */
class SwitcherTest extends TestCase {

	/**
	 * Switcher under test.
	 *
	 * @var Switcher
	 */
	private $switcher;

	protected function setUp(): void {
		parent::setUp();
		$this->switcher = new Switcher( $this->config, $this->context, $this->engine );
	}

	public function test_saved_selection_is_current_role() {
		Store::add_user( 9, array( 'shop_manager', 'dealer' ), array( 'manage_woocommerce', 'dealer' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'operator';
		$this->assertSame( 'operator', $this->switcher->current_role() );
	}

	public function test_saved_msrp_selection_is_current_role() {
		Store::add_user( 9, array( 'shop_manager', 'dealer' ), array( 'manage_woocommerce', 'dealer' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'msrp';
		$this->assertSame( 'msrp', $this->switcher->current_role() );
	}

	public function test_no_selection_reports_managers_own_tier() {
		// With nothing saved, pricing falls through to the manager's own tier, so
		// the toolbar must say so rather than claim MSRP.
		Store::add_user( 9, array( 'shop_manager', 'dealer' ), array( 'manage_woocommerce', 'dealer' ) );
		Store::$current_user = 9;
		$this->assertSame( 'dealer', $this->switcher->current_role() );
	}

	public function test_no_selection_and_no_tier_reports_msrp() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		$this->assertSame( 'msrp', $this->switcher->current_role() );
	}

	public function test_unknown_saved_selection_falls_back_to_own_tier() {
		Store::add_user( 9, array( 'shop_manager', 'dealer' ), array( 'manage_woocommerce', 'dealer' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'retired_tier';
		$this->assertSame( 'dealer', $this->switcher->current_role() );
	}
}
