<?php
/**
 * WooCommerce integration: price, cart, admin-column, and visibility filters.
 *
 * All hooks are registered only when WooCommerce is active; the Product Bundles
 * integration is guarded.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles WooCommerce price/cart/admin filters.
 */
class WooHooks {

	/**
	 * Config provider.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Context resolver.
	 *
	 * @var Context
	 */
	private $context;

	/**
	 * Price engine.
	 *
	 * @var PriceEngine
	 */
	private $engine;

	/**
	 * Object IDs (spl_object_id) of cart-line product instances whose price has been
	 * set for their line quantity by {@see self::apply_bulk_cart_pricing}. For these
	 * {@see self::get_price} honors the already-set (quantity-aware) price instead of
	 * recomputing the per-unit price, so quantity-break pricing survives totals
	 * calculation. Rebuilt on each cart recalculation; per-request only.
	 *
	 * @var array<int,bool>
	 */
	private $cart_priced = array();

	/**
	 * Constructor.
	 *
	 * @param Config      $config  Plugin config.
	 * @param Context     $context Pricing context.
	 * @param PriceEngine $engine  Price engine.
	 */
	public function __construct( Config $config, Context $context, PriceEngine $engine ) {
		$this->config  = $config;
		$this->context = $context;
		$this->engine  = $engine;
	}

	/**
	 * Register all WooCommerce hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'get_price' ), 99, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'get_regular_price' ), 999, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'get_sale_price' ), 990, 2 );

		add_filter( 'woocommerce_sale_flash', array( $this, 'sale_flash' ), 10, 3 );

		// Prefix the catalog price with "From <lowest break>" when bulk pricing beats
		// the per-unit price for this user.
		add_filter( 'woocommerce_get_price_html', array( $this, 'bulk_price_html' ), 99, 2 );

		add_filter( 'woocommerce_add_cart_item', array( $this, 'set_cart_item_price' ), 99, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'set_cart_item_price_from_session' ), 99, 3 );

		// Re-price each line for its quantity on every cart recalculation so bulk
		// (quantity-break) pricing follows quantity changes.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bulk_cart_pricing' ), 100 );

		// A hidden price ("Call for Price") also covers variations. Tier pricing itself
		// is not applied to variations; these only blank a price the parent hides.
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'hide_variation_price' ), 99, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'hide_variation_price' ), 999, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'hide_variation_price' ), 990, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'hidden_price_html' ), 100, 2 );

		// Catalog visibility ("Hide Product" + a user's include/exclude lists), enforced
		// on every front-end product query, the single product page, and purchase.
		add_action( 'pre_get_posts', array( $this, 'filter_product_visibility' ) );
		add_action( 'template_redirect', array( $this, 'block_hidden_product' ), 1 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'product_is_visible' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_visible', array( $this, 'variation_is_visible' ), 99, 3 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'is_purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'is_purchasable' ), 99, 2 );

		if ( is_admin() && $this->config->module_enabled( 'admin_price_matrix' ) ) {
			add_filter( 'manage_product_posts_custom_column', array( $this, 'render_admin_price_column' ), 99, 2 );
			add_filter( 'woocommerce_admin_product_price_html', '__return_empty_string', 10, 2 );
			add_action( 'admin_head', array( $this, 'admin_price_column_style' ) );
		}
	}

	/**
	 * Effective displayed price (lowest of regular/sale) for the current user.
	 *
	 * @param mixed       $price   Incoming price.
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	public function get_price( $price, $product ) {
		// A cart line already priced for its quantity by apply_bulk_cart_pricing: honor
		// that (quantity-aware) price rather than recomputing the per-unit price, or the
		// quantity-break discount would be lost during totals calculation.
		if ( is_object( $product ) && isset( $this->cart_priced[ spl_object_id( $product ) ] ) ) {
			return $price;
		}
		// Bundle/composite containers are priced by their own plugin (sum of items,
		// whole-bundle discounts); Pricebook must not recompute them from meta.
		if ( $this->is_externally_priced_bundle( $product ) ) {
			return $price;
		}
		// Respect Product Bundles: bundled items priced as part of the bundle.
		if ( class_exists( 'WC_PB_Product_Prices' ) && \WC_PB_Product_Prices::is_bundled_pricing_context( $product ) ) {
			$bundled_item = \WC_PB_Product_Prices::get_filtered_bundled_item( $product );
			if ( $bundled_item && ! $bundled_item->is_priced_individually() ) {
				return $price;
			}
		}
		return $this->engine->effective_price( $price, $product );
	}

	/**
	 * Regular price for the current user.
	 *
	 * A tier price below MSRP is presented as a discount: MSRP stays the (struck
	 * through) regular price and the tier price becomes the sale price. When the
	 * tier price is not below MSRP it is shown as the plain regular price.
	 *
	 * @param mixed       $price   Incoming price.
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	public function get_regular_price( $price, $product ) {
		if ( $this->is_externally_priced_bundle( $product ) ) {
			return $price;
		}
		// A deliberately hidden price ("Call for Price") has no regular price either.
		// Falling through would return the MSRP below, exposing the hidden price to
		// anything that reads the regular price (themes, the Store API).
		if ( $this->engine->price_hidden( $product->get_id() ) ) {
			return '';
		}
		$msrp      = $this->msrp( $product );
		$effective = $this->engine->effective_price( $price, $product );

		if ( '' === (string) $effective ) {
			return '' !== (string) $msrp ? $msrp : $price;
		}
		if ( '' !== (string) $msrp && (float) $effective < (float) $msrp ) {
			return $msrp;
		}
		return $effective;
	}

	/**
	 * Sale price for the current user: the tier price when it is a discount off
	 * MSRP, otherwise empty (no sale).
	 *
	 * @param mixed       $price   Incoming price.
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	public function get_sale_price( $price, $product ) {
		if ( $this->is_externally_priced_bundle( $product ) ) {
			return $price;
		}
		$msrp      = $this->msrp( $product );
		$effective = $this->engine->effective_price( $price, $product );

		if ( '' === (string) $msrp || '' === (string) $effective ) {
			return '';
		}
		return (float) $effective < (float) $msrp ? $effective : '';
	}

	/**
	 * Control the sale flash for tier-driven discounts.
	 *
	 * A tier price is exposed to WooCommerce as a sale price, which would otherwise
	 * make every tier-priced product show a "Sale!" badge. By default this suppresses
	 * that badge for tier-driven discounts (a genuine MSRP sale is left untouched).
	 * A host theme opts the badge back in via the wc_pricebook_show_tier_badge filter
	 * (e.g. add_filter( 'wc_pricebook_show_tier_badge', '__return_true' )), in which
	 * case a tier badge (default "Dealer Price") is shown.
	 *
	 * @param string      $html    Default sale-flash HTML.
	 * @param \WP_Post     $post    Post.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function sale_flash( $html, $post, $product ) {
		// Not a tier-driven discount (a real MSRP sale): leave WooCommerce alone.
		if ( '' === $this->context->active_tier_label() ) {
			return $html;
		}

		/**
		 * Filters whether to show a badge for tier-driven discounts. Disabled by
		 * default; enable in theme code to show the tier badge.
		 *
		 * @param bool        $show    Whether to show the tier badge.
		 * @param \WC_Product $product Product.
		 */
		if ( ! apply_filters( 'wc_pricebook_show_tier_badge', false, $product ) ) {
			return '';
		}

		/**
		 * Filters the tier discount badge text.
		 *
		 * @param string      $text    Badge text.
		 * @param \WC_Product $product Product.
		 */
		$text = apply_filters( 'wc_pricebook_sale_flash_text', __( 'Dealer Price', 'wc-pricebook' ), $product );

		return '<span class="onsale">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Replace the catalog price html with "From <lowest break>" when the current user
	 * has a bulk break cheaper than their per-unit price. Left untouched in bundled
	 * pricing contexts and when no break beats the normal price.
	 *
	 * @param string      $html    Default price html.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function bulk_price_html( $html, $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $html;
		}
		if ( $this->is_externally_priced_bundle( $product ) ) {
			return $html;
		}
		if ( class_exists( 'WC_PB_Product_Prices' ) && \WC_PB_Product_Prices::is_bundled_pricing_context( $product ) ) {
			return $html;
		}

		// This filter runs in WooCommerce's price render and the Store API; never let a
		// pricing error break the whole page — fall back to the default markup.
		try {
			$from = $this->engine->from_price( null, $product );
		} catch ( \Throwable $e ) {
			return $html;
		}
		if ( '' === (string) $from ) {
			return $html;
		}

		/* translators: %s: formatted lowest bulk price. */
		return sprintf( esc_html__( 'From %s', 'wc-pricebook' ), wp_kses_post( wc_price( $from ) ) );
	}

	/**
	 * Whether a bundle/composite container is priced dynamically by its own plugin
	 * (sum of individually-priced items), in which case Pricebook must not touch it.
	 *
	 * A bundle priced by its own regular/sale price — i.e. it has no individually
	 * priced items ({@see WC_Product_Bundle::contains}) — is treated like an ordinary
	 * product and DOES get Pricebook pricing. Non-bundle products are never excluded.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	private function is_externally_priced_bundle( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_type' ) ) {
			return false;
		}
		if ( ! in_array( $product->get_type(), array( 'bundle', 'composite' ), true ) ) {
			return false;
		}
		// Statically priced bundle (no priced-individually items): Pricebook applies.
		if ( method_exists( $product, 'contains' ) ) {
			return (bool) $product->contains( 'priced_individually' );
		}
		return true;
	}

	/**
	 * The product's MSRP (base regular price).
	 *
	 * @param \WC_Product $product Product.
	 * @return mixed
	 */
	private function msrp( $product ) {
		$base = $this->config->base_meta();
		return get_post_meta( $product->get_id(), $base['regular'], true );
	}

	/**
	 * Snapshot the resolved price onto the cart item when added.
	 *
	 * @param array<string,mixed> $cart_data Cart item data.
	 * @param string              $cart_item_key Cart item key.
	 * @return array<string,mixed>
	 */
	public function set_cart_item_price( $cart_data, $cart_item_key ) {
		if ( empty( $cart_data['data'] ) || ! is_object( $cart_data['data'] ) ) {
			return $cart_data;
		}
		$new_price             = $cart_data['data']->get_price();
		$cart_data['data']->set_price( $new_price );
		$cart_data['new_price'] = $new_price;
		return $cart_data;
	}

	/**
	 * Restore the resolved price (or zero out bundled children) from session.
	 *
	 * @param array<string,mixed> $session_data Cart item from session.
	 * @param array<string,mixed> $values       Stored values.
	 * @param string              $key          Cart item key.
	 * @return array<string,mixed>
	 */
	public function set_cart_item_price_from_session( $session_data, $values, $key ) {
		if ( empty( $session_data['data'] ) || ! is_object( $session_data['data'] ) ) {
			return $session_data;
		}
		if ( isset( $session_data['bundled_by'] ) ) {
			$session_data['data']->set_price( 0 );
			$session_data['new_price'] = 0;
			return $session_data;
		}
		// Recompute for the current pricing context (so switching roles via the
		// toolbar updates cart prices on the next load) rather than restoring the
		// price snapshotted when the item was added. Quantity-aware so the restored
		// per-unit price already reflects a bulk break: templates that read the item
		// price BEFORE calculate_totals runs (notably the mini-cart, which renders
		// each line's per-unit price before its subtotal triggers recalculation)
		// would otherwise show the non-bulk price while the subtotal shows the bulk
		// one. apply_bulk_cart_pricing() re-applies this on every recalculation.
		// Seeded with the stored _price meta rather than null so an externally-set
		// active price survives the round trip — see PriceEngine::stored_active_price().
		$qty                       = isset( $values['quantity'] ) ? max( 1, (int) $values['quantity'] ) : 1;
		$new_price                 = $this->engine->effective_price_qty( $this->engine->stored_active_price( $session_data['data'] ), $session_data['data'], $qty );
		$session_data['data']->set_price( $new_price );
		$session_data['new_price'] = $new_price;
		// Mark this cart-line instance as already priced so get_price() honors the
		// (quantity-aware) price instead of recomputing the per-unit price. Without
		// this, a template reading the price via the get_price filter before
		// apply_bulk_cart_pricing runs (the mini-cart) gets the non-bulk price even
		// though set_price() above stored the bulk one. Rebuilt on recalculation.
		if ( '' !== (string) $new_price && null !== $new_price ) {
			$this->cart_priced[ spl_object_id( $session_data['data'] ) ] = true;
		}
		return $session_data;
	}

	/**
	 * Apply quantity-break (bulk) pricing to every eligible cart line.
	 *
	 * Runs on each recalculation so a quantity change re-resolves the price. Bundled
	 * children (priced as part of their bundle) are left alone. The price is computed
	 * from meta (incoming price passed as null) to avoid re-entering the price filter.
	 *
	 * @param \WC_Cart $cart Cart being calculated.
	 * @return void
	 */
	public function apply_bulk_cart_pricing( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		// Rebuilt each recalculation so a line that stops resolving to a price (e.g.
		// dropped below a break, or now call_for_price) is no longer honored.
		$this->cart_priced = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
				continue;
			}
			// Skip bundled children and bundle/composite containers — their own plugin
			// owns those prices (including whole-bundle quantity discounts).
			if ( isset( $cart_item['bundled_by'] ) || $this->is_externally_priced_bundle( $cart_item['data'] ) ) {
				continue;
			}
			$qty = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
			// A pricing error on one line must not break the whole cart/checkout.
			try {
				$new_price = $this->engine->effective_price_qty( $this->engine->stored_active_price( $cart_item['data'] ), $cart_item['data'], $qty );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( '' !== (string) $new_price && null !== $new_price ) {
				$cart_item['data']->set_price( $new_price );
				$this->cart_priced[ spl_object_id( $cart_item['data'] ) ] = true;
			}
		}
	}

	/**
	 * The product ID that pricing/visibility rules are evaluated against: the parent
	 * for a variation (categories, rules and overrides live on the parent), otherwise
	 * the product itself.
	 *
	 * @param mixed $product Product object.
	 * @return int
	 */
	private function gate_product_id( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}
		if ( method_exists( $product, 'get_type' ) && 'variation' === $product->get_type() && method_exists( $product, 'get_parent_id' ) ) {
			$parent = (int) $product->get_parent_id();
			if ( $parent > 0 ) {
				return $parent;
			}
		}
		return (int) $product->get_id();
	}

	/**
	 * Blank a variation's price when its parent product's price is hidden for the
	 * current user ("Call for Price"). Otherwise the price is left untouched.
	 *
	 * @param mixed       $price     Incoming price.
	 * @param \WC_Product $variation Variation.
	 * @return mixed
	 */
	public function hide_variation_price( $price, $variation ) {
		$product_id = $this->gate_product_id( $variation );
		return ( $product_id && $this->engine->price_hidden( $product_id ) ) ? '' : $price;
	}

	/**
	 * Replace the price html of a variable product (or a variation) whose price is
	 * hidden for the current user with WooCommerce's empty-price markup. A variable
	 * product builds its price range from cached variation prices, which bypass the
	 * per-product price filters, so the range must be suppressed here.
	 *
	 * @param string      $html    Price html.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function hidden_price_html( $html, $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_type' ) ) {
			return $html;
		}
		if ( ! in_array( $product->get_type(), array( 'variable', 'variation' ), true ) ) {
			return $html;
		}
		$product_id = $this->gate_product_id( $product );
		if ( ! $product_id || ! $this->engine->price_hidden( $product_id ) ) {
			return $html;
		}
		return (string) apply_filters( 'woocommerce_empty_price_html', '', $product );
	}

	/**
	 * Per-request cache of hidden product IDs, keyed by pricing user ID.
	 *
	 * @var array<int,array<int,int>>
	 */
	private $hidden_ids = array();

	/**
	 * True while the hidden-ID lookup runs its own product queries (re-entrancy guard
	 * for the pre_get_posts handler).
	 *
	 * @var bool
	 */
	private $resolving_hidden = false;

	/**
	 * Whether catalog visibility is enforced for this request. Never in wp-admin
	 * screens, cron, WP-CLI or the Action Scheduler runner (so exports, feeds and other
	 * background jobs see the full catalog), and never for managers.
	 *
	 * @return bool
	 */
	private function enforces_visibility() {
		if ( $this->resolving_hidden ) {
			return false;
		}
		$ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( function_exists( 'is_admin' ) && is_admin() && ! $ajax ) {
			return false;
		}
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( function_exists( 'did_action' ) && did_action( 'action_scheduler_before_process_queue' ) ) {
			return false;
		}
		return ! $this->context->is_manager();
	}

	/**
	 * IDs of the published products hidden from a user's catalog: products in an
	 * excluded category, or outside the include list, minus any product that forces
	 * itself visible to the user. Agrees with {@see Context::product_visible_for_user}.
	 *
	 * @param int $user_id Resolved pricing user ID.
	 * @return array<int,int>
	 */
	private function hidden_product_ids( $user_id ) {
		$user_id = (int) $user_id;
		if ( isset( $this->hidden_ids[ $user_id ] ) ) {
			return $this->hidden_ids[ $user_id ];
		}

		$cats       = $this->context->effective_visibility_categories( $user_id );
		$candidates = array();

		$this->resolving_hidden = true;
		foreach ( array( 'exclude' => 'IN', 'include' => 'NOT IN' ) as $list => $operator ) {
			if ( empty( $cats[ $list ] ) ) {
				continue;
			}
			$candidates = array_merge(
				$candidates,
				get_posts(
					array(
						'post_type'   => 'product',
						'post_status' => 'publish',
						'numberposts' => -1,
						'fields'      => 'ids',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'tax_query'   => array(
							array(
								'taxonomy' => 'product_cat',
								'field'    => 'term_id',
								'terms'    => array_map( 'intval', $cats[ $list ] ),
								'operator' => $operator,
							),
						),
					)
				)
			);
		}
		$this->resolving_hidden = false;

		$hide = array();
		foreach ( array_unique( array_map( 'intval', $candidates ) ) as $pid ) {
			if ( ! $this->context->user_force_visible( $pid, $user_id ) ) {
				$hide[] = $pid;
			}
		}

		$this->hidden_ids[ $user_id ] = $hide;
		return $hide;
	}

	/**
	 * Whether a product is hidden from the current viewer's catalog.
	 *
	 * @param int $product_id Product ID (parent ID for a variation).
	 * @return bool
	 */
	private function hidden_from_viewer( $product_id ) {
		if ( $product_id <= 0 || ! $this->enforces_visibility() ) {
			return false;
		}
		$result = $this->context->product_visible_for_user( $product_id, $this->context->pricing_user_id() );
		return empty( $result['visible'] );
	}

	/**
	 * Remove hidden products from every front-end query that can return products:
	 * the shop and taxonomy archives, search, shortcodes/blocks, related products and
	 * REST/Store API listings. Previously only the main archive query was filtered, so
	 * a hidden product was still listed everywhere else.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function filter_product_visibility( $query ) {
		if ( ! $this->enforces_visibility() ) {
			return;
		}
		// Skip queries that explicitly target other post types (menus, pages, orders…).
		$post_type = $query->get( 'post_type' );
		if ( ! empty( $post_type ) && 'any' !== $post_type && ! in_array( 'product', (array) $post_type, true ) ) {
			return;
		}

		$hide = $this->hidden_product_ids( $this->context->pricing_user_id() );
		if ( empty( $hide ) ) {
			return;
		}

		// WP_Query ignores post__not_in when post__in is set, so narrow post__in itself.
		$only = array_filter( array_map( 'intval', (array) $query->get( 'post__in' ) ) );
		if ( ! empty( $only ) ) {
			$left = array_values( array_diff( $only, $hide ) );
			$query->set( 'post__in', empty( $left ) ? array( 0 ) : $left );
			return;
		}

		$existing = array_filter( array_map( 'intval', (array) $query->get( 'post__not_in' ) ) );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $hide ) ) ) );
	}

	/**
	 * Serve a 404 for the single page of a product hidden from the viewer, so it
	 * cannot be opened by direct URL.
	 *
	 * @return void
	 */
	public function block_hidden_product() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( ! $this->hidden_from_viewer( (int) get_queried_object_id() ) ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * woocommerce_product_is_visible: hidden products are not visible in loops.
	 *
	 * @param bool $visible    Current visibility.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function product_is_visible( $visible, $product_id ) {
		return $visible && ! $this->hidden_from_viewer( (int) $product_id );
	}

	/**
	 * woocommerce_variation_is_visible: follows the parent product's visibility.
	 *
	 * @param bool $visible      Current visibility.
	 * @param int  $variation_id Variation ID.
	 * @param int  $parent_id    Parent product ID.
	 * @return bool
	 */
	public function variation_is_visible( $visible, $variation_id, $parent_id = 0 ) {
		return $visible && ! $this->hidden_from_viewer( (int) $parent_id );
	}

	/**
	 * A product hidden from the viewer cannot be purchased (blocks add-to-cart by URL
	 * or API, and drops it from an existing cart).
	 *
	 * @param bool        $purchasable Current purchasability.
	 * @param \WC_Product $product     Product or variation.
	 * @return bool
	 */
	public function is_purchasable( $purchasable, $product ) {
		return $purchasable && ! $this->hidden_from_viewer( $this->gate_product_id( $product ) );
	}

	/**
	 * Render an all-tiers price matrix in the admin product list column.
	 *
	 * @param string $column     Column key.
	 * @param int    $product_id Product ID.
	 * @return void
	 */
	public function render_admin_price_column( $column, $product_id ) {
		if ( 'price' !== $column ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$rows = array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) );
		foreach ( $this->config->tiers() as $key => $tier ) {
			$rows[ $key ] = $tier['label'];
		}

		// Bulk (quantity-break) schedules keyed by an identical-schedule signature, so a
		// schedule shared by several roles is printed once under a combined label rather
		// than repeated per role. signature => array( 'labels' => [], 'breaks' => [] ).
		$bulk_groups = array();

		echo '<table class="pricebook-matrix-table" style="font-size:12px;width:100%;">';
		foreach ( $rows as $tier_key => $label ) {
			$regular = $this->engine->price_as_tier( $product_id, $tier_key, false );
			$sale    = $this->engine->price_as_tier( $product_id, $tier_key, true );
			$regular_price = $regular[0];
			$sale_price    = $sale[0];

			if ( '' === (string) $regular_price || $regular_price <= 0 ) {
				continue;
			}
			$has_sale = '' !== (string) $sale_price && $sale_price != $regular_price && $sale_price > 0; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

			printf(
				'<tr><td>%s:</td><td style="text-align:right">%s%s</td></tr>',
				esc_html( $label ),
				wp_kses_post( wc_price( $regular_price ) ),
				$has_sale ? ' <span style="color:#d63638;">(' . wp_kses_post( wc_price( $sale_price ) ) . ')</span>' : ''
			);

			// Collect this tier's bulk schedule; identical schedules merge into one group.
			if ( 'msrp' !== $tier_key ) {
				$breaks = $this->engine->bulk_breaks( $product_id, $tier_key );
				if ( ! empty( $breaks ) ) {
					$sig = wp_json_encode( $breaks );
					if ( ! isset( $bulk_groups[ $sig ] ) ) {
						$bulk_groups[ $sig ] = array(
							'labels' => array(),
							'breaks' => $breaks,
						);
					}
					$bulk_groups[ $sig ]['labels'][] = $label;
				}
			}
		}

		// Consolidated bulk-pricing section: one block per distinct schedule, its shared
		// roles listed once. Quantity-break pricing overrides tier pricing at/above a
		// break's minimum quantity, so without this bulk pricing is invisible in the list.
		foreach ( $bulk_groups as $group ) {
			printf(
				'<tr class="pricebook-matrix-bulk-head"><td colspan="2">%s <span class="pricebook-matrix-bulk-roles">%s</span></td></tr>',
				esc_html__( 'Bulk pricing', 'wc-pricebook' ),
				esc_html( implode( ', ', $group['labels'] ) )
			);
			foreach ( $group['breaks'] as $break ) {
				$min   = (int) $break['min_qty'];
				$max   = (int) $break['max_qty'];
				$range = $max > 0 ? sprintf( '%d–%d', $min, $max ) : sprintf( '%d+', $min );
				printf(
					'<tr class="pricebook-matrix-break"><td>%s</td><td style="text-align:right">%s</td></tr>',
					esc_html( $range ),
					wp_kses_post( wc_price( $break['price'] ) )
				);
			}
		}
		echo '</table>';
	}

	/**
	 * CSS for the admin price-matrix column.
	 *
	 * @return void
	 */
	public function admin_price_column_style() {
		echo '<style>
			td.price.column-price > span.woocommerce-Price-amount.amount { display:none; }
			.column-price { min-width:250px !important; width:250px !important; }
			.pricebook-matrix-table { border-collapse:collapse; width:100%; }
			.pricebook-matrix-table td { padding:2px 4px; }
			.pricebook-matrix-break td { padding-left:14px; opacity:0.75; font-size:11px; }
			.pricebook-matrix-break td:first-child { color:#666; }
			.pricebook-matrix-bulk-head td { padding-top:6px; border-top:1px solid #e0e0e0; font-weight:600; }
			.pricebook-matrix-bulk-roles { font-weight:normal; color:#666; }
		</style>';
	}
}
