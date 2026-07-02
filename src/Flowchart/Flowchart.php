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
			return '—';
		}
		return esc_html__( 'Call for Price', 'wc-pricebook' );
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
				'desc'  => __( 'Suppresses the dealer-percentage discount — discount tiers collapse to the plain dealer base price.', 'wc-pricebook' ),
			),
			'force_visible'     => array(
				'label' => __( 'Force visible', 'wc-pricebook' ),
				'desc'  => __( 'Keeps the product (and its price) visible even when a hidden-category rule would otherwise hide it.', 'wc-pricebook' ),
			),
			'price_requires_tier' => array(
				'label' => __( 'Price requires tier', 'wc-pricebook' ),
				'desc'  => __( 'Hides the price from customers who do not hold a pricing tier for this product.', 'wc-pricebook' ),
			),
			'hidden_categories' => array(
				'label' => __( 'Hidden categories', 'wc-pricebook' ),
				'desc'  => __( 'Hides the product from customers outside the roles allowed to see this category.', 'wc-pricebook' ),
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
	<title><?php esc_html_e( 'Pricebook Flowchart', 'wc-pricebook' ); ?></title>
	<style>
		body { font-family: -apple-system, system-ui, sans-serif; margin: 0; background: #f6f7f7; color: #1d2327; }
		.wrap { max-width: 980px; margin: 0 auto; padding: 24px; }
		h1 { font-size: 22px; }
		.card { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px 20px; margin-bottom: 16px; }
		input[type=search] { width: 100%; padding: 10px; font-size: 15px; box-sizing: border-box; }
		.results { list-style: none; margin: 6px 0 0; padding: 0; border: 1px solid #dcdcde; border-top: 0; display: none; }
		.results li { padding: 8px 10px; cursor: pointer; }
		.results li:hover { background: #f0f6fc; }
		table { width: 100%; border-collapse: collapse; }
		th, td { text-align: left; padding: 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
		th { border-bottom: 2px solid #dcdcde; }
		.steps { margin: 0; padding-left: 18px; color: #50575e; font-size: 13px; }
		.price { font-weight: 600; white-space: nowrap; }
		.breaks { list-style: none; margin: 0; padding: 0; font-size: 13px; }
		.breaks li { white-space: nowrap; }
		.breaks .qty { color: #50575e; }
		.breaks .from { color: #646970; font-style: italic; }
		h3 { font-size: 15px; margin: 20px 0 4px; }
		.note { margin: 0 0 8px; color: #646970; font-size: 13px; }
	</style>
</head>
<body>
	<div class="wrap">
		<h1><?php esc_html_e( 'Pricebook Flowchart', 'wc-pricebook' ); ?></h1>

		<div class="card">
			<label for="pb-search"><strong><?php esc_html_e( 'Find a product', 'wc-pricebook' ); ?></strong></label>
			<input type="search" id="pb-search" value="<?php echo esc_attr( $product_value ); ?>" placeholder="<?php esc_attr_e( 'Type at least 2 characters…', 'wc-pricebook' ); ?>" autocomplete="off">
			<ul class="results" id="pb-results"></ul>
		</div>

		<div class="card">
			<label for="pb-user-search"><strong><?php esc_html_e( 'Resolve as a customer (optional)', 'wc-pricebook' ); ?></strong></label>
			<input type="search" id="pb-user-search" value="<?php echo esc_attr( $user_value ); ?>" placeholder="<?php esc_attr_e( 'Search customers by name or email…', 'wc-pricebook' ); ?>" autocomplete="off"<?php echo $product ? '' : ' disabled'; ?>>
			<ul class="results" id="pb-user-results"></ul>
			<?php if ( ! $product ) : ?><p class="note"><?php esc_html_e( 'Pick a product first, then choose a customer to see the exact price they get and why.', 'wc-pricebook' ); ?></p><?php endif; ?>
		</div>

		<?php if ( $product ) : ?>
			<div class="card">
				<?php $product_link = get_permalink( $product_id ); ?>
				<h2>
					<?php if ( $product_link ) : ?>
						<a href="<?php echo esc_url( $product_link ); ?>" target="_blank" rel="noopener"><?php echo esc_html( sprintf( '#%d — %s', $product_id, $product->get_name() ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( sprintf( '#%d — %s', $product_id, $product->get_name() ) ); ?>
					<?php endif; ?>
				</h2>
				<?php
				$cat_terms = get_the_terms( $product_id, 'product_cat' );
				$cat_terms = ( is_array( $cat_terms ) ) ? $cat_terms : array();
				?>
				<p class="note" style="margin-top:0;">
					<strong><?php esc_html_e( 'Product categories:', 'wc-pricebook' ); ?></strong>
					<?php
					if ( empty( $cat_terms ) ) {
						esc_html_e( 'none', 'wc-pricebook' );
					} else {
						$cat_labels = array();
						foreach ( $cat_terms as $cat ) {
							$cat_labels[] = sprintf( '%s (#%d)', $cat->name, (int) $cat->term_id );
						}
						echo esc_html( implode( ', ', $cat_labels ) );
					}
					?>
				</p>
				<?php
				$flag_terms = get_the_terms( $product_id, 'product_flag' );
				$flag_terms = ( is_array( $flag_terms ) ) ? $flag_terms : array();
				?>
				<p class="note" style="margin-top:0;">
					<strong><?php esc_html_e( 'Product flags:', 'wc-pricebook' ); ?></strong>
					<?php
					if ( empty( $flag_terms ) ) {
						esc_html_e( 'none', 'wc-pricebook' );
					} else {
						$flag_labels = array();
						foreach ( $flag_terms as $flag ) {
							$flag_labels[] = sprintf( '%s (#%d)', $flag->name, (int) $flag->term_id );
						}
						echo esc_html( implode( ', ', $flag_labels ) );
					}
					?>
				</p>
				<p class="note"><?php esc_html_e( 'How a customer\'s price is chosen, in order:', 'wc-pricebook' ); ?></p>
				<ol class="steps">
					<li><?php esc_html_e( 'A customer-specific price (below), if set for them — beats everything.', 'wc-pricebook' ); ?></li>
					<li><?php esc_html_e( 'Among the tiers the customer qualifies for: an "Always overrides" tier in its category scope wins outright.', 'wc-pricebook' ); ?></li>
					<li><?php esc_html_e( 'Otherwise an "Overrides when priced" tier wins only if it has its own price for this product (e.g. operator pricing); with no price it steps aside.', 'wc-pricebook' ); ?></li>
					<li><?php esc_html_e( 'Otherwise the lowest of the remaining tier prices applies.', 'wc-pricebook' ); ?></li>
				</ol>

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
				<h3><?php esc_html_e( 'Pricing rules in effect', 'wc-pricebook' ); ?></h3>
				<?php if ( empty( $applied_rules ) ) : ?>
					<p class="note"><?php esc_html_e( 'No pricing rules apply to this product — standard tier resolution only.', 'wc-pricebook' ); ?></p>
				<?php else : ?>
					<p class="note"><?php esc_html_e( 'These flags/tags/categories on the product change how its price resolves:', 'wc-pricebook' ); ?></p>
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
									<td><strong><?php echo esc_html( $rule_descriptions[ $rule_key ]['label'] ?? ucwords( str_replace( '_', ' ', $rule_key ) ) ); ?></strong></td>
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

				<?php
				if ( $as_user ) :
					$base      = (float) get_post_meta( $product_id, $this->config->base_meta()['regular'], true );
					$u_reg     = $this->engine->price_for_user( $base, $product, $user_id, false );
					$u_sale    = $this->engine->price_for_user( $base, $product, $user_id, true );
					$priced_as = $this->context->resolve_pricing_user_id( (int) $user_id );
					$parent    = ( $priced_as !== (int) $user_id ) ? get_userdata( $priced_as ) : null;
					$role_user = $parent ? $parent : $as_user;
					$vis       = $this->context->product_visible_for_user( $product_id, $priced_as );
					?>
					<div class="card" style="background:#f0f6fc;border-color:#c5d9ed;">
						<h3 style="margin-top:0;">
							<?php
							/* translators: 1: customer login, 2: customer ID. */
							echo esc_html( sprintf( __( 'Price for %1$s (#%2$d)', 'wc-pricebook' ), $as_user->user_login, (int) $user_id ) );
							?>
						</h3>
						<?php if ( $parent ) : ?>
							<p class="note" style="margin-top:0;">
								<?php
								/* translators: 1: parent account login, 2: parent account ID. */
								echo esc_html( sprintf( __( 'Sub-account — priced using its PARENT account %1$s (#%2$d). The roles below are the parent\'s.', 'wc-pricebook' ), $parent->user_login, (int) $priced_as ) );
								?>
							</p>
						<?php endif; ?>
						<p class="note" style="margin-top:0;">
							<?php
							/* translators: %s: comma-separated roles used for pricing. */
							echo esc_html( sprintf( __( 'Pricing roles: %s', 'wc-pricebook' ), implode( ', ', (array) $role_user->roles ) ) );
							?>
						</p>
						<?php
						$vis_messages = array(
							'manager'        => __( 'managers see the entire catalog.', 'wc-pricebook' ),
							'unrestricted'   => __( 'this customer has no catalog visibility restrictions.', 'wc-pricebook' ),
							'allowed'        => __( 'the product is within this customer\'s allowed categories.', 'wc-pricebook' ),
							'excluded'       => __( 'the product is in a category this customer is hidden from (Hide visibility).', 'wc-pricebook' ),
							'not_in_include' => __( 'this customer only sees specific categories, and this product is not one of them.', 'wc-pricebook' ),
						);
						$vis_text     = isset( $vis_messages[ $vis['reason'] ] ) ? $vis_messages[ $vis['reason'] ] : '';
						$price_hidden = $this->context->price_hidden_by_visibility_role( $product_id, $priced_as );
						?>
						<?php if ( ! $vis['visible'] ) : ?>
							<p class="note" style="margin:0 0 8px;padding:8px 10px;background:#fcf0f1;border:1px solid #f0c0c4;border-radius:4px;color:#b32d2e;font-weight:600;">
								<?php
								/* translators: %s: reason the product is hidden. */
								echo esc_html( sprintf( __( 'HIDDEN from this customer\'s catalog — %s', 'wc-pricebook' ), $vis_text ) );
								?>
							</p>
						<?php else : ?>
							<p class="note" style="margin-top:0;color:#00701a;font-weight:600;">
								<?php
								/* translators: %s: reason the product is visible. */
								echo esc_html( sprintf( __( 'Catalog visibility: Visible — %s', 'wc-pricebook' ), $vis_text ) );
								?>
							</p>
						<?php endif; ?>
						<?php if ( $price_hidden && $vis['visible'] ) : ?>
							<p class="note" style="margin:0 0 8px;padding:8px 10px;background:#fcf9e8;border:1px solid #e6d9a2;border-radius:4px;color:#8a6d00;font-weight:600;">
								<?php esc_html_e( 'Price hidden for this customer (Call for Price) via a visibility role.', 'wc-pricebook' ); ?>
							</p>
						<?php endif; ?>
						<p class="price">
							<?php esc_html_e( 'Regular:', 'wc-pricebook' ); ?> <?php echo $this->price_cell( $u_reg[0], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
							&nbsp;&middot;&nbsp;
							<?php esc_html_e( 'Sale:', 'wc-pricebook' ); ?> <?php echo $this->price_cell( $u_sale[0], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
						</p>
						<p class="note" style="margin-bottom:2px;"><?php esc_html_e( 'How this price was resolved:', 'wc-pricebook' ); ?></p>
						<ol class="steps">
							<?php foreach ( $u_reg[1] as $step ) : ?>
								<li><?php echo esc_html( $step ); ?></li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php endif; ?>

				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'wc-pricebook' ); ?></th>
							<th><?php esc_html_e( 'When it applies', 'wc-pricebook' ); ?></th>
							<th><?php esc_html_e( 'Regular', 'wc-pricebook' ); ?></th>
							<th><?php esc_html_e( 'Sale', 'wc-pricebook' ); ?></th>
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
								$applies = __( 'Always overrides other tiers (in its category scope).', 'wc-pricebook' );
							} elseif ( 'when_priced' === $override ) {
								$applies = __( 'Overrides other tiers only when this tier has its own price set; otherwise it steps aside and the lowest tier price applies.', 'wc-pricebook' );
							} else {
								$applies = __( 'Competes on price — the lowest tier the customer qualifies for wins.', 'wc-pricebook' );
							}
							?>
							<tr>
								<td><strong><?php echo esc_html( $label ); ?></strong></td>
								<td class="note"><?php echo esc_html( $applies ); ?></td>
								<td class="price"><?php echo $this->price_cell( $regular[0], $is_cfp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></td>
								<td class="price"><?php echo $this->price_cell( $sale[0], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></td>
								<td>
									<?php if ( empty( $breaks ) ) : ?>
										—
									<?php else : ?>
										<ul class="breaks">
											<?php foreach ( $breaks as $break ) : ?>
												<?php
												$range = (int) $break['max_qty'] > 0
													? sprintf( '%d&ndash;%d', (int) $break['min_qty'], (int) $break['max_qty'] )
													: sprintf( '%d+', (int) $break['min_qty'] );
												?>
												<li>
													<span class="qty"><?php echo wp_kses_post( $range ); ?></span>:
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
								<td>
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

				<?php if ( ! empty( $user_rows ) ) : ?>
					<h3><?php esc_html_e( 'Customer-specific prices', 'wc-pricebook' ); ?></h3>
					<p class="note"><?php esc_html_e( 'A price set here for a customer wins over every tier and quantity-break price for that customer.', 'wc-pricebook' ); ?></p>
					<table>
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'wc-pricebook' ); ?></th>
								<th><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></th>
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
									'<tr><td>%s</td><td class="price">%s</td></tr>',
									esc_html( $name ),
									$this->price_cell( $row['price'], false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
								);
							}
							?>
						</tbody>
					</table>
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
