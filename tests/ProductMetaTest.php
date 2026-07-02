<?php
/**
 * Tests for the product-meta admin save path, focused on the per-user (customer)
 * price override rows that feed {@see \WCPricebook\PriceEngine::price_for_user}.
 *
 * Exercises the real public entry point ({@see ProductMeta::save}) including its
 * nonce and capability guards, backed by the in-memory store.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

use WCPricebook\Admin\ProductMeta;

/**
 * @covers \WCPricebook\Admin\ProductMeta
 */
class ProductMetaTest extends TestCase {

	const META_KEY      = '_pricebook_user_pricing';
	const BULK_META_KEY = '_pricebook_bulk_pricing';
	const NONCE_FIELD   = 'wc_pricebook_product_meta_nonce';
	const PRODUCT_ID    = 10;

	/**
	 * Subject under test.
	 *
	 * @var ProductMeta
	 */
	private $product_meta;

	/**
	 * Build the subject and a privileged current user, then prime $_POST.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->product_meta = new ProductMeta( $this->config );

		// A user allowed to edit the product, with a valid nonce present.
		Store::add_user( 1, array(), array( 'edit_post' ) );
		Store::$current_user      = 1;
		$_POST                    = array();
		$_POST[ self::NONCE_FIELD ] = 'valid';
	}

	/**
	 * Clear request superglobal between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * The stored override meta for the product under test.
	 *
	 * @return mixed
	 */
	private function stored_pricing() {
		return get_post_meta( self::PRODUCT_ID, self::META_KEY, true );
	}

	public function test_save_persists_customer_rows() {
		$_POST['pricebook_user_price'] = array(
			array( 'user-id' => '5', 'price' => '42' ),
			array( 'user-id' => '8', 'price' => '33.50' ),
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array(
				array( 'user-id' => 5, 'price' => '42' ),
				array( 'user-id' => 8, 'price' => '33.5' ),
			),
			$this->stored_pricing()
		);
	}

	public function test_save_drops_rows_missing_user_or_price() {
		$_POST['pricebook_user_price'] = array(
			array( 'user-id' => '5', 'price' => '42' ),
			array( 'user-id' => '0', 'price' => '99' ),  // no customer.
			array( 'user-id' => '8', 'price' => '' ),    // no price.
			array( 'price' => '12' ),                    // missing user-id key.
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array( array( 'user-id' => 5, 'price' => '42' ) ),
			$this->stored_pricing()
		);
	}

	public function test_save_collapses_duplicate_customer_to_last_row() {
		$_POST['pricebook_user_price'] = array(
			array( 'user-id' => '5', 'price' => '42' ),
			array( 'user-id' => '5', 'price' => '40' ),
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array( array( 'user-id' => 5, 'price' => '40' ) ),
			$this->stored_pricing()
		);
	}

	public function test_save_clears_meta_when_no_valid_rows() {
		Store::$post_meta[ self::PRODUCT_ID ][ self::META_KEY ] = array( array( 'user-id' => 5, 'price' => '42' ) );

		$_POST['pricebook_user_price'] = array(
			array( 'user-id' => '0', 'price' => '' ),
		);

		$this->product_meta->save( self::PRODUCT_ID );

		// Deleted, so the accessor returns the empty-meta default.
		$this->assertSame( '', $this->stored_pricing() );
	}

	public function test_save_clears_meta_when_section_omitted() {
		Store::$post_meta[ self::PRODUCT_ID ][ self::META_KEY ] = array( array( 'user-id' => 5, 'price' => '42' ) );

		// No pricebook_user_price key in the request at all.
		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame( '', $this->stored_pricing() );
	}

	public function test_save_skips_without_valid_nonce() {
		Store::$post_meta[ self::PRODUCT_ID ][ self::META_KEY ] = array( array( 'user-id' => 5, 'price' => '42' ) );

		$_POST[ self::NONCE_FIELD ]    = 'wrong';
		$_POST['pricebook_user_price'] = array( array( 'user-id' => '8', 'price' => '33' ) );

		$this->product_meta->save( self::PRODUCT_ID );

		// Untouched: the existing override survives an unverified request.
		$this->assertSame(
			array( array( 'user-id' => 5, 'price' => '42' ) ),
			$this->stored_pricing()
		);
	}

	public function test_save_skips_without_edit_capability() {
		Store::$post_meta[ self::PRODUCT_ID ][ self::META_KEY ] = array( array( 'user-id' => 5, 'price' => '42' ) );

		// Current user lacks edit_post.
		Store::add_user( 2, array(), array() );
		Store::$current_user = 2;

		$_POST['pricebook_user_price'] = array( array( 'user-id' => '8', 'price' => '33' ) );

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array( array( 'user-id' => 5, 'price' => '42' ) ),
			$this->stored_pricing()
		);
	}

	/**
	 * The stored bulk-pricing meta for the product under test.
	 *
	 * @return mixed
	 */
	private function stored_bulk() {
		return get_post_meta( self::PRODUCT_ID, self::BULK_META_KEY, true );
	}

	public function test_save_bulk_groups_by_role_and_sorts_by_quantity() {
		$_POST['pricebook_bulk_price'] = array(
			array( 'role' => 'dealer', 'min_qty' => '50', 'max_qty' => '', 'price' => '55' ),
			array( 'role' => 'dealer', 'min_qty' => '10', 'max_qty' => '49', 'price' => '60' ),
			array( 'role' => 'operator', 'min_qty' => '10', 'max_qty' => '', 'price' => '78.5' ),
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array(
				'dealer'   => array(
					array( 'min_qty' => 10, 'max_qty' => 49, 'price' => '60' ),
					array( 'min_qty' => 50, 'max_qty' => 0, 'price' => '55' ),
				),
				'operator' => array(
					array( 'min_qty' => 10, 'max_qty' => 0, 'price' => '78.5' ),
				),
			),
			$this->stored_bulk()
		);
	}

	public function test_save_bulk_drops_invalid_rows() {
		$_POST['pricebook_bulk_price'] = array(
			array( 'role' => 'dealer', 'min_qty' => '10', 'price' => '60' ),
			array( 'role' => '', 'min_qty' => '10', 'price' => '60' ),                       // no role.
			array( 'role' => 'nope', 'min_qty' => '10', 'price' => '60' ),                   // unknown role.
			array( 'role' => 'dealer', 'min_qty' => '0', 'price' => '60' ),                  // qty < 1.
			array( 'role' => 'dealer', 'min_qty' => '20', 'price' => '' ),                   // no price.
			array( 'role' => 'dealer', 'min_qty' => '30', 'max_qty' => '20', 'price' => '50' ), // max < min.
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array( 'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 0, 'price' => '60' ) ) ),
			$this->stored_bulk()
		);
	}

	public function test_save_bulk_collapses_duplicate_quantity_to_last() {
		$_POST['pricebook_bulk_price'] = array(
			array( 'role' => 'dealer', 'min_qty' => '10', 'max_qty' => '49', 'price' => '60' ),
			array( 'role' => 'dealer', 'min_qty' => '10', 'max_qty' => '49', 'price' => '58' ),
		);

		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame(
			array( 'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 49, 'price' => '58' ) ) ),
			$this->stored_bulk()
		);
	}

	public function test_save_bulk_clears_meta_when_no_valid_rows() {
		Store::$post_meta[ self::PRODUCT_ID ][ self::BULK_META_KEY ] = array(
			'dealer' => array( array( 'min_qty' => 10, 'price' => '60' ) ),
		);

		// Section omitted entirely from the request.
		$this->product_meta->save( self::PRODUCT_ID );

		$this->assertSame( '', $this->stored_bulk() );
	}
}
