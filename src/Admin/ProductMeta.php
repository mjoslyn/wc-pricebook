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
			<div class="options_group">
				<?php if ( empty( $tiers ) ) : ?>
					<p class="form-field"><?php esc_html_e( 'No pricing tiers configured.', 'wc-pricebook' ); ?></p>
				<?php else : ?>
					<div class="wc-pricebook-roles" data-repeater>
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
			<?php $this->render_force_roles_group( (int) $post->ID ); ?>
			<?php $this->render_rule_flags_group( (int) $post->ID ); ?>
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
			<p class="form-field"><strong><?php esc_html_e( 'Pricing rules', 'wc-pricebook' ); ?></strong></p>
			<?php foreach ( $this->rule_flags() as $rule => $meta ) : ?>
				<p class="form-field">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( 'wc_pricebook_rule[' . $rule . ']' ); ?>" value="1" <?php checked( '1', (string) get_post_meta( $product_id, \WCPricebook\Rules::flag_meta_key( $rule ), true ) ); ?>>
						<?php echo esc_html( $meta[0] ); ?>
					</label>
					<span class="description" style="display:block;"><?php echo esc_html( $meta[1] ); ?></span>
				</p>
			<?php endforeach; ?>
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
			<p class="form-field"><strong><?php esc_html_e( 'Force visibility overrides', 'wc-pricebook' ); ?></strong></p>
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

		// Flatten the { tier => rows } map to { role, min_qty, price } rows.
		$rows = array();
		foreach ( $stored as $tier_key => $tier_rows ) {
			if ( ! isset( $tiers[ $tier_key ] ) || ! is_array( $tier_rows ) ) {
				continue;
			}
			foreach ( $tier_rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['min_qty'], $row['price'] ) ) {
					continue;
				}
				$max      = isset( $row['max_qty'] ) ? (int) $row['max_qty'] : 0;
				$rows[]   = array(
					'role'    => $tier_key,
					'min_qty' => (string) (int) $row['min_qty'],
					'max_qty' => $max > 0 ? (string) $max : '',
					'price'   => (string) $row['price'],
				);
			}
		}
		?>
		<div class="options_group">
			<p class="form-field"><strong><?php esc_html_e( 'Bulk pricing (quantity breaks)', 'wc-pricebook' ); ?></strong></p>
			<p class="form-field"><?php esc_html_e( 'Per-role price from a quantity upward. Applied in the cart and shown on the product page.', 'wc-pricebook' ); ?></p>
			<div class="wc-pricebook-bulk-prices" data-repeater>
				<div data-repeater-list>
					<?php
					$index = 0;
					foreach ( $rows as $row ) {
						$this->render_bulk_row( (string) $index, $row['role'], $row['min_qty'], $row['max_qty'], $row['price'] );
						$index++;
					}
					?>
				</div>
				<template data-repeater-template>
					<?php $this->render_bulk_row( '__INDEX__', '', '', '', '' ); ?>
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
	private function render_bulk_row( $index, $role, $min_qty, $max_qty, $price ) {
		$base = 'pricebook_bulk_price[' . $index . ']';
		?>
		<div class="wc-pricebook-role-row" data-repeater-item>
			<p class="form-field">
				<label><?php esc_html_e( 'Role', 'wc-pricebook' ); ?></label>
				<select name="<?php echo esc_attr( $base . '[role]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'wc-pricebook' ); ?></option>
					<?php foreach ( $this->tier_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $role ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Min quantity', 'wc-pricebook' ); ?></label>
				<input type="number" step="1" min="1" name="<?php echo esc_attr( $base . '[min_qty]' ); ?>" value="<?php echo esc_attr( $min_qty ); ?>">
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Max quantity', 'wc-pricebook' ); ?></label>
				<input type="number" step="1" min="1" name="<?php echo esc_attr( $base . '[max_qty]' ); ?>" value="<?php echo esc_attr( $max_qty ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'wc-pricebook' ); ?>">
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
				<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
			</p>
			<p class="form-field">
				<label>&nbsp;</label>
				<a href="#" class="wc-pricebook-role-row__remove" data-repeater-remove role="button"><?php esc_html_e( 'Remove', 'wc-pricebook' ); ?></a>
			</p>
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
		?>
		<div class="options_group">
			<p class="form-field"><strong><?php esc_html_e( 'Customer-specific prices', 'wc-pricebook' ); ?></strong></p>
			<p class="form-field"><?php esc_html_e( 'A price set here for a customer overrides every tier and role price for that customer.', 'wc-pricebook' ); ?></p>
			<div class="wc-pricebook-user-prices" data-repeater>
				<div data-repeater-list>
					<?php
					$index = 0;
					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) || ! isset( $row['user-id'] ) ) {
							continue;
						}
						$this->render_user_row( (string) $index, (int) $row['user-id'], isset( $row['price'] ) ? (string) $row['price'] : '' );
						$index++;
					}
					?>
				</div>
				<template data-repeater-template>
					<?php $this->render_user_row( '__INDEX__', 0, '' ); ?>
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
	private function render_user_row( $index, $user_id, $price ) {
		$base = 'pricebook_user_price[' . $index . ']';
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		?>
		<div class="wc-pricebook-role-row" data-repeater-item>
			<p class="form-field">
				<label><?php esc_html_e( 'Customer', 'wc-pricebook' ); ?></label>
				<select
					class="wc-customer-search"
					name="<?php echo esc_attr( $base . '[user-id]' ); ?>"
					data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'wc-pricebook' ); ?>"
					data-allow_clear="true"
					style="width:100%;">
					<?php if ( $user ) : ?>
						<option value="<?php echo esc_attr( (string) $user_id ); ?>" selected="selected">
							<?php echo esc_html( sprintf( '%1$s (#%2$d &ndash; %3$s)', $user->display_name, $user_id, $user->user_email ) ); ?>
						</option>
					<?php endif; ?>
				</select>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
				<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
			</p>
			<p class="form-field">
				<label>&nbsp;</label>
				<a href="#" class="wc-pricebook-role-row__remove" data-repeater-remove role="button"><?php esc_html_e( 'Remove', 'wc-pricebook' ); ?></a>
			</p>
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
		<div class="wc-pricebook-role-row" data-repeater-item>
			<p class="form-field">
				<label><?php esc_html_e( 'Role', 'wc-pricebook' ); ?></label>
				<select name="<?php echo esc_attr( $base . '[role]' ); ?>">
					<option value=""><?php esc_html_e( '— Select —', 'wc-pricebook' ); ?></option>
					<?php foreach ( $this->tier_options( $this->bulk_roles ) as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $role ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Price', 'wc-pricebook' ); ?></label>
				<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[price]' ); ?>" value="<?php echo esc_attr( $price ); ?>">
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Sale price', 'wc-pricebook' ); ?></label>
				<input type="number" step="0.0001" name="<?php echo esc_attr( $base . '[sale]' ); ?>" value="<?php echo esc_attr( $sale ); ?>">
			</p>
			<p class="form-field">
				<label>&nbsp;</label>
				<a href="#" class="wc-pricebook-role-row__remove" data-repeater-remove role="button"><?php esc_html_e( 'Remove', 'wc-pricebook' ); ?></a>
			</p>
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
		// the same role collapses to the last row entered.
		$by_role = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$role  = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '';
			$qty   = isset( $row['min_qty'] ) ? absint( $row['min_qty'] ) : 0;
			$max   = isset( $row['max_qty'] ) ? absint( $row['max_qty'] ) : 0;
			$price = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			// Drop incomplete rows and inverted ranges (a set max below the min).
			if ( '' === $role || ! isset( $tiers[ $role ] ) || $qty < 1 || '' === $price ) {
				continue;
			}
			if ( $max > 0 && $max < $qty ) {
				continue;
			}
			$by_role[ $role ][ $qty ] = array(
				'min_qty' => $qty,
				'max_qty' => $max,
				'price'   => $this->format( $price ),
			);
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
		$by_user = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$user_id = isset( $row['user-id'] ) ? absint( $row['user-id'] ) : 0;
			$price   = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			if ( $user_id <= 0 || '' === $price ) {
				continue;
			}
			$by_user[ $user_id ] = array(
				'user-id' => $user_id,
				'price'   => $this->format( $price ),
			);
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
