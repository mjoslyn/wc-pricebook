<?php
/**
 * Admin-bar product price inspector.
 *
 * Adds a toolbar dropdown that lists each pricing tier's price (plus MSRP) for the
 * product currently being viewed — on the single-product front end or the product
 * edit screen. Read-only reference for shop staff (anyone who can edit products);
 * does not change anyone's pricing.
 *
 * @package WCPricebook
 */

namespace WCPricebook\ProductPrices;

use WCPricebook\Config;
use WCPricebook\Context;
use WCPricebook\PriceEngine;
use WCPricebook\Flowchart\Flowchart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolbar node showing per-tier prices for the current product.
 */
class ProductPrices {

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
	 * Engine.
	 *
	 * @var PriceEngine
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param Config      $config  Config.
	 * @param Context     $context Context.
	 * @param PriceEngine $engine  Engine.
	 */
	public function __construct( Config $config, Context $context, PriceEngine $engine ) {
		$this->config  = $config;
		$this->context = $context;
		$this->engine  = $engine;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 90 );
	}

	/**
	 * Format a resolved price for display in the toolbar.
	 *
	 * @param mixed $value Price value.
	 * @return string
	 */
	private function format( $value ) {
		if ( '' === (string) $value || null === $value ) {
			return '—';
		}
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $value ) );
		}
		return (string) $value;
	}

	/**
	 * A quantity-break range label ("10–49" or "50+").
	 *
	 * @param array{min_qty:int,max_qty:int,price:string} $break Break row.
	 * @return string
	 */
	private function range_label( $break ) {
		$min = (int) $break['min_qty'];
		$max = (int) $break['max_qty'];
		return $max > 0 ? sprintf( '%d–%d', $min, $max ) : sprintf( '%d+', $min );
	}

	/**
	 * Add a "Customer-specific prices" node with a child per per-user override, when
	 * the product has any. Per-user overrides are product-level (not per-role), so
	 * they sit as their own toggle rather than under a role.
	 *
	 * @param \WP_Admin_Bar $bar        Admin bar.
	 * @param int           $product_id Product ID.
	 * @return void
	 */
	private function add_user_pricing_node( $bar, $product_id ) {
		$meta_key = $this->config->user_pricing_meta();
		if ( '' === $meta_key ) {
			return;
		}
		$rows = get_post_meta( $product_id, $meta_key, true );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$bar->add_node(
			array(
				'parent' => 'wc-pricebook-product-prices',
				'id'     => 'wc-pricebook-user-prices',
				'title'  => esc_html__( 'Customer-specific prices', 'wc-pricebook' ),
				'href'   => '#',
			)
		);

		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['user-id'], $row['price'] ) ) {
				continue;
			}
			$user  = get_userdata( (int) $row['user-id'] );
			$label = $user ? $user->display_name : sprintf( '#%d', (int) $row['user-id'] );
			$bar->add_node(
				array(
					'parent' => 'wc-pricebook-user-prices',
					'id'     => 'wc-pricebook-user-price-' . $i,
					'title'  => esc_html( $label . ': ' . $this->format( $row['price'] ) ),
					'href'   => '#',
				)
			);
		}
	}

	/**
	 * Build the toolbar node and a child per tier.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar_menu( $bar ) {
		// Visible to all shop staff (anyone who can edit products), not just managers.
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$product_id = Flowchart::current_product_id();
		if ( ! $product_id ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'wc-pricebook-product-prices',
				'title' => '<span class="ab-icon dashicons dashicons-tag"></span> ' . esc_html__( 'Tier prices', 'wc-pricebook' ),
				'href'  => '#',
				'meta'  => array( 'title' => __( 'Pricebook tier prices for this product', 'wc-pricebook' ) ),
			)
		);

		$rows = array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) );
		foreach ( $this->config->tiers() as $key => $tier ) {
			$rows[ $key ] = ! empty( $tier['label'] ) ? (string) $tier['label'] : $key;
		}

		foreach ( $rows as $tier_key => $label ) {
			$result  = $this->engine->price_as_tier( $product_id, $tier_key, false );
			$breaks  = 'msrp' === $tier_key ? array() : $this->engine->bulk_breaks( $product_id, $tier_key );
			$node_id = 'wc-pricebook-price-' . $tier_key;

			$title = esc_html( $label ) . ': ' . esc_html( $this->format( $result[0] ) );
			// Hint that this role expands to its quantity-break details below.
			if ( ! empty( $breaks ) ) {
				$title .= ' <span style="opacity:0.7;font-style:italic;">(' . esc_html__( 'quantity pricing', 'wc-pricebook' ) . ')</span>';
			}

			$bar->add_node(
				array(
					'parent' => 'wc-pricebook-product-prices',
					'id'     => $node_id,
					'title'  => $title,
					'href'   => '#',
				)
			);

			// Quantity-break details nested (toggle) under this role's price.
			foreach ( $breaks as $i => $break ) {
				$bar->add_node(
					array(
						'parent' => $node_id,
						'id'     => $node_id . '-break-' . $i,
						'title'  => esc_html( $this->range_label( $break ) . ': ' . $this->format( $break['price'] ) ),
						'href'   => '#',
					)
				);
			}
		}

		$this->add_user_pricing_node( $bar, $product_id );

		if ( $this->config->module_enabled( 'flowchart' ) ) {
			$bar->add_node(
				array(
					'parent' => 'wc-pricebook-product-prices',
					'id'     => 'wc-pricebook-product-prices-flowchart',
					'title'  => esc_html__( 'Open in flowchart', 'wc-pricebook' ),
					'href'   => Flowchart::url( $product_id ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}
}
