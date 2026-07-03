<?php
/**
 * Lightweight product stand-in for the price engine.
 *
 * The engine resolves everything about a product from its ID (post meta, terms),
 * needing only {@see PriceEngine::price_for_user}'s `$product->get_id()` call. When
 * exporting a full pricelist we resolve prices for every product against every user;
 * loading a full WC_Product object for each (product, user) pair would be wasteful, so
 * we pass this proxy carrying just the ID. Product name/SKU for the CSV are gathered
 * once, separately.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal object exposing only the get_id() the price engine reads.
 */
class ProductProxy {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param int $id Product ID.
	 */
	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * Product ID (the only method the engine calls on a product).
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}
}
