<?php
/**
 * Optional pricing flowchart / debug page at /price-flowchart.
 *
 * Manager-only. Shows the price-resolution path (the engine's history output) for
 * a chosen product across MSRP and every configured tier. A leaner generic
 * replacement for the theme's Mermaid-based flowchart that reuses the engine's
 * own step history rather than re-deriving the logic.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Flowchart;

use WCPricebook\Config;
use WCPricebook\Context;
use WCPricebook\PriceEngine;
use WCPricebook\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the pricing flowchart page.
 */
class Flowchart {

	const QUERY_VAR        = 'wc_pricebook_flowchart';
	const AJAX_SEARCH      = 'wc_pricebook_flowchart_search';
	const AJAX_USER_SEARCH = 'wc_pricebook_flowchart_user_search';

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
	 * Rules.
	 *
	 * @var Rules
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Config      $config  Config.
	 * @param Context     $context Context.
	 * @param PriceEngine $engine  Engine.
	 * @param Rules       $rules   Rules.
	 */
	public function __construct( Config $config, Context $context, PriceEngine $engine, Rules $rules ) {
		$this->config  = $config;
		$this->context = $context;
		$this->engine  = $engine;
		$this->rules   = $rules;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH, array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_' . self::AJAX_USER_SEARCH, array( $this, 'ajax_user_search' ) );
	}

	/**
	 * The flowchart URL, optionally preselecting a product.
	 *
	 * @param int $product_id Product ID (0 = none).
	 * @return string
	 */
	public static function url( $product_id = 0 ) {
		$url = home_url( '/price-flowchart' );
		if ( $product_id > 0 ) {
			$url = add_query_arg( 'product_id', (int) $product_id, $url );
		}
		return $url;
	}

	/**
	 * The product ID in the current context (front-end single product or the
	 * product edit screen), or 0 when there is none.
	 *
	 * @return int
	 */
	public static function current_product_id() {
		if ( is_admin() ) {
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && 'product' === $screen->id && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					return (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}
			return 0;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * Add the /price-flowchart rewrite rule, flushing once if it is not yet
	 * registered (e.g. the module was just enabled, so activation never flushed it).
	 *
	 * @return void
	 */
	public function add_rewrite() {
		$rule = '^price-flowchart/?$';
		add_rewrite_rule( $rule, 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules[ $rule ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Register the query var.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render the page when the endpoint is hit.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! $this->context->current_user_is_manager() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wc-pricebook' ), '', array( 'response' => 403 ) );
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id    = isset( $_GET['as_user'] ) ? absint( $_GET['as_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->render_page( $product_id, $user_id );
		exit;
	}

	/**
	 * AJAX product search (manager-only).
	 *
	 * @return void
	 */
	public function ajax_search() {
		if ( ! $this->context->current_user_is_manager() ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		// Match on title/content (the default WP search) and on SKU (a _sku LIKE
		// query, plus an exact SKU lookup) so products are findable either way.
		$by_title = new \WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 20,
				's'              => $term,
				'fields'         => 'ids',
			)
		);
		$by_sku   = new \WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_sku',
						'value'   => $term,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$ids = array_slice( array_values( array_unique( array_merge( (array) $by_title->posts, (array) $by_sku->posts ) ) ), 0, 20 );

		$results = array();
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$sku  = $product->get_sku();
			$text = sprintf( '#%d — %s', $pid, $product->get_name() );
			if ( '' !== (string) $sku ) {
				$text .= ' (' . $sku . ')';
			}
			$results[] = array(
				'id'   => $pid,
				'text' => $text,
			);
		}
		wp_send_json_success( $results );
	}

	/**
	 * AJAX user search (manager-only) — by login, name, or email.
	 *
	 * @return void
	 */
	public function ajax_user_search() {
		if ( ! $this->context->current_user_is_manager() ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 20,
				'fields'         => array( 'ID', 'user_login', 'user_email' ),
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'   => (int) $user->ID,
				'text' => sprintf( '#%d — %s <%s>', $user->ID, $user->user_login, $user->user_email ),
			);
		}
		wp_send_json_success( $results );
	}

	/**
	 * Render a price for a table cell. A real number (including 0) shows as a
	 * formatted price ($0.00 for an actual zero). An empty/unset price shows the
	 * "Call for Price" label when $empty_is_cfp is true (the customer-facing price,
	 * or a product bound to the call_for_price rule), otherwise a dash (a tier that
	 * simply does not price this product).
	 *
	 * @param mixed $value        Resolved price ('' / null when unpriced).
	 * @param bool  $empty_is_cfp Whether an empty value means "Call for Price".
	 * @return string HTML (already escaped).
	 */
	private function price_cell( $value, $empty_is_cfp = false ) {
		if ( '' !== (string) $value && null !== $value ) {
			return wp_kses_post( wc_price( $value ) );
		}
		if ( ! $empty_is_cfp ) {
			return '<span class="dash">—</span>';
		}
		return esc_html__( 'Call for Price', 'wc-pricebook' );
	}

	/**
	 * Whether two resolved prices are the same amount. Compared numerically on
	 * purpose: the engine's tier and bulk paths return the same amount in different
	 * shapes ('595' vs '595.00'), so a string comparison reports false differences.
	 *
	 * @param mixed $a First price ('' / null when unpriced).
	 * @param mixed $b Second price.
	 * @return bool
	 */
	private static function prices_equal( $a, $b ) {
		$a_empty = ( '' === (string) $a || null === $a );
		$b_empty = ( '' === (string) $b || null === $b );
		if ( $a_empty || $b_empty ) {
			return $a_empty && $b_empty;
		}
		return abs( (float) $a - (float) $b ) < 0.0001;
	}

	/**
	 * The per-unit price a customer actually pays at each quantity, as contiguous
	 * ranges, for products whose price moves with quantity.
	 *
	 * Which bulk rows reach a given customer is the engine's business (it weighs tier
	 * membership, role-targeted rows, switcher previews and customer-specific
	 * overrides, and keeps that resolution private). So rather than re-deriving it
	 * here and risking drift, this probes the engine's own quantity pricing at every
	 * break quantity configured on the product and keeps the points where the price
	 * changes. What it reports is therefore what the cart charges, by construction.
	 *
	 * @param mixed       $base       Base regular price passed to the engine.
	 * @param \WC_Product $product    Product.
	 * @param int         $product_id Product ID.
	 * @param int         $user_id    Customer to resolve for.
	 * @return array<int,array{min:int,max:int,price:mixed,is_bulk:bool}> Ranges in
	 *         quantity order, or an empty array when quantity does not change the price.
	 */
	private function customer_qty_ladder( $base, $product, $product_id, $user_id ) {
		$bulk_meta = $this->config->bulk_pricing_meta();
		$bulk_all  = '' !== $bulk_meta ? get_post_meta( $product_id, $bulk_meta, true ) : array();
		if ( ! is_array( $bulk_all ) || empty( $bulk_all ) ) {
			return array();
		}

		// Every quantity at which any configured break could start, whoever it targets.
		$qtys = array( 1 );
		foreach ( $bulk_all as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['min_qty'] ) && (int) $row['min_qty'] > 0 ) {
					$qtys[] = (int) $row['min_qty'];
				}
			}
		}
		$qtys = array_values( array_unique( $qtys ) );
		sort( $qtys );

		// The tier price with quantity out of the picture, to tell a bulk-driven price
		// from the tier price it replaced. A rung is only called a quantity break when
		// it moves the amount: a break that resolves to the same amount as the tier
		// changes nothing the customer would notice, so labelling it one would be noise.
		$tier_only = $this->engine->effective_price( $base, $product, $user_id );

		// Keep only the quantities where this customer's price actually changes.
		$points = array();
		$prev   = null;
		foreach ( $qtys as $qty ) {
			$price = $this->engine->effective_price_qty( $base, $product, $qty, $user_id );
			if ( null !== $prev && self::prices_equal( $price, $prev ) ) {
				continue;
			}
			$points[] = array(
				'qty'     => $qty,
				'price'   => $price,
				'is_bulk' => ! self::prices_equal( $price, $tier_only ),
			);
			$prev     = $price;
		}

		// A single point means quantity never moves the price — nothing to show.
		if ( count( $points ) < 2 ) {
			return array();
		}

		$ladder = array();
		foreach ( $points as $i => $point ) {
			$ladder[] = array(
				'min'     => (int) $point['qty'],
				'max'     => isset( $points[ $i + 1 ] ) ? (int) $points[ $i + 1 ]['qty'] - 1 : 0,
				'price'   => $point['price'],
				'is_bulk' => (bool) $point['is_bulk'],
			);
		}
		return $ladder;
	}

	/**
	 * Display label for a role/tier key used in bulk or force targeting: the tier's
	 * label, "MSRP Customer" for the synthetic key, the WP role name, or the raw slug.
	 *
	 * @param string $key Role/tier/MSRP-Customer key.
	 * @return string
	 */
	private function role_label( $key ) {
		if ( \WCPricebook\Context::MSRP_CUSTOMER === $key ) {
			return __( 'MSRP Customer', 'wc-pricebook' );
		}
		$tiers = $this->config->tiers();
		if ( isset( $tiers[ $key ]['label'] ) && '' !== (string) $tiers[ $key ]['label'] ) {
			return (string) $tiers[ $key ]['label'];
		}
		if ( function_exists( 'wp_roles' ) ) {
			$names = wp_roles()->get_names();
			if ( isset( $names[ $key ] ) ) {
				return (string) $names[ $key ];
			}
		}
		return (string) $key;
	}

	/**
	 * Format a list of user IDs as "login (#id)" labels for display.
	 *
	 * @param array<int,int> $ids User IDs.
	 * @return array<int,string>
	 */
	private function user_labels( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$user  = get_userdata( (int) $id );
			$out[] = $user ? sprintf( '%s (#%d)', $user->user_login, (int) $id ) : sprintf( '#%d', (int) $id );
		}
		return $out;
	}

	/**
	 * Human-readable label + description for each named pricing rule, for the
	 * "Pricing rules in effect" table. Unknown rule keys fall back to a
	 * humanized version of the key with no description.
	 *
	 * @return array<string,array{label:string,desc:string}>
	 */
	private static function rule_descriptions() {
		return array(
			'skip_matrix'       => array(
				'label' => __( 'Skip matrix', 'wc-pricebook' ),
				'desc'  => __( 'Skips tier pricing entirely — every customer sees MSRP for this product.', 'wc-pricebook' ),
			),
			'no_tier_discount'  => array(
				'label' => __( 'No tier discount', 'wc-pricebook' ),
				'desc'  => __( 'Suppresses tier discounts — discount tiers collapse to the plain base price.', 'wc-pricebook' ),
			),
			'force_visible'     => array(
				'label' => __( 'Force visible', 'wc-pricebook' ),
				'desc'  => __( 'Keeps the product\'s price visible even when a Hide-Pricing visibility role would otherwise hide it.', 'wc-pricebook' ),
			),
			'call_for_price'    => array(
				'label' => __( 'Call for Price', 'wc-pricebook' ),
				'desc'  => __( 'Forces the price empty ("Call for Price") for every customer on this product.', 'wc-pricebook' ),
			),
		);
	}

	/**
	 * Render the standalone flowchart page.
	 *
	 * @param int $product_id Selected product ID (0 = none).
	 * @param int $user_id    Optional customer to resolve the price as (0 = none).
	 * @return void
	 */
	private function render_page( $product_id, $user_id = 0 ) {
		$tiers   = array_merge( array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) ), wp_list_pluck( $this->config->tiers(), 'label' ) );
		$product = $product_id ? wc_get_product( $product_id ) : null;
		$as_user = $user_id ? get_userdata( $user_id ) : null;

		// Pre-fill the search inputs with the current selection (the AJAX search shows
		// the same "#id — …" label format).
		$product_value = $product ? sprintf( '#%d — %s', $product_id, $product->get_name() ) : '';
		$user_value    = $as_user ? sprintf( '#%d — %s <%s>', (int) $user_id, $as_user->user_login, $as_user->user_email ) : '';

		// Customer-specific overrides for this product (these win over every tier and
		// quantity-break price for the matching customer).
		$user_pricing_meta = $this->config->user_pricing_meta();
		$user_rows         = ( '' !== $user_pricing_meta && $product_id ) ? get_post_meta( $product_id, $user_pricing_meta, true ) : array();
		$user_rows         = is_array( $user_rows ) ? $user_rows : array();

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Price resolution — Pricebook', 'wc-pricebook' ); ?></title>
	<style>
		:root {
			--paper: #f1f4f3;
			--card: #fff;
			--ink: #14171a;
			--muted: #5a6169;
			--faint: #8b9299;
			--rule: #e3e7e5;
			--rule-firm: #c7cfcc;
			--signal: #12594a;
			--signal-soft: #e7f1ed;
			--alert: #a4262c;
			--alert-soft: #fbedee;
			--caution: #8a5a00;
			--caution-soft: #fbf3e2;
			--ui: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
			--data: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
		}
		* { box-sizing: border-box; }
		body { font-family: var(--ui); margin: 0; background: var(--paper); color: var(--ink); font-size: 14px; line-height: 1.5; }
		.wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px 64px; }
		a { color: var(--signal); }
		:focus-visible { outline: 2px solid var(--signal); outline-offset: 2px; }

		/* Masthead: identity + the two pickers, always reachable. */
		.masthead { position: sticky; top: 0; z-index: 20; background: var(--card); border-bottom: 1px solid var(--rule-firm); }
		.masthead-inner { max-width: 1100px; margin: 0 auto; padding: 14px 24px; display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; }
		.brand { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--faint); font-weight: 600; padding-bottom: 9px; white-space: nowrap; }
		.brand b { color: var(--ink); font-weight: 600; }
		.picker { flex: 1 1 260px; min-width: 0; position: relative; }
		.picker label { display: block; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-bottom: 4px; }
		input[type=search] { width: 100%; padding: 8px 10px; font: inherit; background: var(--paper); border: 1px solid var(--rule-firm); border-radius: 4px; color: var(--ink); }
		input[type=search]:focus { background: var(--card); border-color: var(--signal); outline: none; box-shadow: 0 0 0 3px var(--signal-soft); }
		input[type=search]:disabled { color: var(--faint); cursor: not-allowed; }
		.results { list-style: none; margin: 0; padding: 4px; position: absolute; left: 0; right: 0; top: 100%; background: var(--card); border: 1px solid var(--rule-firm); border-radius: 4px; box-shadow: 0 8px 24px rgba(20,23,26,.14); display: none; max-height: 320px; overflow-y: auto; z-index: 30; }
		.results li { padding: 7px 8px; cursor: pointer; border-radius: 3px; }
		.results li:hover { background: var(--signal-soft); }

		/* Product identity. */
		.ident { padding: 28px 0 20px; border-bottom: 1px solid var(--rule); margin-bottom: 24px; }
		.ident h1 { font-size: 24px; margin: 0 0 6px; font-weight: 600; letter-spacing: -.01em; }
		.ident h1 a { color: inherit; text-decoration: none; border-bottom: 1px solid var(--rule-firm); }
		.ident h1 a:hover { border-bottom-color: var(--signal); color: var(--signal); }
		/* Jump to where the price is actually changed. */
		.edit-link { display: inline-flex; align-items: center; gap: 4px; margin-left: 10px; vertical-align: 3px; font-family: var(--ui); font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); padding: 3px 8px 3px 6px; border: 1px solid var(--rule-firm); border-radius: 4px; }
		.ident h1 a.edit-link:hover { color: var(--signal); border-color: var(--signal); background: var(--signal-soft); }
		.edit-link svg { width: 13px; height: 13px; display: block; }
		.pid { font-family: var(--data); color: var(--faint); font-weight: 400; }
		.empty { padding: 80px 0; text-align: center; color: var(--muted); }

		/* Sections. */
		section { margin-bottom: 32px; }
		h2 { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); font-weight: 700; margin: 0 0 10px; }
		.note { margin: 0 0 10px; color: var(--muted); font-size: 13px; max-width: 78ch; }
		.card { background: var(--card); border: 1px solid var(--rule); border-radius: 6px; padding: 16px 18px; }

		/* The answer: what this customer pays, and why. */
		.answer { background: var(--card); border: 1px solid var(--rule-firm); border-left: 3px solid var(--signal); border-radius: 6px; padding: 18px 20px; }
		.answer h2 { color: var(--signal); }
		.answer-price { font-family: var(--data); font-size: 30px; font-weight: 600; letter-spacing: -.02em; margin: 2px 0 0; }
		.answer-price .sale { color: var(--alert); }
		.answer-price .struck { color: var(--faint); text-decoration: line-through; font-weight: 400; font-size: 20px; }
		.who { font-size: 13px; color: var(--muted); margin: 0 0 12px; }
		.who strong { color: var(--ink); }

		/* Resolution trace: the engine's own audit trail, in order. */
		.trace { list-style: none; margin: 14px 0 0; padding: 0; counter-reset: step; border-left: 1px solid var(--rule-firm); }
		.trace li { counter-increment: step; position: relative; padding: 3px 0 3px 22px; font-size: 13px; color: var(--muted); font-family: var(--data); }
		.trace li::before { content: counter(step); position: absolute; left: -6px; top: 6px; width: 11px; height: 11px; border-radius: 50%; background: var(--paper); border: 1px solid var(--rule-firm); color: var(--faint); font-size: 8px; line-height: 9px; text-align: center; font-family: var(--ui); }
		.trace li:last-child { color: var(--ink); }
		.trace li:last-child::before { background: var(--signal); border-color: var(--signal); color: #fff; }

		/* Status callouts. */
		.flag { margin: 0 0 10px; padding: 8px 11px; border-radius: 4px; font-size: 13px; font-weight: 600; border: 1px solid; }
		.flag-bad { background: var(--alert-soft); border-color: #eccace; color: var(--alert); }
		.flag-warn { background: var(--caution-soft); border-color: #e6d9a2; color: var(--caution); }
		.flag-ok { background: var(--signal-soft); border-color: #bfdcd2; color: var(--signal); }

		/* Tables. */
		table { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--rule); border-radius: 6px; overflow: hidden; }
		th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--rule); vertical-align: top; }
		thead th { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); font-weight: 600; background: #fafbfb; border-bottom: 1px solid var(--rule-firm); white-space: nowrap; }
		tbody tr:last-child td { border-bottom: 0; }
		td.num, th.num { text-align: right; }
		.price { font-family: var(--data); font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }
		.price .amount { font-weight: 600; }
		.dash { color: var(--faint); font-weight: 400; }
		.tier-name { font-weight: 600; }
		.who .pid { font-size: 12px; }

		/* Override-behaviour badges — the "when it applies" rule, once per row. */
		.badge { display: inline-block; margin-left: 6px; font-size: 10px; letter-spacing: .05em; text-transform: uppercase; font-weight: 700; padding: 1px 6px; border-radius: 3px; white-space: nowrap; vertical-align: 1px; }
		.badge-always { background: var(--signal-soft); color: var(--signal); }
		.badge-priced { background: #eaeef7; color: #33468c; }
		.badge-competes { background: #f0f1f2; color: var(--muted); }
		.badge-bulk { background: var(--caution-soft); color: var(--caution); vertical-align: 8px; }

		/* Price-by-quantity ladder: the headline figure is one rung of this. */
		.ladder-wrap { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--rule); }
		.ladder-title { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); font-weight: 700; margin: 0 0 6px; }
		.ladder { list-style: none; margin: 0; padding: 0; }
		.ladder li { display: flex; align-items: baseline; gap: 12px; padding: 4px 0; border-bottom: 1px solid var(--rule); }
		.ladder li:last-child { border-bottom: 0; }
		.ladder .qty { font-family: var(--data); font-variant-numeric: tabular-nums; color: var(--muted); min-width: 68px; }
		.ladder .price { font-family: var(--data); font-variant-numeric: tabular-nums; font-weight: 600; min-width: 90px; }
		.ladder .rung-tag { font-size: 11px; letter-spacing: .04em; text-transform: uppercase; color: var(--faint); font-weight: 600; }
		.ladder li.is-bulk .price { color: var(--caution); }
		.ladder li.is-bulk .rung-tag { color: var(--caution); }
		.legend { display: flex; gap: 18px; flex-wrap: wrap; margin: 0 0 10px; padding: 0; list-style: none; font-size: 12px; color: var(--muted); }
		.legend li { display: flex; gap: 6px; align-items: baseline; }

		/* Quantity breaks. */
		.breaks { list-style: none; margin: 0; padding: 0; font-size: 13px; font-family: var(--data); font-variant-numeric: tabular-nums; }
		.breaks li { white-space: nowrap; padding: 1px 0; }
		.breaks .qty { display: inline-block; min-width: 44px; color: var(--muted); }
		.breaks .from { color: var(--faint); font-size: 11px; }

		/* Per-row resolution steps in the tier table. */
		.steps { margin: 0; padding-left: 15px; color: var(--muted); font-size: 12px; font-family: var(--data); }

		/* Reference material — present, but folded away. */
		details.ref { background: var(--card); border: 1px solid var(--rule); border-radius: 6px; padding: 0; }
		details.ref summary { cursor: pointer; padding: 11px 16px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); list-style: none; }
		details.ref summary::-webkit-details-marker { display: none; }
		details.ref summary::before { content: "▸"; display: inline-block; margin-right: 8px; color: var(--faint); transition: transform .15s; }
		details.ref[open] summary::before { transform: rotate(90deg); }
		details.ref summary:hover { color: var(--ink); }
		.ref-body { padding: 0 16px 14px; border-top: 1px solid var(--rule); }
		.ref-body ol { margin: 12px 0; padding-left: 20px; font-size: 13px; color: var(--muted); }
		.ref-body ol li { padding: 2px 0; }
		.ref-body ol li strong { color: var(--ink); }

		@media (max-width: 782px) {
			.wrap { padding: 0 14px 40px; }
			.masthead-inner { padding: 12px 14px; }
			.brand { padding-bottom: 0; }
			table, thead, tbody, tr, td { display: block; }
			thead { display: none; }
			tbody tr { border-bottom: 1px solid var(--rule-firm); padding: 6px 0; }
			tbody tr:last-child { border-bottom: 0; }
			td { border-bottom: 0; padding: 3px 12px; }
			td.num { text-align: left; }
			td[data-label]::before { content: attr(data-label); display: block; font-size: 10px; letter-spacing: .06em; text-transform: uppercase; color: var(--faint); font-weight: 600; }
		}
		@media (prefers-reduced-motion: reduce) {
			* { transition: none !important; }
		}
	</style>
</head>
<body>
	<div class="masthead">
		<div class="masthead-inner">
			<div class="brand"><?php esc_html_e( 'Pricebook', 'wc-pricebook' ); ?> · <b><?php esc_html_e( 'Price resolution', 'wc-pricebook' ); ?></b></div>
			<div class="picker">
				<label for="pb-search"><?php esc_html_e( 'Product', 'wc-pricebook' ); ?></label>
				<input type="search" id="pb-search" value="<?php echo esc_attr( $product_value ); ?>" placeholder="<?php esc_attr_e( 'Search by name or SKU…', 'wc-pricebook' ); ?>" autocomplete="off">
				<ul class="results" id="pb-results"></ul>
			</div>
			<div class="picker">
				<label for="pb-user-search"><?php esc_html_e( 'Resolve as customer', 'wc-pricebook' ); ?></label>
				<input type="search" id="pb-user-search" value="<?php echo esc_attr( $user_value ); ?>" placeholder="<?php echo $product ? esc_attr__( 'Search by name or email…', 'wc-pricebook' ) : esc_attr__( 'Pick a product first', 'wc-pricebook' ); ?>" autocomplete="off"<?php echo $product ? '' : ' disabled'; ?>>
				<ul class="results" id="pb-user-results"></ul>
			</div>
		</div>
	</div>

	<div class="wrap">
		<?php if ( ! $product ) : ?>
			<p class="empty"><?php esc_html_e( 'Search for a product to see how its price resolves across every tier.', 'wc-pricebook' ); ?></p>
		<?php endif; ?>

		<?php if ( $product ) : ?>
			<div>
				<?php
				$product_link = get_permalink( $product_id );
				$edit_link    = current_user_can( 'edit_post', $product_id ) ? get_edit_post_link( $product_id ) : '';
				$cat_terms    = get_the_terms( $product_id, 'product_cat' );
				$cat_terms    = ( is_array( $cat_terms ) ) ? $cat_terms : array();
				?>
				<div class="ident">
					<h1>
						<span class="pid">#<?php echo (int) $product_id; ?></span>
						<?php if ( $product_link ) : ?>
							<a href="<?php echo esc_url( $product_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $product->get_name() ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $product->get_name() ); ?>
						<?php endif; ?>
						<?php if ( $edit_link ) : ?>
							<a class="edit-link" href="<?php echo esc_url( $edit_link ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Edit this product', 'wc-pricebook' ); ?>">
								<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M13.5 3.2a1.7 1.7 0 0 1 2.4 2.4l-.9.9-2.4-2.4.9-.9ZM11.5 5.2l2.4 2.4-6.6 6.6-3 .6.6-3 6.6-6.6ZM4 16.5h12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php esc_html_e( 'Edit', 'wc-pricebook' ); ?>
							</a>
						<?php endif; ?>
					</h1>
					<p class="note" style="margin:0;">
						<?php
						if ( empty( $cat_terms ) ) {
							esc_html_e( 'No product categories', 'wc-pricebook' );
						} else {
							$cat_labels = array();
							foreach ( $cat_terms as $cat ) {
								$cat_labels[] = sprintf( '%s (#%d)', $cat->name, (int) $cat->term_id );
							}
							/* translators: %s: comma-separated product category names. */
							echo esc_html( sprintf( __( 'Categories: %s', 'wc-pricebook' ), implode( ', ', $cat_labels ) ) );
						}
						?>
					</p>
				</div>

				<?php
				// The answer first: what this specific customer pays, and the trail that got
				// there. Everything below it is the supporting detail.
				if ( $as_user ) :
					$base      = (float) get_post_meta( $product_id, $this->config->base_meta()['regular'], true );
					$u_reg     = $this->engine->price_for_user( $base, $product, $user_id, false );
					// What the customer actually pays: the effective (sale-applied) price.
					// Pass an EMPTY base so the engine derives the active price from the
					// product (including a storewide/bundle sale). Passing the regular base
					// here suppresses the sale — the engine uses the value it's handed.
					$paid      = $this->engine->effective_price( '', $product, $user_id );
					$priced_as = $this->context->resolve_pricing_user_id( (int) $user_id );
					$parent    = ( $priced_as !== (int) $user_id ) ? get_userdata( $priced_as ) : null;
					$role_user = $parent ? $parent : $as_user;
					$vis       = $this->context->product_visible_for_user( $product_id, $priced_as );

					$vis_messages = array(
						'manager'        => __( 'managers see the entire catalog.', 'wc-pricebook' ),
						'unrestricted'   => __( 'this customer has no catalog visibility restrictions.', 'wc-pricebook' ),
						'allowed'        => __( 'the product is within this customer\'s allowed categories.', 'wc-pricebook' ),
						'excluded'       => __( 'the product is in a category this customer is hidden from (Hide visibility).', 'wc-pricebook' ),
						'not_in_include' => __( 'this customer only sees specific categories, and this product is not one of them.', 'wc-pricebook' ),
						'forced_role'    => __( 'a per-product Force-product-visible override applies to this customer.', 'wc-pricebook' ),
					);
					$vis_text     = isset( $vis_messages[ $vis['reason'] ] ) ? $vis_messages[ $vis['reason'] ] : '';
					$price_hidden = $this->context->price_hidden_by_visibility_role( $product_id, $priced_as );
					$price_forced = $this->rules->applies( 'force_visible', $product_id ) || $this->context->user_force_price( $product_id, $priced_as );

					// The headline price is the single-unit price. When quantity moves it, say
					// so next to the figure rather than letting it read as the whole story.
					$ladder     = $this->customer_qty_ladder( $base, $product, $product_id, (int) $user_id );
					$qty1_bulk  = ! empty( $ladder ) && $ladder[0]['is_bulk'];
					?>
					<section>
						<div class="answer">
							<h2>
								<?php echo $ladder ? esc_html__( 'This customer pays, at quantity 1', 'wc-pricebook' ) : esc_html__( 'This customer pays', 'wc-pricebook' ); ?>
							</h2>
							<p class="answer-price">
								<?php echo $this->price_cell( $paid, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
								<?php if ( $qty1_bulk ) : ?>
									<span class="badge badge-bulk"><?php esc_html_e( 'Bulk price', 'wc-pricebook' ); ?></span>
								<?php endif; ?>
							</p>
							<?php
							// On sale when what they pay undercuts the regular tier price — show the
							// struck-through regular for context (the headline already shows the sale).
							$has_sale = '' !== (string) $paid && null !== $paid
								&& '' !== (string) $u_reg[0] && null !== $u_reg[0]
								&& (float) $paid < (float) $u_reg[0];
							if ( $has_sale ) :
								?>
								<p class="answer-price" style="font-size:15px;margin-top:4px;">
									<span class="sale"><?php esc_html_e( 'Regular:', 'wc-pricebook' ); ?> <s><?php echo $this->price_cell( $u_reg[0], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></s></span>
								</p>
							<?php endif; ?>
							<p class="who" style="margin-top:10px;">
								<strong><?php echo esc_html( $as_user->user_login ); ?></strong>
								<span class="pid">#<?php echo (int) $user_id; ?></span>
								·
								<?php
								/* translators: %s: comma-separated roles used for pricing. */
								echo esc_html( sprintf( __( 'roles: %s', 'wc-pricebook' ), implode( ', ', (array) $role_user->roles ) ) );
								?>
							</p>
							<?php if ( $parent ) : ?>
								<p class="flag flag-warn">
									<?php
									/* translators: 1: parent account login, 2: parent account ID. */
									echo esc_html( sprintf( __( 'Sub-account — priced using its parent account %1$s (#%2$d). The roles above are the parent\'s.', 'wc-pricebook' ), $parent->user_login, (int) $priced_as ) );
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! $vis['visible'] ) : ?>
								<p class="flag flag-bad">
									<?php
									/* translators: %s: reason the product is hidden. */
									echo esc_html( sprintf( __( 'Hidden from this customer\'s catalog — %s', 'wc-pricebook' ), $vis_text ) );
									?>
								</p>
							<?php else : ?>
								<p class="flag flag-ok">
									<?php
									/* translators: %s: reason the product is visible. */
									echo esc_html( sprintf( __( 'Visible in catalog — %s', 'wc-pricebook' ), $vis_text ) );
									?>
								</p>
							<?php endif; ?>
							<?php if ( $price_hidden && $vis['visible'] && ! $price_forced ) : ?>
								<p class="flag flag-warn">
									<?php esc_html_e( 'Price hidden for this customer (Call for Price) via a visibility role.', 'wc-pricebook' ); ?>
								</p>
							<?php elseif ( $price_hidden && $vis['visible'] && $price_forced ) : ?>
								<p class="flag flag-ok">
									<?php esc_html_e( 'A visibility role would hide the price, but a Force-price-visible override shows it to this customer.', 'wc-pricebook' ); ?>
								</p>
							<?php endif; ?>
							<ol class="trace">
								<?php foreach ( $u_reg[1] as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>

							<?php if ( $ladder ) : ?>
								<div class="ladder-wrap">
									<h3 class="ladder-title"><?php esc_html_e( 'Price by quantity', 'wc-pricebook' ); ?></h3>
									<p class="note" style="margin-bottom:8px;"><?php esc_html_e( 'This product is bulk priced for this customer — the per-unit price they pay depends on how many they order.', 'wc-pricebook' ); ?></p>
									<ul class="ladder">
										<?php foreach ( $ladder as $rung ) : ?>
											<li<?php echo $rung['is_bulk'] ? ' class="is-bulk"' : ''; ?>>
												<span class="qty">
													<?php
													echo esc_html(
														$rung['max'] > 0
															? sprintf( '%1$d–%2$d', $rung['min'], $rung['max'] )
															: sprintf( '%d+', $rung['min'] )
													);
													?>
												</span>
												<span class="price"><?php echo $this->price_cell( $rung['price'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></span>
												<span class="rung-tag">
													<?php echo $rung['is_bulk'] ? esc_html__( 'quantity break', 'wc-pricebook' ) : esc_html__( 'tier price', 'wc-pricebook' ); ?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</div>
					</section>
					<?php
				endif;
				?>

				<section>
					<details class="ref">
						<summary><?php esc_html_e( 'How a price is chosen', 'wc-pricebook' ); ?></summary>
						<div class="ref-body">
							<ol>
								<li><strong><?php esc_html_e( 'A customer-specific price', 'wc-pricebook' ); ?></strong> — <?php esc_html_e( 'if one is set for them, it beats everything.', 'wc-pricebook' ); ?></li>
								<li><strong><?php esc_html_e( 'A quantity break', 'wc-pricebook' ); ?></strong> — <?php esc_html_e( 'if the line quantity qualifies, it overrides all tier pricing, even when a tier price would be lower. Only a customer-specific price outranks it.', 'wc-pricebook' ); ?></li>
								<li><strong><?php esc_html_e( 'An "Always" tier', 'wc-pricebook' ); ?></strong> — <?php esc_html_e( 'among the tiers the customer qualifies for, one that always overrides wins outright in its category scope.', 'wc-pricebook' ); ?></li>
								<li><strong><?php esc_html_e( 'A "When priced" tier', 'wc-pricebook' ); ?></strong> — <?php esc_html_e( 'wins only if it has its own price for this product (e.g. operator pricing). With no price it steps aside.', 'wc-pricebook' ); ?></li>
								<li><strong><?php esc_html_e( 'The lowest remaining tier price', 'wc-pricebook' ); ?></strong> — <?php esc_html_e( 'otherwise this applies.', 'wc-pricebook' ); ?></li>
							</ol>
							<p class="note"><strong><?php esc_html_e( 'Quantity breaks:', 'wc-pricebook' ); ?></strong> <?php esc_html_e( 'bulk pricing applies only at quantities that meet a break — below the lowest break (e.g. qty 1 when breaks start at 2) the normal tier price stands. Once a break is met, its price is used outright rather than compared against the tier price.', 'wc-pricebook' ); ?></p>
						</div>
					</details>
				</section>

				<?php
				// Whether this product is genuinely "call for price": empty prices for it
				// render the Call for Price label rather than a dash (and never $0.00).
				$is_cfp            = $this->rules->applies( 'call_for_price', $product_id );
				$rule_descriptions = self::rule_descriptions();
				$applied_rules     = array();
				foreach ( array_keys( $this->config->rules() ) as $rule_key ) {
					if ( $this->rules->applies( $rule_key, $product_id ) ) {
						$applied_rules[ $rule_key ] = $this->rules->matched_terms( $rule_key, $product_id );
					}
				}
				?>
				<section>
				<h2><?php esc_html_e( 'Pricing rules in effect', 'wc-pricebook' ); ?></h2>
				<?php if ( empty( $applied_rules ) ) : ?>
					<p class="card note" style="margin:0;"><?php esc_html_e( 'No pricing rules apply to this product — standard tier resolution only.', 'wc-pricebook' ); ?></p>
				<?php else : ?>
					<p class="note"><?php esc_html_e( 'These flags, tags, or categories on the product change how its price resolves:', 'wc-pricebook' ); ?></p>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Rule', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'What it does', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Triggered by', 'wc-pricebook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $applied_rules as $rule_key => $matched ) : ?>
								<tr>
									<td><span class="tier-name"><?php echo esc_html( $rule_descriptions[ $rule_key ]['label'] ?? ucwords( str_replace( '_', ' ', $rule_key ) ) ); ?></span></td>
									<td class="note"><?php echo esc_html( $rule_descriptions[ $rule_key ]['desc'] ?? '' ); ?></td>
									<td class="note">
										<?php
										$labels = array();
										foreach ( $matched as $term ) {
											$labels[] = sprintf( '%s (%s #%d)', $term['name'], $term['taxonomy'], $term['term_id'] );
										}
										echo esc_html( empty( $labels ) ? __( 'Set on this product', 'wc-pricebook' ) : implode( ', ', $labels ) );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				</section>

				<?php
				$fv_roles = $this->context->product_force_visible_roles( $product_id );
				$fp_roles = $this->context->product_force_price_roles( $product_id );
				$fv_users = get_post_meta( $product_id, \WCPricebook\Context::FORCE_VISIBLE_USERS_META, true );
				$fv_users = is_array( $fv_users ) ? $fv_users : array();
				$fp_users = get_post_meta( $product_id, \WCPricebook\Context::FORCE_PRICE_USERS_META, true );
				$fp_users = is_array( $fp_users ) ? $fp_users : array();
				if ( $fv_roles || $fp_roles || $fv_users || $fp_users ) :
					?>
					<section>
					<h2><?php esc_html_e( 'Force visibility overrides', 'wc-pricebook' ); ?></h2>
					<p class="note"><?php esc_html_e( 'These roles and customers always see the product, or its price, overriding any Hide visibility role.', 'wc-pricebook' ); ?></p>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Override', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Roles', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Users', 'wc-pricebook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><span class="tier-name"><?php esc_html_e( 'Force product visible', 'wc-pricebook' ); ?></span></td>
								<td class="note" data-label="<?php esc_attr_e( 'Roles', 'wc-pricebook' ); ?>"><?php echo esc_html( $fv_roles ? implode( ', ', array_map( array( $this, 'role_label' ), $fv_roles ) ) : '—' ); ?></td>
								<td class="note" data-label="<?php esc_attr_e( 'Users', 'wc-pricebook' ); ?>"><?php echo esc_html( $fv_users ? implode( ', ', $this->user_labels( $fv_users ) ) : '—' ); ?></td>
							</tr>
							<tr>
								<td><span class="tier-name"><?php esc_html_e( 'Force price visible', 'wc-pricebook' ); ?></span></td>
								<td class="note" data-label="<?php esc_attr_e( 'Roles', 'wc-pricebook' ); ?>"><?php echo esc_html( $fp_roles ? implode( ', ', array_map( array( $this, 'role_label' ), $fp_roles ) ) : '—' ); ?></td>
								<td class="note" data-label="<?php esc_attr_e( 'Users', 'wc-pricebook' ); ?>"><?php echo esc_html( $fp_users ? implode( ', ', $this->user_labels( $fp_users ) ) : '—' ); ?></td>
							</tr>
						</tbody>
					</table>
					</section>
					<?php
				endif;
				?>

				<section>
					<h2><?php esc_html_e( 'Tier prices', 'wc-pricebook' ); ?></h2>
					<ul class="legend">
						<li><span class="badge badge-always"><?php esc_html_e( 'Always', 'wc-pricebook' ); ?></span> <?php esc_html_e( 'wins outright in its category scope', 'wc-pricebook' ); ?></li>
						<li><span class="badge badge-priced"><?php esc_html_e( 'When priced', 'wc-pricebook' ); ?></span> <?php esc_html_e( 'wins only if it prices this product', 'wc-pricebook' ); ?></li>
						<li><span class="badge badge-competes"><?php esc_html_e( 'Competes', 'wc-pricebook' ); ?></span> <?php esc_html_e( 'lowest qualifying price wins', 'wc-pricebook' ); ?></li>
					</ul>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tier', 'wc-pricebook' ); ?></th>
								<th class="num"><?php esc_html_e( 'Regular', 'wc-pricebook' ); ?></th>
								<th class="num"><?php esc_html_e( 'Sale', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Quantity breaks', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Resolution steps', 'wc-pricebook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tiers as $tier_key => $label ) : ?>
								<?php
								$regular  = $this->engine->price_as_tier( $product_id, $tier_key, false );
								$sale     = $this->engine->price_as_tier( $product_id, $tier_key, true );
								$breaks   = 'msrp' === $tier_key ? array() : $this->engine->bulk_breaks( $product_id, $tier_key );
								$tier_cfg = 'msrp' === $tier_key ? null : $this->config->tier( $tier_key );
								$override = $tier_cfg ? (string) $tier_cfg['override'] : '';
								if ( 'always' === $override ) {
									$badge_class = 'badge-always';
									$badge_text  = __( 'Always', 'wc-pricebook' );
								} elseif ( 'when_priced' === $override ) {
									$badge_class = 'badge-priced';
									$badge_text  = __( 'When priced', 'wc-pricebook' );
								} else {
									$badge_class = 'badge-competes';
									$badge_text  = __( 'Competes', 'wc-pricebook' );
								}
								?>
								<tr>
									<td>
										<span class="tier-name"><?php echo esc_html( $label ); ?></span>
										<?php if ( 'msrp' !== $tier_key ) : ?>
											<span class="badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
										<?php endif; ?>
									</td>
									<td class="price num" data-label="<?php esc_attr_e( 'Regular', 'wc-pricebook' ); ?>"><?php echo $this->price_cell( $regular[0], $is_cfp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></td>
									<td class="price num" data-label="<?php esc_attr_e( 'Sale', 'wc-pricebook' ); ?>"><?php echo $this->price_cell( $sale[0], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></td>
									<td data-label="<?php esc_attr_e( 'Quantity breaks', 'wc-pricebook' ); ?>">
										<?php if ( empty( $breaks ) ) : ?>
											<span class="dash">—</span>
										<?php else : ?>
											<ul class="breaks">
												<?php foreach ( $breaks as $break ) : ?>
													<?php
													$range = (int) $break['max_qty'] > 0
														? sprintf( '%d&ndash;%d', (int) $break['min_qty'], (int) $break['max_qty'] )
														: sprintf( '%d+', (int) $break['min_qty'] );
													?>
													<li>
														<span class="qty"><?php echo wp_kses_post( $range ); ?></span>
														<span class="price"><?php echo wp_kses_post( wc_price( $break['price'] ) ); ?></span>
														<?php if ( '' !== (string) $regular[0] ) : ?>
															<span class="from">
																<?php
																/* translators: %s: the tier's regular (pre-quantity-break) price the bulk price is discounted from. */
																printf( esc_html__( 'from %s', 'wc-pricebook' ), wp_kses_post( wc_price( $regular[0] ) ) );
																?>
															</span>
														<?php endif; ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Resolution steps', 'wc-pricebook' ); ?>">
										<?php if ( ! empty( $breaks ) ) : ?>
											<span class="note"><?php esc_html_e( 'Quantity-based — see breaks', 'wc-pricebook' ); ?></span>
										<?php else : ?>
											<ol class="steps">
												<?php foreach ( $regular[1] as $step ) : ?>
													<li><?php echo esc_html( $step ); ?></li>
												<?php endforeach; ?>
											</ol>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>

				<?php
				// Bulk rows keyed by a role / MSRP Customer that is not a configured pricing
				// tier — these never appear in the tier table above.
				$bulk_all   = get_post_meta( $product_id, $this->config->bulk_pricing_meta(), true );
				$bulk_all   = is_array( $bulk_all ) ? $bulk_all : array();
				$extra_bulk = array_diff_key( $bulk_all, $tiers );
				if ( ! empty( $extra_bulk ) ) :
					?>
					<section>
					<h2><?php esc_html_e( 'Role-targeted quantity breaks', 'wc-pricebook' ); ?></h2>
					<p class="note"><?php esc_html_e( 'Bulk pricing set for roles that are not configured pricing tiers. It applies to any customer who has the role — the lowest applicable price wins.', 'wc-pricebook' ); ?></p>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Role', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Quantity breaks', 'wc-pricebook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_keys( $extra_bulk ) as $bulk_key ) : ?>
								<tr>
									<td><span class="tier-name"><?php echo esc_html( $this->role_label( (string) $bulk_key ) ); ?></span></td>
									<td data-label="<?php esc_attr_e( 'Quantity breaks', 'wc-pricebook' ); ?>">
										<?php $break_rows = $this->engine->bulk_breaks( $product_id, (string) $bulk_key ); ?>
										<?php if ( empty( $break_rows ) ) : ?>
											<span class="dash">—</span>
										<?php else : ?>
											<ul class="breaks">
												<?php foreach ( $break_rows as $b ) : ?>
													<li>
														<span class="qty"><?php echo esc_html( 0 === (int) $b['max_qty'] ? sprintf( '%d+', (int) $b['min_qty'] ) : sprintf( '%1$d–%2$d', (int) $b['min_qty'], (int) $b['max_qty'] ) ); ?></span>
														<?php echo $this->price_cell( $b['price'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</section>
					<?php
				endif;
				?>

				<?php if ( ! empty( $user_rows ) ) : ?>
					<section>
					<h2><?php esc_html_e( 'Customer-specific prices', 'wc-pricebook' ); ?></h2>
					<p class="note"><?php esc_html_e( 'A price set here for a customer wins over every tier and quantity-break price for that customer.', 'wc-pricebook' ); ?></p>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'wc-pricebook' ); ?></th>
								<th class="num"><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $user_rows as $row ) {
								if ( ! is_array( $row ) || ! isset( $row['user-id'], $row['price'] ) ) {
									continue;
								}
								$uid  = (int) $row['user-id'];
								$user = get_userdata( $uid );
								$name = $user ? sprintf( '%s (#%d)', $user->display_name, $uid ) : sprintf( '#%d', $uid );
								printf(
									'<tr><td><span class="tier-name">%s</span></td><td class="price num" data-label="%s">%s</td></tr>',
									esc_html( $name ),
									esc_attr__( 'Price', 'wc-pricebook' ),
									$this->price_cell( $row['price'], false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
								);
							}
							?>
						</tbody>
					</table>
					</section>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<script>
	( function () {
		var ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var productId = <?php echo (int) $product_id; ?>;

		function wire( inputId, listId, action, onPick ) {
			var input = document.getElementById( inputId );
			var list  = document.getElementById( listId );
			if ( ! input || ! list ) { return; }
			var timer, lastQuery;
			function run() {
				clearTimeout( timer );
				var q = input.value.trim();
				if ( q.length < 2 ) { list.style.display = 'none'; lastQuery = q; return; }
				if ( q === lastQuery ) { return; }
				lastQuery = q;
				timer = setTimeout( function () {
					fetch( ajaxUrl + '?action=' + encodeURIComponent( action ) + '&q=' + encodeURIComponent( q ) )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							list.innerHTML = '';
							( res.data || [] ).forEach( function ( item ) {
								var li = document.createElement( 'li' );
								li.textContent = item.text;
								li.addEventListener( 'click', function () { onPick( item ); } );
								list.appendChild( li );
							} );
							list.style.display = res.data && res.data.length ? 'block' : 'none';
						} );
				}, 250 );
			}
			input.addEventListener( 'input', run );
			// Paste and autofill can update the value without a normal 'input' event, or
			// before it settles — re-check on the next tick so a pasted title searches.
			input.addEventListener( 'paste', function () { setTimeout( run, 0 ); } );
			input.addEventListener( 'change', run );
			input.addEventListener( 'focus', run );
		}

		wire( 'pb-search', 'pb-results', <?php echo wp_json_encode( self::AJAX_SEARCH ); ?>, function ( item ) {
			window.location.search = '?product_id=' + item.id;
		} );
		wire( 'pb-user-search', 'pb-user-results', <?php echo wp_json_encode( self::AJAX_USER_SEARCH ); ?>, function ( item ) {
			window.location.search = '?product_id=' + productId + '&as_user=' + item.id;
		} );
	} )();
	</script>
</body>
</html>
		<?php
	}
}
