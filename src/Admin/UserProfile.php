<?php
/**
 * User-profile pricing controls (wp-admin → Users → Edit).
 *
 * Lets a manager assign a user a pricing role and a visibility role. Both are
 * stored as user meta and consumed by {@see \WCPricebook\Context}.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Admin;

use WCPricebook\Config;
use WCPricebook\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves per-user pricing/visibility settings on the profile screen.
 */
class UserProfile {

	const NONCE_ACTION = 'wc_pricebook_user_profile';
	const NONCE_FIELD  = 'wc_pricebook_user_profile_nonce';

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
	 * Constructor.
	 *
	 * @param Config  $config  Config.
	 * @param Context $context Context.
	 */
	public function __construct( Config $config, Context $context ) {
		$this->config  = $config;
		$this->context = $context;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the WooCommerce enhanced-select (product/category search + chips)
	 * and the shared repeater script on the user profile/edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) || ! $this->can_manage() ) {
			return;
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'wc-pricebook-settings', WC_PRICEBOOK_URL . 'src/Admin/assets/settings.css', array(), WC_PRICEBOOK_VERSION );
		wp_enqueue_script( 'wc-pricebook-settings', WC_PRICEBOOK_URL . 'src/Admin/assets/settings.js', array( 'wc-enhanced-select' ), WC_PRICEBOOK_VERSION, true );
	}

	/**
	 * Whether the current user may manage these settings.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( $this->config->manager()['capability'] ?: 'manage_woocommerce' );
	}

	/**
	 * Tier options (key => label) for the role selectors.
	 *
	 * @return array<string,string>
	 */
	private function tier_options() {
		$options = array();
		foreach ( $this->config->tiers() as $key => $tier ) {
			$options[ $key ] = ! empty( $tier['label'] ) ? (string) $tier['label'] : $key;
		}
		return $options;
	}

	/**
	 * Render the profile section.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render( $user ) {
		if ( ! $this->can_manage() ) {
			return;
		}

		$tiers = $this->tier_options();

		$keys          = $this->config->user_meta_keys();
		$my_products   = $this->parse_id_list( get_user_meta( $user->ID, $keys['include_products'], true ) );
		$vis           = $this->context->visibility_categories( $user->ID );
		$include_cats  = $vis['include'];
		$exclude_cats  = $vis['exclude'];
		$cat_role_maps = $this->normalize_category_roles( $this->context->category_roles( $user->ID ) );
		$cat_terms     = $this->product_categories();
		$role_options  = array( 'msrp' => __( 'MSRP', 'wc-pricebook' ) ) + $tiers;
		?>
		<h2><?php esc_html_e( 'Pricebook', 'wc-pricebook' ); ?></h2>
		<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Pricing tier', 'wc-pricebook' ); ?></th>
				<td>
					<?php $held = $this->context->user_pricing_roles( $user->ID ); ?>
					<p><strong><?php echo $held ? esc_html( implode( ', ', $held ) ) : esc_html__( 'None', 'wc-pricebook' ); ?></strong></p>
					<p class="description"><?php esc_html_e( 'Membership is by WP role/capability (the tier’s role slug). Assign the user a matching role under their user Role settings — the plugin does not manage roles.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wc_pricebook_my_products"><?php esc_html_e( 'My Products', 'wc-pricebook' ); ?></label></th>
				<td>
					<select multiple class="wc-product-search" id="wc_pricebook_my_products" name="wc_pricebook_my_products[]" style="width:25em;"
						data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'wc-pricebook' ); ?>"
						data-action="woocommerce_json_search_products" data-minimum_input_length="2">
						<?php
						foreach ( $my_products as $pid ) {
							$p = wc_get_product( $pid );
							if ( ! $p ) {
								continue;
							}
							printf(
								'<option value="%d" selected="selected">%s</option>',
								(int) $pid,
								esc_html( sprintf( '#%d &ndash; %s', (int) $pid, $p->get_name() ) )
							);
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Products scoped to this user (the products shortcode lists these).', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wc_pricebook_include_categories"><?php esc_html_e( 'Include product categories', 'wc-pricebook' ); ?></label></th>
				<td>
					<?php $this->category_multiselect( 'wc_pricebook_include_categories', $include_cats, $cat_terms ); ?>
					<p class="description"><?php esc_html_e( 'Categories to show this user. When set, the catalog is limited to these categories.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wc_pricebook_exclude_categories"><?php esc_html_e( 'Exclude product categories', 'wc-pricebook' ); ?></label></th>
				<td>
					<?php $this->category_multiselect( 'wc_pricebook_exclude_categories', $exclude_cats, $cat_terms ); ?>
					<p class="description"><?php esc_html_e( 'Categories to hide from this user.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Category pricing roles', 'wc-pricebook' ); ?></label></th>
				<td>
					<div class="wc-pricebook-catroles" data-repeater>
						<div data-repeater-list>
							<?php
							$row_index = 0;
							foreach ( $cat_role_maps as $map ) {
								$this->render_catrole_row( (string) $row_index, $map['product-category'], $map['pricing-role'], $cat_terms, $role_options );
								$row_index++;
							}
							?>
						</div>
						<template data-repeater-template>
							<?php $this->render_catrole_row( '__INDEX__', array(), 'msrp', $cat_terms, $role_options ); ?>
						</template>
						<p class="form-field" style="margin:0;">
							<button type="button" class="button" data-repeater-add><?php esc_html_e( 'Add item', 'wc-pricebook' ); ?></button>
						</p>
					</div>
					<p class="description"><?php esc_html_e( 'Products in a listed category are priced as the selected role for this user (overrides their tier). Note: the first category in each item is the one matched.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render a select element.
	 *
	 * @param string               $name    Field name.
	 * @param array<string,string> $options Option map (value => label).
	 * @param string               $current Selected value.
	 * @return void
	 */
	private function select( $name, array $options, $current ) {
		$id = preg_replace( '/[^a-z0-9_]/', '_', $name );
		?>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $value, $current ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render a multi-select element (posts an array under "<name>[]").
	 *
	 * @param string               $name    Field name.
	 * @param array<string,string> $options Option map (value => label).
	 * @param array<int,string>    $current Selected values.
	 * @return void
	 */
	private function multiselect( $name, array $options, array $current ) {
		$id   = preg_replace( '/[^a-z0-9_]/', '_', $name );
		$size = max( 3, min( 8, count( $options ) ) );
		?>
		<select name="<?php echo esc_attr( $name ); ?>[]" id="<?php echo esc_attr( $id ); ?>" multiple="multiple" size="<?php echo esc_attr( (string) $size ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php echo in_array( (string) $value, $current, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Save the profile section.
	 *
	 * @param int $user_id User ID being saved.
	 * @return void
	 */
	public function save( $user_id ) {
		if ( ! $this->can_manage() || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		$keys = $this->config->user_meta_keys();

		// My Products + include/exclude category lists (stored as int arrays).
		$my_products = isset( $_POST['wc_pricebook_my_products'] ) ? $this->parse_id_list( wp_unslash( $_POST['wc_pricebook_my_products'] ) ) : array();
		update_user_meta( $user_id, $keys['include_products'], $my_products );

		$include_cats = isset( $_POST['wc_pricebook_include_categories'] ) ? $this->parse_id_list( wp_unslash( $_POST['wc_pricebook_include_categories'] ) ) : array();
		update_user_meta( $user_id, $keys['include_categories'], $include_cats );

		$exclude_cats = isset( $_POST['wc_pricebook_exclude_categories'] ) ? $this->parse_id_list( wp_unslash( $_POST['wc_pricebook_exclude_categories'] ) ) : array();
		update_user_meta( $user_id, $keys['exclude_categories'], $exclude_cats );

		// Category → role mappings (validated against MSRP + the configured tier keys).
		$valid_roles = array_merge( array( 'msrp' ), array_keys( $this->config->tiers() ) );
		$submitted   = isset( $_POST['wc_pricebook_category_roles'] ) && is_array( $_POST['wc_pricebook_category_roles'] )
			? wp_unslash( $_POST['wc_pricebook_category_roles'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.
			: array();
		$cat_roles   = $this->parse_category_roles( $submitted, $valid_roles );
		update_user_meta( $user_id, $keys['category_roles'], $cat_roles );
	}

	/**
	 * Parse a submitted ID list (array or comma/space-separated string) into a
	 * de-duplicated int array, dropping empty/zero values.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int,int>
	 */
	private function parse_id_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $value ) ) ) );
	}

	/**
	 * All product categories (id-bearing terms), fetched once per request.
	 *
	 * @return array<int,\WP_Term>
	 */
	private function product_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Render a WooCommerce enhanced-select multi-select of product categories.
	 *
	 * @param string                $name     Field base name (posts under "<name>[]").
	 * @param array<int,int>        $selected Selected term IDs.
	 * @param array<int,\WP_Term>   $terms    All category terms.
	 * @return void
	 */
	private function category_multiselect( $name, array $selected, array $terms ) {
		$selected = array_map( 'intval', $selected );
		?>
		<select multiple class="wc-enhanced-select" name="<?php echo esc_attr( $name ); ?>[]" style="width:25em;"
			data-placeholder="<?php esc_attr_e( 'Select categories&hellip;', 'wc-pricebook' ); ?>">
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php echo in_array( (int) $term->term_id, $selected, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $term->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render one Category Pricing Roles repeater row: a category multi-select plus
	 * a pricing-role dropdown.
	 *
	 * @param string                $index        Row index (or "__INDEX__" template placeholder).
	 * @param array<int,int>        $selected     Selected category IDs.
	 * @param string                $role         Selected pricing role.
	 * @param array<int,\WP_Term>   $terms        All category terms.
	 * @param array<string,string>  $role_options Role option map (key => label).
	 * @return void
	 */
	private function render_catrole_row( $index, array $selected, $role, array $terms, array $role_options ) {
		$base = 'wc_pricebook_category_roles[' . $index . ']';
		?>
		<div class="wc-pricebook-catrole-row" data-repeater-item>
			<p class="form-field">
				<label><?php esc_html_e( 'Product Category', 'wc-pricebook' ); ?></label>
				<?php $this->category_multiselect( $base . '[categories]', $selected, $terms ); ?>
			</p>
			<p class="form-field">
				<label><?php esc_html_e( 'Pricing Role', 'wc-pricebook' ); ?></label>
				<?php $this->select( $base . '[role]', $role_options, (string) $role ); ?>
			</p>
			<p class="form-field">
				<a href="#" class="wc-pricebook-role-row__remove" data-repeater-remove role="button"><?php esc_html_e( 'Remove', 'wc-pricebook' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Normalize stored category→role mappings to a clean list of
	 * { product-category: int[], pricing-role: string } rows for rendering.
	 *
	 * @param mixed $mappings Stored mappings (JetEngine "item-N" assoc or a list).
	 * @return array<int,array{product-category:array<int,int>,pricing-role:string}>
	 */
	private function normalize_category_roles( $mappings ) {
		$out = array();
		foreach ( (array) $mappings as $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['product-category'] ) || ! isset( $mapping['pricing-role'] ) ) {
				continue;
			}
			$out[] = array(
				'product-category' => array_values( array_filter( array_map( 'intval', (array) $mapping['product-category'] ) ) ),
				'pricing-role'     => (string) $mapping['pricing-role'],
			);
		}
		return $out;
	}

	/**
	 * Parse the submitted Category Pricing Roles repeater into the stored mapping
	 * shape. Each item is { categories: int[], role: string }; rows with no
	 * categories or an unknown role are dropped.
	 *
	 * @param array<int|string,mixed> $submitted   Submitted repeater rows.
	 * @param array<int,string>       $valid_roles Allowed pricing-role keys (msrp + tier keys).
	 * @return array<int,array{product-category:array<int,int>,pricing-role:string}>
	 */
	private function parse_category_roles( array $submitted, array $valid_roles ) {
		$out = array();
		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cats = isset( $row['categories'] ) ? $this->parse_id_list( $row['categories'] ) : array();
			$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '';
			if ( empty( $cats ) || ! in_array( $role, $valid_roles, true ) ) {
				continue;
			}
			$out[] = array(
				'product-category' => $cats,
				'pricing-role'     => $role,
			);
		}
		return $out;
	}
}
