<?php
/**
 * Minimal WC_Product stand-in for engine tests.
 *
 * The engine only needs get_id() on the product object; everything else is
 * resolved from post meta via the shims.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

/**
 * Fake product exposing the methods the engine touches.
 */
class FakeProduct {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Product type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Whether a bundle contains individually-priced items (dynamic pricing).
	 *
	 * @var bool
	 */
	private $priced_individually;

	/**
	 * Explicitly set price (as WooCommerce's cart flow does via set_price()), or null.
	 *
	 * @var mixed
	 */
	private $price = null;

	/**
	 * Constructor.
	 *
	 * @param int    $id                  Product ID.
	 * @param string $type                Product type (e.g. 'simple', 'bundle').
	 * @param bool   $priced_individually For bundles: whether items are priced individually.
	 */
	public function __construct( $id, $type = 'simple', $priced_individually = true ) {
		$this->id                  = $id;
		$this->type                = $type;
		$this->priced_individually = $priced_individually;
	}

	/**
	 * Bundle "contains" check (only 'priced_individually' is modeled).
	 *
	 * @param string $key Contains key.
	 * @return bool
	 */
	public function contains( $key ) {
		return 'priced_individually' === $key ? $this->priced_individually : false;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Store an explicit price (WooCommerce cart flow).
	 *
	 * @param mixed $price Price.
	 * @return void
	 */
	public function set_price( $price ) {
		$this->price = $price;
	}

	/**
	 * The explicitly set price (null when unset).
	 *
	 * @param string $context Unused; matches the WC_Product signature.
	 * @return mixed
	 */
	public function get_price( $context = 'view' ) {
		return $this->price;
	}
}
