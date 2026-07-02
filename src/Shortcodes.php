<?php
/**
 * Shortcodes: manager pricing table and a user's allowed-products list.
 *
 * Generic port of `[dealer_pricing_table]` and `[get-dealer-products]`. Tags are
 * configurable so a host can re-expose the original names without code changes.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the plugin's shortcodes.
 */
class Shortcodes {

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
	 * Register shortcodes using the configured tags.
	 *
	 * @return void
	 */
	public function register() {
		$tags = $this->config->shortcodes();
		if ( ! empty( $tags['table'] ) ) {
			add_shortcode( $tags['table'], array( $this, 'render_table' ) );
		}
		if ( ! empty( $tags['products'] ) ) {
			add_shortcode( $tags['products'], array( $this, 'render_user_products' ) );
		}
		if ( ! empty( $tags['bulk_table'] ) ) {
			add_shortcode( $tags['bulk_table'], array( $this, 'render_bulk_table' ) );
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_bulk_table' ), 25 );
		}
	}

	/**
	 * Auto-render the bulk-pricing table on the single product page (below the price),
	 * for the viewing user's resolved tier. No-op when the product has no breaks.
	 *
	 * @return void
	 */
	public function render_product_bulk_table() {
		echo $this->render_bulk_table( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within renderer.
	}

	/**
	 * Render a product's quantity-break (bulk) pricing table.
	 *
	 * Attributes:
	 *  - product: product ID (defaults to the current/queried product).
	 *  - role:    tier key to show, 'all' for every configured tier, or '' (default)
	 *             for the viewing user's resolved tier.
	 *
	 * Returns an empty string when the product has no bulk breaks for the resolved
	 * role(s), so it is safe to drop onto any product template.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_bulk_table( $atts ) {
		$atts = shortcode_atts(
			array(
				'product' => 0,
				'role'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			$this->config->shortcodes()['bulk_table']
		);

		$product_id = (int) $atts['product'];
		if ( $product_id <= 0 ) {
			$post       = get_queried_object();
			$product_id = ( $post && isset( $post->ID ) ) ? (int) $post->ID : 0;
		}
		if ( $product_id <= 0 ) {
			return '';
		}

		// Resolve which tiers to display.
		$tiers = $this->config->tiers();
		if ( 'all' === $atts['role'] ) {
			$role_keys = array_keys( $tiers );
		} elseif ( '' !== $atts['role'] ) {
			$role_keys = isset( $tiers[ $atts['role'] ] ) ? array( $atts['role'] ) : array();
		} else {
			// The viewing customer's own pricing. A customer-specific price wins over
			// quantity pricing, so the breaks table would be misleading — hide it.
			if ( $this->engine->user_has_override( $product_id ) ) {
				return '';
			}
			$active    = $this->context->user_pricing_role( $this->context->pricing_user_id() );
			$role_keys = ( '' !== $active && isset( $tiers[ $active ] ) ) ? array( $active ) : array();
		}

		// Collect non-empty break tables.
		$sections = array();
		foreach ( $role_keys as $key ) {
			$breaks = $this->engine->bulk_breaks( $product_id, $key );
			if ( ! empty( $breaks ) ) {
				$label              = isset( $tiers[ $key ]['label'] ) ? (string) $tiers[ $key ]['label'] : $key;
				$sections[ $label ] = $breaks;
			}
		}
		if ( empty( $sections ) ) {
			return '';
		}

		$show_role = ( 'all' === $atts['role'] && count( $sections ) > 1 );

		ob_start();
		echo '<table class="pricebook-bulk-table">';
		echo '<thead><tr>';
		if ( $show_role ) {
			echo '<th>' . esc_html__( 'Role', 'wc-pricebook' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Quantity', 'wc-pricebook' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'wc-pricebook' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $sections as $label => $breaks ) {
			$count = count( $breaks );
			foreach ( $breaks as $i => $row ) {
				echo '<tr>';
				if ( $show_role && 0 === $i ) {
					printf( '<td rowspan="%d">%s</td>', (int) $count, esc_html( $label ) );
				}
				$min   = (int) $row['min_qty'];
				$max   = (int) $row['max_qty'];
				$range = $max > 0
					? sprintf( '%d&ndash;%d', $min, $max )
					: sprintf( '%d+', $min );
				echo '<td>' . wp_kses_post( $range ) . '</td>';
				echo '<td>' . wp_kses_post( wc_price( $row['price'] ) ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		return (string) ob_get_clean();
	}

	/**
	 * Render the all-tiers pricing table for the current product (managers only).
	 *
	 * @return string
	 */
	public function render_table() {
		// Manager-only reference table: gate on the real user so it stays visible while
		// a manager is previewing as a customer.
		if ( ! $this->context->current_user_is_manager() ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! $post || ! isset( $post->ID ) ) {
			return '';
		}
		$product_id = $post->ID;

		$flags = wp_get_post_terms( $product_id, 'product_flag', array( 'fields' => 'all' ) );
		$cats  = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
		$terms = array_merge( is_array( $flags ) ? $flags : array(), is_array( $cats ) ? $cats : array() );

		ob_start();
		include WC_PRICEBOOK_DIR . 'src/templates/pricing-table.php';
		return (string) ob_get_clean();
	}

	/**
	 * Output a comma-separated list of product IDs a user is allowed to see.
	 *
	 * Mirrors the original behavior of returning "1" when empty so downstream
	 * dynamic queries do not break on a blank string.
	 *
	 * @return string
	 */
	public function render_user_products() {
		$meta_key = $this->context->meta_key( 'include_products' );
		if ( '' === $meta_key ) {
			return '1';
		}
		$ids = get_user_meta( $this->context->pricing_user_id(), $meta_key, true );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return implode( ',', array_map( 'intval', $ids ) );
		}
		return '1';
	}
}
