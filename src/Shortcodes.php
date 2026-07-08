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
		if ( ! empty( $tags['bulk_applies'] ) ) {
			add_shortcode( $tags['bulk_applies'], array( $this, 'render_bulk_applies' ) );
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

		$product_id = $this->resolve_product_id( $atts['product'] );
		if ( $product_id <= 0 ) {
			return '';
		}

		$sections = $this->bulk_sections( $product_id, (string) $atts['role'] );
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
	 * Boolean-ish shortcode for Elementor/dynamic-visibility conditions: outputs "1"
	 * when quantity-break (bulk) pricing applies to the product for the resolved
	 * role(s), or an empty string when it does not. The condition is identical to
	 * whether {@see self::render_bulk_table} would render a table (same role
	 * resolution and customer-override hide), so a widget can be shown/hidden in
	 * lockstep with the table.
	 *
	 * Attributes match the bulk table: `product` (defaults to the current product)
	 * and `role` ('' = viewer's resolved tier, 'all', or a tier key).
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string "1" when bulk pricing applies, otherwise "".
	 */
	public function render_bulk_applies( $atts ) {
		$atts = shortcode_atts(
			array(
				'product' => 0,
				'role'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			$this->config->shortcodes()['bulk_applies']
		);

		$product_id = $this->resolve_product_id( $atts['product'] );
		if ( $product_id <= 0 ) {
			return '';
		}

		return empty( $this->bulk_sections( $product_id, (string) $atts['role'] ) ) ? '' : '1';
	}

	/**
	 * Resolve a shortcode `product` attribute to a product ID, falling back to the
	 * current/queried object when none (or a non-positive value) is given.
	 *
	 * @param mixed $product Attribute value.
	 * @return int Product ID (0 when none could be resolved).
	 */
	private function resolve_product_id( $product ) {
		$product_id = (int) $product;
		if ( $product_id <= 0 ) {
			$post       = get_queried_object();
			$product_id = ( $post && isset( $post->ID ) ) ? (int) $post->ID : 0;
		}
		return $product_id;
	}

	/**
	 * The non-empty quantity-break tables for a product, keyed by tier label, for the
	 * roles a `role` attribute resolves to. Shared by the bulk table and its
	 * dynamic-visibility companion so both agree on when bulk pricing applies.
	 *
	 * `role`: 'all' = every configured tier; a tier key = just that tier; '' = the
	 * viewing customer's resolved tier — but a customer-specific override price wins
	 * over quantity pricing, so that case yields no sections (the table would mislead).
	 *
	 * @param int    $product_id Product ID (assumed > 0).
	 * @param string $role       Role attribute.
	 * @return array<string,array<int,array<string,mixed>>> label => break rows.
	 */
	private function bulk_sections( $product_id, $role ) {
		$tiers = $this->config->tiers();
		if ( 'all' === $role ) {
			$role_keys = array_keys( $tiers );
		} elseif ( '' !== $role ) {
			$role_keys = isset( $tiers[ $role ] ) ? array( $role ) : array();
		} else {
			if ( $this->engine->user_has_override( $product_id ) ) {
				return array();
			}
			$active    = $this->context->user_pricing_role( $this->context->pricing_user_id() );
			$role_keys = ( '' !== $active && isset( $tiers[ $active ] ) ) ? array( $active ) : array();
		}

		$sections = array();
		foreach ( $role_keys as $key ) {
			$breaks = $this->engine->bulk_breaks( $product_id, $key );
			if ( ! empty( $breaks ) ) {
				$label              = isset( $tiers[ $key ]['label'] ) ? (string) $tiers[ $key ]['label'] : $key;
				$sections[ $label ] = $breaks;
			}
		}
		return $sections;
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

		$cats  = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
		$terms = is_array( $cats ) ? $cats : array();

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
