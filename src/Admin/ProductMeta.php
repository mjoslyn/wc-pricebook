<?php
/**
 * Product Data tab for per-role manual prices.
 *
 * Adds a "Pricebook" tab to the WooCommerce Product Data panel where staff add
 * role/price rows (pick a pricing role, enter its price for this product). Each
 * value is stored under that role's price-meta key, which the engine reads as the
 * tier's specific price ({@see \WCPricebook\PriceEngine}). Roles removed from the
 * list have their stored price cleared.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Admin;

use WCPricebook\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves manual per-role prices in the product data panel.
 */
class ProductMeta {

	const NONCE_ACTION = 'wc_pricebook_product_meta';
	const NONCE_FIELD  = 'wc_pricebook_product_meta_nonce';

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Tier keys with quantity-break pricing for the product being rendered, used to
	 * annotate the role-price dropdown. Set per render in {@see self::render_panel}.
	 *
	 * @var array<string,bool>
	 */
	private $bulk_roles = array();

	/**
	 * Constructor.
	 *
	 * @param Config $config Config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the shared repeater assets on the product editor.
	 *
	 * @return void
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'wc-pricebook-settings', WC_PRICEBOOK_URL . 'src/Admin/assets/settings.css', array(), WC_PRICEBOOK_VERSION );
		// The per-user rows use WooCommerce's customer-search select; depend on its
		// script so the enhance handler is present when a row is added.
		wp_enqueue_script( 'wc-pricebook-settings', WC_PRICEBOOK_URL . 'src/Admin/assets/settings.js', array( 'wc-enhanced-select' ), WC_PRICEBOOK_VERSION, true );
	}

	/**
	 * Add the Pricebook product data tab.
	 *
	 * @param array<string,array<string,mixed>> $tabs Existing tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_tab( $tabs ) {
		$tabs['wc_pricebook'] = array(
			'label'    => __( 'Pricebook', 'wc-pricebook' ),
			'target'   => 'wc_pricebook_role_prices',
			'class'    => array(),
			'priority' => 21,
		);
		return $tabs;
	}

	/**
	 * Tier options (key => label) for the role selector. Tiers whose key is present in
	 * $annotate are flagged so staff setting a flat price know quantity pricing also
	 * applies to that role for this product.
	 *
	 * @param array<string,bool> $annotate Tier keys to flag (value ignored).
	 * @return array<string,string>
	 */
	private function tier_options( array $annotate = array() ) {
		$options = array();
		foreach ( $this->config->tiers() as $key => $tier ) {
			$label = ! empty( $tier['label'] ) ? (string) $tier['label'] : $key;
			if ( isset( $annotate[ $key ] ) ) {
				/* translators: appended to a tier name when the product has quantity-break pricing for that tier. */
				$label .= ' — ' . __( 'quantity pricing applies', 'wc-pricebook' );
			}
			$options[ $key ] = $label;
		}
		return $options;
	}

	/**
	 * Tier keys that have bulk (quantity-break) rows configured for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,bool>
	 */
	private function bulk_priced_roles( $product_id ) {
		$meta_key = $this->config->bulk_pricing_meta();
		if ( '' === $meta_key ) {
			return array();
		}
		$all = get_post_meta( $product_id, $meta_key, true );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$keys = array();
		foreach ( $all as $tier_key => $rows ) {
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				$keys[ $tier_key ] = true;
			}
		}
		return $keys;
	}

	/**
	 * The currency symbol (decoded to a plain character) for repeater summaries.
	 *
	 * @return string
	 */
	private function currency_symbol() {
		return function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '';
	}

	/**
	 * Render a collapsible repeater card header (chevron + live summary + remove).
	 * The summary text is filled in by settings.js from the row's fields.
	 *
	 * @param string $remove_label Accessible label for the remove control.
	 * @return void
	 */
	private function card_header( $remove_label ) {
		?>
		<div class="wc-pricebook-repeater__header">
			<button type="button" class="wc-pricebook-repeater__toggle" data-repeater-toggle aria-expanded="false">
				<span class="wc-pricebook-repeater__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				<span class="wc-pricebook-repeater__summary" data-repeater-summary></span>
			</button>
			<a href="#" class="wc-pricebook-repeater__remove dashicons dashicons-trash" data-repeater-remove role="button" aria-label="<?php echo esc_attr( $remove_label ); ?>"></a>
		</div>
		<?php
	}

	/**
	 * Render the panel.
	 *
	 * @return void
	 */
	public function render_panel() {
		global $post;
		$tiers            = $this->config->tiers();
		$this->bulk_roles = $this->bulk_priced_roles( (int) $post->ID );
		?>
		<div id="wc_pricebook_role_prices" class="panel woocommerce_options_panel">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

			<?php
			// Sub-tabs within the (already tabbed) WooCommerce product-data panel. All
			// panels stay in the product form, so switching only shows/hides them (JS);
			// every field still posts together on Update. Slugs are prefixed "pb-" so
			// they never collide with the settings page tabs (shared sessionStorage key).
			$subtabs = array(
				'pb-prices'     => __( 'Prices', 'wc-pricebook' ),
				'pb-visibility' => __( 'Visibility', 'wc-pricebook' ),
				'pb-rules'      => __( 'Rules', 'wc-pricebook' ),
			);
			?>
			<nav class="nav-tab-wrapper wc-pricebook-tabs wc-pricebook-subtabs" data-pricebook-tabs>
				<?php
				$first = true;
				foreach ( $subtabs as $slug => $label ) :
					?>
					<a href="#<?php echo esc_attr( $slug ); ?>" class="nav-tab<?php echo $first ? ' nav-tab-active' : ''; ?>" data-pricebook-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php
					$first = false;
				endforeach;
				?>
			</nav>

			<div class="wc-pricebook-tab-panel is-active" data-pricebook-panel="pb-prices">
				<div class="options_group">
					<p class="form-field wc-pricebook-group-title"><strong><?php esc_html_e( 'Manual role prices', 'wc-pricebook' ); ?></strong></p>
					<?php if ( empty( $tiers ) ) : ?>
						<p class="form-field"><?php esc_html_e( 'No pricing tiers configured.', 'wc-pricebook' ); ?></p>
					<?php else : ?>
						<div class="wc-pricebook-roles" data-repeater data-repeater-kind="role-price" data-currency="<?php echo esc_attr( $this->currency_symbol() ); ?>">
							<div data-repeater-list>
								<?php
								$index = 0;
								foreach ( $tiers as $key => $tier ) {
									$value = (string) get_post_meta( $post->ID, $tier['price_meta'], true );
									$sale  = (string) get_post_meta( $post->ID, $tier['sale_meta'], true );
									if ( '' === $value && '' === $sale ) {
										continue;
									}
									$this->render_row( (string) $index, $key, $value, $sale );
									$index++;
								}
								?>
							</div>
							<template data-repeater-template>
								<?php $this->render_row( '__INDEX__', '', '', '' ); ?>
							</template>
							<p class="form-field">
								<button type="button" class="button" data-repeater-add><?php esc_html_e( 'Add role price', 'wc-pricebook' ); ?></button>
							</p>
						</div>
					<?php endif; ?>
				</div>
				<?php $this->render_bulk_pricing_group( (int) $post->ID ); ?>
				<?php $this->render_user_pricing_group( (int) $post->ID ); ?>
			</div>

			<div class="wc-pricebook-tab-panel" data-pricebook-panel="pb-visibility">
				<?php $this->render_force_roles_group( (int) $post->ID ); ?>
			</div>

			<div class="wc-pricebook-tab-panel" data-pricebook-panel="pb-rules">
				<?php $this->render_rule_flags_group( (int) $post->ID ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The rule keys exposed as per-product checkboxes (no translation — used on save).
	 *
	 * @return array<int,string>
	 */
	private function rule_flag_keys() {
		return array( 'skip_matrix', 'no_tier_discount' );
	}

	/**
	 * The per-product rule flags exposed as checkboxes (rule key => [label, help]).
	 *
	 * @return array<string,array<int,string>>
	 */
	private function rule_flags() {
		return array(
			'skip_matrix'      => array(
				__( 'Skip pricing matrix', 'wc-pricebook' ),
				__( 'Ignore tier pricing for this product — every customer sees MSRP.', 'wc-pricebook' ),
			),
			'no_tier_discount' => array(
				__( 'No tier discount', 'wc-pricebook' ),
				__( 'Discount tiers (dealer 4/8/12/15…) collapse to the plain dealer base price.', 'wc-pricebook' ),
			),
		);
	}

	/**
	 * Render the per-product rule flag checkboxes.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function render_rule_flags_group( $product_id ) {
		?>
		<div class="options_group">
			<p class="form-field wc-pricebook-group-title"><strong><?php esc_html_e( 'Pricing rules', 'wc-pricebook' ); ?></strong></p>
			<div class="wc-pricebook-toggles">
				<?php foreach ( $this->rule_flags() as $rule => $meta ) : ?>
					<label class="wc-pricebook-toggle">
						<input type="checkbox" name="<?php echo esc_attr( 'wc_pricebook_rule[' . $rule . ']' ); ?>" value="1" <?php checked( '1', (string) get_post_meta( $product_id, \WCPricebook\Rules::flag_meta_key( $rule ), true ) ); ?>>
						<span class="wc-pricebook-toggle__text">
							<span class="wc-pricebook-toggle__title"><?php echo esc_html( $meta[0] ); ?></span>
							<span class="wc-pricebook-toggle__desc"><?php echo esc_html( $meta[1] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Role options for the force-override selectors: the synthetic "MSRP Customer"
	 * plus every registered WordPress role.
	 *
	 * @return array<string,string>
	 */
	private function role_options() {
		$options = array( \WCPricebook\Context::MSRP_CUSTOMER => __( 'MSRP Customer (no pricing tier)', 'wc-pricebook' ) );
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->get_names() as $slug => $label ) {
				$options[ $slug ] = $label;
			}
		}
		return $options;
	}

	/**
	 * Render a role multi-select bound to a product-meta list of role slugs.
	 *
	 * @param string            $name     Field name (posts under "<name>[]").
	 * @param array<int,string> $selected Selected role slugs.
	 * @return void
	 */
	private function render_role_select( $name, array $selected ) {
		?>
		<select multiple class="wc-enhanced-select" name="<?php echo esc_attr( $name . '[]' ); ?>" style="width:60%;" data-placeholder="<?php esc_attr_e( 'Select roles&hellip;', 'wc-pricebook' ); ?>">
			<?php foreach ( $this->role_options() as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php echo in_array( (string) $slug, $selected, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the per-product force-override section: roles that always see this product
	 * in the catalog, and roles that always see its price — overriding Hide-visibility /
	 * Hide-pricing / Price-gating for those roles.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function render_force_roles_group( $product_id ) {
		$fv_roles = get_post_meta( $product_id, \WCPricebook\Context::FORCE_VISIBLE_ROLES_META, true );
		$fv_roles = is_array( $fv_roles ) ? array_map( 'strval', $fv_roles ) : array();
		$fp_roles = get_post_meta( $product_id, \WCPricebook\Context::FORCE_PRICE_ROLES_META, true );
		$fp_roles = is_array( $fp_roles ) ? array_map( 'strval', $fp_roles ) : array();
		$fv_users = get_post_meta( $product_id, \WCPricebook\Context::FORCE_VISIBLE_USERS_META, true );
		$fv_users = is_array( $fv_users ) ? array_map( 'intval', $fv_users ) : array();
		$fp_users = get_post_meta( $product_id, \WCPricebook\Context::FORCE_PRICE_USERS_META, true );
		$fp_users = is_array( $fp_users ) ? array_map( 'intval', $fp_users ) : array();
		?>
		<div class="options_group">
			<p class="form-field wc-pricebook-group-title"><strong><?php esc_html_e( 'Force visibility overrides', 'wc-pricebook' ); ?></strong></p>
			<p class="form-field">
				<label><?php esc_html_e( 'Force product visible to roles', 'wc-pricebook' ); ?></label>
				<?php $this->render_role_select( 'wc_pricebook_force_visible_roles', $fv_roles ); ?>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( '…or to specific users', 'wc-pricebook' ); ?></label>
				<?php $this->render_user_select( 'wc_pricebook_force_visible_users', $fv_users ); ?>
			</p>
			<p class="form-field" style="margin-top:-8px;">
				<span class="description"><?php esc_html_e( 'These roles/users always see this product in the catalog, even if a “Hide Product” visibility role would hide it.', 'wc-pricebook' ); ?></span>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Force price visible to roles', 'wc-pricebook' ); ?></label>
				<?php $this->render_role_select( 'wc_pricebook_force_price_roles', $fp_roles ); ?>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( '…or to specific users', 'wc-pricebook' ); ?></label>
				<?php $this->render_user_select( 'wc_pricebook_force_price_users', $fp_users ); ?>
			</p>
			<p class="form-field" style="margin-top:-8px;">
				<span class="description"><?php esc_html_e( 'These roles/users always see this product’s price, even if a “Hide Pricing” visibility role or Price gating would hide it.', 'wc-pricebook' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a WooCommerce customer-search multi-select bound to a list of user IDs.
	 *
	 * @param string         $name Field name (posts under "<name>[]").
	 * @param array<int,int> $ids  Selected user IDs.
	 * @return void
	 */
	private function render_user_select( $name, array $ids ) {
		?>
		<select multiple class="wc-customer-search" name="<?php echo esc_attr( $name . '[]' ); ?>" style="width:60%;" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'wc-pricebook' ); ?>" data-allow_clear="true">
			<?php
			foreach ( $ids as $uid ) {
				$user = get_userdata( $uid );
				if ( ! $user ) {
					continue;
				}
				printf(
					'<option value="%d" selected="selected">%s</option>',
					(int) $uid,
					esc_html( sprintf( '%1$s (#%2$d &ndash; %3$s)', $user->display_name, (int) $uid, $user->user_email ) )
				);
			}
			?>
		</select>
		<?php
	}

	/**
	 * Render the bulk (quantity-break) pricing section: flat { role, min qty, price }
	 * rows, grouped by role on save. A row prices that role from its quantity upward
	 * until the next, higher row for the same role. Hidden when the feature is
	 * disabled (empty meta key) or no tiers are configured.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function render_bulk_pricing_group( $product_id ) {
		$meta_key = $this->config->bulk_pricing_meta();
		$tiers    = $this->config->tiers();
		if ( '' === $meta_key || empty( $tiers ) ) {
			return;
		}

		$stored = get_post_meta( $product_id, $meta_key, true );
		$stored = is_array( $stored ) ? $stored : array();

		// Flatten the { role => rows } map, then group breaks that share the same
		// quantity range and price so one row can carry every role they apply to
		// (a break is stored once per role; multi-role rows fan out on save).
		$groups = array();
		foreach ( $stored as $role_key => $role_rows ) {
			if ( ! is_array( $role_rows ) ) {
				continue;
			}
			foreach ( $role_rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['min_qty'], $row['price'] ) ) {
					continue;
				}
				$max   = isset( $row['max_qty'] ) && (int) $row['max_qty'] > 0 ? (string) (int) $row['max_qty'] : '';
				$min   = (string) (int) $row['min_qty'];
				$price = (string) $row['price'];
				$sig   = $min . '|' . $max . '|' . $price;
				if ( ! isset( $groups[ $sig ] ) ) {
					$groups[ $sig ] = array(
						'roles'   => array(),
						'min_qty' => $min,
						'max_qty' => $max,
						'price'   => $price,
					);
				}
				$groups[ $sig ]['roles'][] = (string) $role_key;
			}
		}
		?>
		<div class="options_group">
			<p class="form-field wc-pricebook-group-title"><strong><?php esc_html_e( 'Bulk pricing (quantity breaks)', 'wc-pricebook' ); ?></strong></p>
			<p class="form-field"><?php esc_html_e( 'Per-role price from a quantity upward. Applied in the cart and shown on the product page.', 'wc-pricebook' ); ?></p>
			<div class="wc-pricebook-bulk-prices" data-repeater data-repeater-kind="bulk" data-currency="<?php echo esc_attr( $this->currency_symbol() ); ?>">
				<div data-repeater-list>
					<?php
					$index = 0;
					foreach ( $groups as $group ) {
						$this->render_bulk_row( (string) $index, $group['roles'], $group['min_qty'], $group['max_qty'], $group['price'] );
						$index++;
					}
					?>
				</div>
				<template data-repeater-template>
					<?php $this->render_bulk_row( '__INDEX__', array(), '', '', '' ); ?>
				</template>
				<p class="form-field">
					<button type="button" class="button" data-repeater-add><?php esc_html_e( 'Add quantity break', 'wc-pricebook' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single bulk-break row (role, quantity range, price).
	 *
	 * @param string $index   Row index (or "__INDEX__").
	 * @param string $role    Selected tier key.
	 * @param string $min_qty Minimum quantity.
	 * @param string $max_qty Maximum quantity ('' for unbounded / "and up").
	 * @param string $price   Price value.
	 * @return void
	 */
	/**
	 * Options for a bulk-break row's target: configured pricing tiers (with their
	 * labels) first, then the synthetic "MSRP Customer" and every registered WP role.
	 * Any stored value not otherwise present is appended so an existing row never
	 * silently changes on save.
	 *
	 * @param string|array<int,string> $selected Currently stored value(s) for the row.
	 * @return array<string,string> value => label.
	 */
	private function bulk_target_options( $selected = '' ) {
		$options = $this->tier_options();
		foreach ( $this->role_options() as $key => $label ) {
			if ( ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $label;
			}
		}
		foreach ( (array) $selected as $sel ) {
			$sel = (string) $sel;
			if ( '' !== $sel && ! isset( $options[ $sel ] ) ) {
				$options[ $sel ] = $sel;
			}
		}
		return $options;
	}

	/**
	 * Whether a bulk-break target key is valid: a configured pricing tier, the synthetic
	 * "MSRP Customer", or a registered WP role.
	 *
	 * @param string                             $key   Sanitized target key.
	 * @param array<string,array<string,mixed>>  $tiers Configured tiers.
	 * @return bool
	 */
	private function is_valid_bulk_target( $key, array $tiers ) {
		if ( isset( $tiers[ $key ] ) || \WCPricebook\Context::MSRP_CUSTOMER === $key ) {
			return true;
		}
		return function_exists( 'wp_roles' ) && wp_roles()->is_role( $key );
	}

	private function render_bulk_row( $index, array $roles, $min_qty, $max_qty, $price ) {
		$base  = 'pricebook_bulk_price[' . $index . ']';
		$roles = array_map( 'strval', $roles );
		?>
		<div class="wc-pricebook-repeater__item" data-repeater-item>
			<?php $this->card_header( __( 'Remove quantity break', 'wc-pricebook' ) ); ?>
			<div class="wc-pricebook-repeater__body">
				<div class="wc-pricebook-repeater__grid">
					<div class="wc-pricebook-field wc-pricebook-field--full">
						<label><?php esc_html_e( 'Roles', 'wc-pricebook' ); ?></label>
						<select multiple class="wc-enhanced-select" name="<?php echo esc_attr( $base . '[role][]' ); ?>" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Select roles&hellip;', 'wc-pricebook' ); ?>">
							<?php foreach ( $this->bulk_target_options( $roles ) as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( (string) $key, $roles, true ) ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Min quantity', 'wc-pricebook' ); ?></label>
						<input type="number" step="1" min="1" name="<?php echo esc_attr( $base . '[min_qty]' ); ?>" value="<?php echo esc_attr( $min_qty ); ?>">
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Max quantity', 'wc-pricebook' ); ?></label>
						<input type="number" step="1" min="1" name="<?php echo esc_attr( $base . '[max_qty]' ); ?>" value="<?php echo esc_attr( $max_qty ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'wc-pricebook' ); ?>">
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
						<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the per-user override section: explicit { customer, price } rows stored
	 * under the user-pricing meta key. A matching row beats all tier resolution
	 * ({@see \WCPricebook\PriceEngine::price_for_user}). Hidden when the feature is
	 * disabled (empty meta key).
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function render_user_pricing_group( $product_id ) {
		$meta_key = $this->config->user_pricing_meta();
		if ( '' === $meta_key ) {
			return;
		}

		$rows = get_post_meta( $product_id, $meta_key, true );
		$rows = is_array( $rows ) ? $rows : array();

		// Group stored { user-id, price } entries by price so a single row can carry
		// every customer that shares it (a price is stored once per customer; multi-
		// customer rows fan out on save).
		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['user-id'] ) ) {
				continue;
			}
			$uid = (int) $row['user-id'];
			if ( $uid <= 0 ) {
				continue;
			}
			$price = isset( $row['price'] ) ? (string) $row['price'] : '';
			if ( ! isset( $groups[ $price ] ) ) {
				$groups[ $price ] = array(
					'users' => array(),
					'price' => $price,
				);
			}
			$groups[ $price ]['users'][] = $uid;
		}
		?>
		<div class="options_group">
			<p class="form-field wc-pricebook-group-title"><strong><?php esc_html_e( 'Customer-specific prices', 'wc-pricebook' ); ?></strong></p>
			<p class="form-field"><?php esc_html_e( 'A price set here overrides every tier and role price for the selected customers.', 'wc-pricebook' ); ?></p>
			<div class="wc-pricebook-user-prices" data-repeater data-repeater-kind="user-price" data-currency="<?php echo esc_attr( $this->currency_symbol() ); ?>">
				<div data-repeater-list>
					<?php
					$index = 0;
					foreach ( $groups as $group ) {
						$this->render_user_row( (string) $index, $group['users'], $group['price'] );
						$index++;
					}
					?>
				</div>
				<template data-repeater-template>
					<?php $this->render_user_row( '__INDEX__', array(), '' ); ?>
				</template>
				<p class="form-field">
					<button type="button" class="button" data-repeater-add><?php esc_html_e( 'Add customer price', 'wc-pricebook' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single customer/price row using WooCommerce's customer-search select.
	 *
	 * @param string $index   Row index (or "__INDEX__").
	 * @param int    $user_id Selected customer ID (0 for a blank row).
	 * @param string $price   Price value.
	 * @return void
	 */
	private function render_user_row( $index, array $user_ids, $price ) {
		$base = 'pricebook_user_price[' . $index . ']';
		?>
		<div class="wc-pricebook-repeater__item" data-repeater-item>
			<?php $this->card_header( __( 'Remove customer price', 'wc-pricebook' ) ); ?>
			<div class="wc-pricebook-repeater__body">
				<div class="wc-pricebook-repeater__grid">
					<div class="wc-pricebook-field wc-pricebook-field--full">
						<label><?php esc_html_e( 'Customers', 'wc-pricebook' ); ?></label>
						<select
							multiple
							class="wc-customer-search"
							name="<?php echo esc_attr( $base . '[user-id][]' ); ?>"
							data-placeholder="<?php esc_attr_e( 'Search for customers&hellip;', 'wc-pricebook' ); ?>"
							data-allow_clear="true"
							style="width:100%;">
							<?php
							foreach ( $user_ids as $user_id ) :
								$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;
								if ( ! $user ) :
									continue;
								endif;
								?>
								<option value="<?php echo esc_attr( (string) (int) $user_id ); ?>" selected="selected">
									<?php echo esc_html( sprintf( '%1$s (#%2$d &ndash; %3$s)', $user->display_name, (int) $user_id, $user->user_email ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
						<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single role/price row using native product-panel field markup.
	 *
	 * @param string $index Row index (or "__INDEX__").
	 * @param string $role  Selected tier key.
	 * @param string $price Regular price value.
	 * @param string $sale  Sale price value.
	 * @return void
	 */
	private function render_row( $index, $role, $price, $sale ) {
		$base = 'pricebook_role_price[' . $index . ']';
		?>
		<div class="wc-pricebook-repeater__item" data-repeater-item>
			<?php $this->card_header( __( 'Remove role price', 'wc-pricebook' ) ); ?>
			<div class="wc-pricebook-repeater__body">
				<div class="wc-pricebook-repeater__grid">
					<div class="wc-pricebook-field wc-pricebook-field--full">
						<label><?php esc_html_e( 'Role', 'wc-pricebook' ); ?></label>
						<select name="<?php echo esc_attr( $base . '[role]' ); ?>">
							<option value=""><?php esc_html_e( '— Select —', 'wc-pricebook' ); ?></option>
							<?php foreach ( $this->tier_options( $this->bulk_roles ) as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $role ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
						<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
					</div>
					<div class="wc-pricebook-field">
						<label><?php esc_html_e( 'Sale price', 'wc-pricebook' ); ?></label>
						<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[sale]' ); ?>" value="<?php echo esc_attr( $sale ); ?>">
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the submitted per-role prices, syncing every tier's price meta.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$rows = isset( $_POST['pricebook_role_price'] ) && is_array( $_POST['pricebook_role_price'] )
			? wp_unslash( $_POST['pricebook_role_price'] )
			: array();

		$tiers = $this->config->tiers();

		// Submitted role rows resolved to a meta-key => value map (regular + sale).
		$submitted = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '';
			if ( '' === $role || ! isset( $tiers[ $role ] ) ) {
				continue;
			}
			$price = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			$sale  = isset( $row['sale'] ) ? trim( (string) $row['sale'] ) : '';
			if ( '' !== $price ) {
				$submitted[ $tiers[ $role ]['price_meta'] ] = $this->format( $price );
			}
			if ( '' !== $sale ) {
				$submitted[ $tiers[ $role ]['sale_meta'] ] = $this->format( $sale );
			}
		}

		// Update listed values; clear any tier price/sale meta no longer listed.
		$seen = array();
		foreach ( $tiers as $tier ) {
			foreach ( array( $tier['price_meta'], $tier['sale_meta'] ) as $meta_key ) {
				if ( isset( $seen[ $meta_key ] ) ) {
					continue;
				}
				$seen[ $meta_key ] = true;

				if ( isset( $submitted[ $meta_key ] ) ) {
					update_post_meta( $post_id, $meta_key, $submitted[ $meta_key ] );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
			}
		}

		$this->save_user_pricing( $post_id );
		$this->save_bulk_pricing( $post_id );

		// Per-product force-override role lists.
		foreach ( array(
			'wc_pricebook_force_visible_roles' => \WCPricebook\Context::FORCE_VISIBLE_ROLES_META,
			'wc_pricebook_force_price_roles'   => \WCPricebook\Context::FORCE_PRICE_ROLES_META,
		) as $field => $meta_key ) {
			$roles = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST[ $field ] ) ) ) ) )
				: array();
			update_post_meta( $post_id, $meta_key, $roles );
		}

		// Per-product force-override user lists.
		foreach ( array(
			'wc_pricebook_force_visible_users' => \WCPricebook\Context::FORCE_VISIBLE_USERS_META,
			'wc_pricebook_force_price_users'   => \WCPricebook\Context::FORCE_PRICE_USERS_META,
		) as $field => $meta_key ) {
			$ids = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] )
				? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST[ $field ] ) ) ) ) )
				: array();
			update_post_meta( $post_id, $meta_key, $ids );
		}

		// Per-product rule flag checkboxes.
		$rule_flags = isset( $_POST['wc_pricebook_rule'] ) && is_array( $_POST['wc_pricebook_rule'] ) ? $_POST['wc_pricebook_rule'] : array();
		foreach ( $this->rule_flag_keys() as $rule ) {
			if ( ! empty( $rule_flags[ $rule ] ) ) {
				update_post_meta( $post_id, \WCPricebook\Rules::flag_meta_key( $rule ), '1' );
			} else {
				delete_post_meta( $post_id, \WCPricebook\Rules::flag_meta_key( $rule ) );
			}
		}
	}

	/**
	 * Persist the bulk-break rows to the bulk-pricing meta key as a { tier => list of
	 * { min_qty, price } } map (ascending by quantity). Rows missing a known role, a
	 * positive quantity, or a price are dropped; a duplicate quantity for a role keeps
	 * the last; an empty result clears the meta. No-op when the feature is disabled.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private function save_bulk_pricing( $post_id ) {
		$meta_key = $this->config->bulk_pricing_meta();
		if ( '' === $meta_key ) {
			return;
		}

		$rows = isset( $_POST['pricebook_bulk_price'] ) && is_array( $_POST['pricebook_bulk_price'] )
			? wp_unslash( $_POST['pricebook_bulk_price'] )
			: array();

		$tiers = $this->config->tiers();

		// Group by role, keyed within a role by quantity so a repeated quantity for
		// the same role collapses to the last row entered. A row may target several
		// roles at once (role[]); it fans out to one stored break per role.
		$by_role = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$roles = isset( $row['role'] ) ? (array) $row['role'] : array();
			$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
			$qty   = isset( $row['min_qty'] ) ? absint( $row['min_qty'] ) : 0;
			$max   = isset( $row['max_qty'] ) ? absint( $row['max_qty'] ) : 0;
			$price = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			// Drop incomplete rows and inverted ranges (a set max below the min).
			if ( empty( $roles ) || $qty < 1 || '' === $price ) {
				continue;
			}
			if ( $max > 0 && $max < $qty ) {
				continue;
			}
			$formatted = $this->format( $price );
			foreach ( $roles as $role ) {
				if ( ! $this->is_valid_bulk_target( $role, $tiers ) ) {
					continue;
				}
				$by_role[ $role ][ $qty ] = array(
					'min_qty' => $qty,
					'max_qty' => $max,
					'price'   => $formatted,
				);
			}
		}

		if ( empty( $by_role ) ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		$out = array();
		foreach ( $by_role as $role => $qty_rows ) {
			ksort( $qty_rows );
			$out[ $role ] = array_values( $qty_rows );
		}
		update_post_meta( $post_id, $meta_key, $out );
	}

	/**
	 * Persist the per-user override rows to the user-pricing meta key as a list of
	 * { user-id, price } entries (the shape {@see \WCPricebook\PriceEngine} reads).
	 * Rows missing a customer or a price are dropped; a later row for the same
	 * customer wins; an empty result clears the meta. No-op when the feature is
	 * disabled (empty meta key).
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	private function save_user_pricing( $post_id ) {
		$meta_key = $this->config->user_pricing_meta();
		if ( '' === $meta_key ) {
			return;
		}

		$rows = isset( $_POST['pricebook_user_price'] ) && is_array( $_POST['pricebook_user_price'] )
			? wp_unslash( $_POST['pricebook_user_price'] )
			: array();

		// Keyed by user id so a duplicate customer collapses to the last row entered.
		// A row may target several customers at once (user-id[]); it fans out to one
		// stored entry per customer, all sharing the row's price.
		$by_user = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$user_ids = isset( $row['user-id'] ) ? (array) $row['user-id'] : array();
			$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
			$price    = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			if ( empty( $user_ids ) || '' === $price ) {
				continue;
			}
			$formatted = $this->format( $price );
			foreach ( $user_ids as $user_id ) {
				$by_user[ $user_id ] = array(
					'user-id' => $user_id,
					'price'   => $formatted,
				);
			}
		}

		if ( empty( $by_user ) ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}
		update_post_meta( $post_id, $meta_key, array_values( $by_user ) );
	}

	/**
	 * Format a submitted price value.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function format( $raw ) {
		return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $raw ) : (string) (float) $raw;
	}
}
