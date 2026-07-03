<?php
/**
 * Settings page (WooCommerce → Pricebook) using the WordPress Settings API.
 *
 * The page edits the module toggles and the tiers (via a small repeater script).
 * Base price meta, the per-user override meta, the manager identity, shortcodes and
 * user-meta keys are hardcoded Config constants and not shown here. Multiaccount
 * parent resolution is theme-side via the wc_pricebook_pricing_user filter, and
 * rules are bound on the product side, so neither appears on this page. The form
 * still writes the single config option.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Admin;

use WCPricebook\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the plugin settings page.
 */
class Settings {

	const PAGE  = 'wc-pricebook';
	const GROUP = 'wc_pricebook_settings';

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Page hook suffix returned by add_submenu_page (for scoped asset loading).
	 *
	 * @var string
	 */
	private $hook = '';

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Pricebook', 'wc-pricebook' ),
			__( 'Pricebook', 'wc-pricebook' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the repeater script/style on this settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'wc-pricebook-settings',
			WC_PRICEBOOK_URL . 'src/Admin/assets/settings.css',
			array(),
			WC_PRICEBOOK_VERSION
		);
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script(
			'wc-pricebook-settings',
			WC_PRICEBOOK_URL . 'src/Admin/assets/settings.js',
			array( 'wc-enhanced-select' ),
			WC_PRICEBOOK_VERSION,
			true
		);
	}

	/**
	 * Role options for a visibility role's roles selector: the synthetic "MSRP
	 * Customer" pseudo-role plus every registered WordPress role.
	 *
	 * @return array<string,string> slug => label.
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
	 * Register the single config option with a sanitize callback.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			Config::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Config::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings into the stored config shape.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->config->all();
		$input   = is_array( $input ) ? $input : array();

		$out = $current;

		// Modules.
		$out['modules']['switcher']       = ! empty( $input['modules']['switcher'] );
		$out['modules']['flowchart']      = ! empty( $input['modules']['flowchart'] );
		$out['modules']['product_prices'] = ! empty( $input['modules']['product_prices'] );

		// Base price meta, per-user override meta, manager and shortcodes/user-meta
		// keys are hardcoded (Config constants), not editable here. Multiaccount
		// resolution is theme-side via the wc_pricebook_pricing_user filter. Per-product
		// rule binding is on the product side; category-level binding is set below.
		$out['tiers']            = $this->sanitize_tiers( $input['tiers'] ?? array() );
		$out['visibility_roles'] = $this->sanitize_visibility_roles( $input['visibility_roles'] ?? array() );
		$out['export']           = $this->sanitize_export( $input['export'] ?? array() );

		return $out;
	}

	/**
	 * Normalize the pricelist-export settings: recipient email, cron cadence, and an
	 * optional role filter. The cron schedule itself is (re)synced by ExportModule on
	 * the update_option hook after this save.
	 *
	 * @param mixed $input Submitted export settings.
	 * @return array{recipient:string,schedule:string,roles:array<int,string>}
	 */
	private function sanitize_export( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$recipient = isset( $input['recipient'] ) ? sanitize_email( (string) $input['recipient'] ) : '';
		$schedule  = isset( $input['schedule'] ) ? (string) $input['schedule'] : 'off';

		return array(
			'recipient' => $recipient,
			'schedule'  => in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : 'off',
			'roles'     => isset( $input['roles'] ) && is_array( $input['roles'] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_key', $input['roles'] ) ) ) )
				: array(),
		);
	}




	/**
	 * Normalize submitted tier rows into the key-indexed tier map.
	 *
	 * Rows arrive as a numerically indexed list; they are re-keyed by each row's
	 * sanitized `key`. Rows with an empty key are dropped.
	 *
	 * @param mixed $rows Submitted tier rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function sanitize_tiers( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// The key is hidden in the UI; for new tiers it is derived from the label.
			$key = sanitize_key( $row['key'] ?? '' );
			if ( '' === $key ) {
				$key = sanitize_title( $row['label'] ?? '' );
			}
			if ( '' === $key ) {
				continue;
			}

			$out[ $key ] = array(
				'key'                 => $key,
				'label'               => sanitize_text_field( $row['label'] ?? ucfirst( $key ) ),
				'price_meta'          => sanitize_text_field( $row['price_meta'] ?? '' ),
				'sale_meta'           => sanitize_text_field( $row['sale_meta'] ?? '' ),
				'multiplier'          => isset( $row['multiplier'] ) && '' !== $row['multiplier'] ? (float) $row['multiplier'] : 1.0,
				'base_meta'           => sanitize_key( $row['base_meta'] ?? '' ),
				'fallback_to'           => sanitize_text_field( $row['fallback_to'] ?? 'msrp' ),
				'override'              => in_array( $row['override'] ?? '', array( 'always', 'when_priced' ), true ) ? $row['override'] : '',
				'notes'                 => sanitize_textarea_field( $row['notes'] ?? '' ),
				'pricing_categories'    => $this->sanitize_category_set( $row['pricing_categories'] ?? array() ),
			);
		}

		return $out;
	}

	/**
	 * Normalize submitted visibility-role rows into a key-indexed map. The key is
	 * hidden in the UI and derived from the label for new roles.
	 *
	 * @param mixed $rows Submitted role rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function sanitize_visibility_roles( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		$out  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( $row['key'] ?? '' );
			if ( '' === $key ) {
				$key = sanitize_title( $row['label'] ?? '' );
			}
			if ( '' === $key ) {
				continue;
			}

			$roles       = isset( $row['roles'] ) && is_array( $row['roles'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $row['roles'] ) ) ) ) : array();
			$users       = isset( $row['users'] ) && is_array( $row['users'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $row['users'] ) ) ) ) : array();
			$match       = isset( $row['match'] ) && 'all' === $row['match'] ? 'all' : 'any';
			$out[ $key ] = array(
				'key'             => $key,
				'label'           => sanitize_text_field( $row['label'] ?? ucfirst( $key ) ),
				'roles'           => $roles,
				'users'           => $users,
				'match'           => $match,
				'categories'      => $this->sanitize_category_set( $row['categories'] ?? array() ),
				'hide'            => in_array( $row['hide'] ?? '', array( 'product', 'pricing' ), true ) ? $row['hide'] : '',
				'notes'           => sanitize_textarea_field( $row['notes'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Sanitize a category-scope set { mode, categories }.
	 *
	 * @param mixed $set Raw set.
	 * @return array{mode:string,categories:array<int,int>}
	 */
	private function sanitize_category_set( $set ) {
		$set  = is_array( $set ) ? $set : array();
		$mode = isset( $set['mode'] ) ? sanitize_key( $set['mode'] ) : 'all';
		if ( ! in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
			$mode = 'all';
		}
		return array(
			'mode'       => $mode,
			'categories' => $this->parse_ids( $set['categories'] ?? array() ),
		);
	}

	/**
	 * Parse a submitted value into a list of unique positive integer IDs.
	 *
	 * @param mixed $value Raw value (array of IDs).
	 * @return array<int,int>
	 */
	private function parse_ids( $value ) {
		$ids = is_array( $value ) ? $value : array();
		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids, static function ( $id ) {
			return $id > 0;
		} );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		$c          = $this->config->all();
		$name       = Config::OPTION;
		$shortcodes = $this->config->shortcodes();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WC Pricebook', 'wc-pricebook' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<?php $this->render_tiers( $name, is_array( $c['tiers'] ) ? $c['tiers'] : array() ); ?>

				<?php $this->render_visibility_roles( $name, is_array( $c['visibility_roles'] ?? null ) ? $c['visibility_roles'] : array() ); ?>

				<h2><?php esc_html_e( 'Modules', 'wc-pricebook' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Pricing-view switcher', 'wc-pricebook' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[modules][switcher]" value="1" <?php checked( $this->config->module_enabled( 'switcher' ) ); ?>> <?php esc_html_e( 'Enabled', 'wc-pricebook' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Pricing flowchart', 'wc-pricebook' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[modules][flowchart]" value="1" <?php checked( $this->config->module_enabled( 'flowchart' ) ); ?>> <?php esc_html_e( 'Enabled (/price-flowchart)', 'wc-pricebook' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Product tier prices (toolbar)', 'wc-pricebook' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[modules][product_prices]" value="1" <?php checked( $this->config->module_enabled( 'product_prices' ) ); ?>> <?php esc_html_e( 'Show a toolbar dropdown of each tier’s price on the current product', 'wc-pricebook' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Shortcodes', 'wc-pricebook' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Shortcode tags are fixed. Paste these into any page or post.', 'wc-pricebook' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Pricing table', 'wc-pricebook' ); ?></th>
						<td><code>[<?php echo esc_html( $shortcodes['table'] ); ?>]</code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'User products', 'wc-pricebook' ); ?></th>
						<td><code>[<?php echo esc_html( $shortcodes['products'] ); ?>]</code></td>
					</tr>
				</table>

				<?php $this->render_export_settings( $name, is_array( $c['export'] ?? null ) ? $c['export'] : array() ); ?>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_export_send_now( is_array( $c['export'] ?? null ) ? $c['export'] : array() ); ?>
		</div>
		<?php
	}

	/**
	 * Render the pricelist-export settings (inside the main settings form): recipient
	 * email, cron cadence, and an optional role filter. The recipient defaults to the
	 * current admin's email so an unconfigured install still emails a sensible address.
	 *
	 * @param string              $name   Option name prefix.
	 * @param array<string,mixed> $export Stored export settings.
	 * @return void
	 */
	private function render_export_settings( $name, array $export ) {
		$current   = wp_get_current_user();
		$recipient = isset( $export['recipient'] ) && '' !== (string) $export['recipient'] ? (string) $export['recipient'] : (string) $current->user_email;
		$schedule  = in_array( $export['schedule'] ?? 'off', array( 'off', 'daily', 'weekly' ), true ) ? $export['schedule'] : 'off';
		$sel_roles = isset( $export['roles'] ) && is_array( $export['roles'] ) ? array_map( 'strval', $export['roles'] ) : array();
		$schedules = array(
			'off'    => __( 'Off (manual / WP-CLI only)', 'wc-pricebook' ),
			'daily'  => __( 'Daily', 'wc-pricebook' ),
			'weekly' => __( 'Weekly', 'wc-pricebook' ),
		);
		?>
		<h2><?php esc_html_e( 'Pricelist export', 'wc-pricebook' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Export a CSV of every user’s resolved price for every product (display name, roles, product, SKU, resolved price). Runs from WP-CLI (wp wc-pricebook export-pricelist), on a schedule, or with the button below.', 'wc-pricebook' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wc-pricebook-export-recipient"><?php esc_html_e( 'Email recipient', 'wc-pricebook' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="wc-pricebook-export-recipient" name="<?php echo esc_attr( $name . '[export][recipient]' ); ?>" value="<?php echo esc_attr( $recipient ); ?>" placeholder="<?php echo esc_attr( (string) $current->user_email ); ?>">
					<p class="description"><?php esc_html_e( 'Where the scheduled export is emailed. Defaults to your address; leave blank to use the site admin email.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wc-pricebook-export-schedule"><?php esc_html_e( 'Schedule', 'wc-pricebook' ); ?></label></th>
				<td>
					<select id="wc-pricebook-export-schedule" name="<?php echo esc_attr( $name . '[export][schedule]' ); ?>">
						<?php foreach ( $schedules as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'How often to email the export automatically (WP-Cron).', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Limit to roles', 'wc-pricebook' ); ?></label></th>
				<td>
					<select multiple class="wc-enhanced-select" name="<?php echo esc_attr( $name . '[export][roles][]' ); ?>" style="min-width:25em;" data-placeholder="<?php esc_attr_e( 'All users', 'wc-pricebook' ); ?>">
						<?php
						if ( function_exists( 'wp_roles' ) ) :
							foreach ( wp_roles()->get_names() as $slug => $label ) :
								?>
								<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php echo in_array( (string) $slug, $sel_roles, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $label ); ?></option>
								<?php
							endforeach;
						endif;
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Optional. Restrict the export to users in these roles. Leave empty to include every user.', 'wc-pricebook' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the "Send now" button as its own form (posting to admin-post.php, since it
	 * cannot be nested in the settings form). Emails the export immediately.
	 *
	 * @param array<string,mixed> $export Stored export settings.
	 * @return void
	 */
	private function render_export_send_now( array $export ) {
		$current   = wp_get_current_user();
		$recipient = isset( $export['recipient'] ) && '' !== (string) $export['recipient'] ? (string) $export['recipient'] : (string) $current->user_email;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
			<input type="hidden" name="action" value="<?php echo esc_attr( \WCPricebook\Export\ExportModule::ACTION_NOW ); ?>">
			<input type="hidden" name="recipient" value="<?php echo esc_attr( $recipient ); ?>">
			<?php wp_nonce_field( \WCPricebook\Export\ExportModule::ACTION_NOW ); ?>
			<?php submit_button( __( 'Generate and email now', 'wc-pricebook' ), 'secondary', 'wc-pricebook-export-now', false ); ?>
			<p class="description"><?php esc_html_e( 'Builds the CSV now and emails it to the recipient above. Save your changes first if you just edited the recipient.', 'wc-pricebook' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Render the tiers repeater (one row per tier + a blank template row).
	 *
	 * @param string                            $name  Option name prefix.
	 * @param array<string,array<string,mixed>> $tiers Configured tiers.
	 * @return void
	 */
	private function render_tiers( $name, array $tiers ) {
		$categories = $this->product_categories();
		$options    = $this->tier_field_options( $tiers );
		?>
		<h2><?php esc_html_e( 'Pricing Tiers', 'wc-pricebook' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Each tier maps a pricing role to its price meta keys. The tier key is used in URLs, the switcher, and fallback chains.', 'wc-pricebook' ); ?></p>
		<div class="wc-pricebook-repeater" data-repeater>
			<div class="wc-pricebook-repeater__list" data-repeater-list>
				<?php
				$index = 0;
				foreach ( $tiers as $tier ) {
					$this->render_tier_row( $name, (string) $index, is_array( $tier ) ? $tier : array(), $categories, $options );
					$index++;
				}
				?>
			</div>
			<template data-repeater-template>
				<?php $this->render_tier_row( $name, '__INDEX__', array(), $categories, $options ); ?>
			</template>
			<button type="button" class="button wc-pricebook-repeater__add" data-repeater-add><?php esc_html_e( 'Add tier', 'wc-pricebook' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Build the dropdown option maps (value => label) for the base-meta and
	 * fallback-to tier fields, derived from the saved tiers.
	 *
	 * @param array<string,array<string,mixed>> $tiers Saved tiers.
	 * @return array{base_meta:array<string,string>,fallback_to:array<string,string>}
	 */
	private function tier_field_options( array $tiers ) {
		$none        = __( 'None', 'wc-pricebook' );
		$msrp        = __( 'MSRP', 'wc-pricebook' );
		$tier_labels = array();

		foreach ( $tiers as $key => $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$tier_labels[ $key ] = ! empty( $tier['label'] ) ? (string) $tier['label'] : $key;
		}

		// base_meta (the "base pricing role") and fallback_to both reference a role:
		// none, MSRP, or any tier (including the current one).
		$role_options = array(
			''     => $none,
			'msrp' => $msrp,
		) + $tier_labels;

		return array(
			'base_meta'   => $role_options,
			'fallback_to' => $role_options,
		);
	}

	/**
	 * Product categories for the tier scope selectors.
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
		return ( is_array( $terms ) ) ? $terms : array();
	}

	/**
	 * Render a single tier row.
	 *
	 * @param string                 $name       Option name prefix.
	 * @param string                 $index      Row index (or "__INDEX__" placeholder).
	 * @param array<string,mixed>    $tier       Tier values.
	 * @param array<int,\WP_Term>    $categories Product categories for the scope selectors.
	 * @param array<string,array<string,string>> $options Dropdown option maps for base_meta/fallback_to.
	 * @return void
	 */
	private function render_tier_row( $name, $index, array $tier, array $categories, array $options ) {
		$base   = $name . '[tiers][' . $index . ']';
		$fields = array(
			'label'      => array( __( 'Label', 'wc-pricebook' ), __( 'Shown in the switcher and tables.', 'wc-pricebook' ) ),
			'multiplier' => array( __( 'Multiplier', 'wc-pricebook' ), __( 'Applied to base meta when no price is set.', 'wc-pricebook' ) ),
		);
		?>
		<div class="wc-pricebook-repeater__item" data-repeater-item>
			<a href="#" class="wc-pricebook-repeater__remove dashicons dashicons-trash" data-repeater-remove role="button" aria-label="<?php esc_attr_e( 'Remove tier', 'wc-pricebook' ); ?>"></a>
			<div class="wc-pricebook-repeater__grid">
				<?php foreach ( $fields as $field => $meta ) : ?>
					<div class="wc-pricebook-field<?php echo 'label' === $field ? ' wc-pricebook-field--full' : ''; ?>">
						<label><?php echo esc_html( $meta[0] ); ?></label>
						<input
							type="<?php echo 'multiplier' === $field ? 'number' : 'text'; ?>"
							<?php echo 'multiplier' === $field ? 'step="0.0001"' : ''; ?>
							name="<?php echo esc_attr( $base . '[' . $field . ']' ); ?>"
							value="<?php echo esc_attr( (string) ( $tier[ $field ] ?? '' ) ); ?>">
						<p class="description"><?php echo esc_html( $meta[1] ); ?></p>
					</div>
				<?php endforeach; ?>
				<?php
				$this->render_select_field( $base . '[base_meta]', __( 'Base pricing role', 'wc-pricebook' ), __( 'Role whose price is multiplied for the tier price.', 'wc-pricebook' ), $options['base_meta'], (string) ( $tier['base_meta'] ?? '' ) );
				$this->render_select_field( $base . '[fallback_to]', __( 'Fallback to', 'wc-pricebook' ), __( 'MSRP, another tier, or none. Also used when the no-tier-discount rule applies.', 'wc-pricebook' ), $options['fallback_to'], (string) ( $tier['fallback_to'] ?? 'msrp' ) );
				$override_options = array(
					''            => __( 'Competes on price (lowest wins)', 'wc-pricebook' ),
					'when_priced' => __( 'Overrides when this tier has its own price', 'wc-pricebook' ),
					'always'      => __( 'Always overrides (within its category scope)', 'wc-pricebook' ),
				);
				$this->render_select_field( $base . '[override]', __( 'Price override', 'wc-pricebook' ), __( 'How this tier competes: lowest-wins, override only when it has its own price (e.g. operator pricing), or always override other tiers in its category scope (e.g. a parts tier).', 'wc-pricebook' ), $override_options, (string) ( $tier['override'] ?? '' ) );
				?>
				<?php
				$wp_role_options = array( '' => __( '— Select a role —', 'wc-pricebook' ) );
				if ( function_exists( 'wp_roles' ) ) {
					foreach ( wp_roles()->get_names() as $slug => $title ) {
						$wp_role_options[ $slug ] = $title . ' (' . $slug . ')';
					}
				}
				$this->render_select_field( $base . '[key]', __( 'Role', 'wc-pricebook' ), __( 'The WP role that puts a user in this tier (membership = users with this role). Roles are created and assigned outside the plugin.', 'wc-pricebook' ), $wp_role_options, (string) ( $tier['key'] ?? '' ) );
				?>
				<div class="wc-pricebook-field wc-pricebook-field--full">
					<label><?php esc_html_e( 'Notes', 'wc-pricebook' ); ?></label>
					<textarea class="large-text" rows="2" name="<?php echo esc_attr( $base . '[notes]' ); ?>"><?php echo esc_textarea( (string) ( $tier['notes'] ?? '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal notes about this tier (not shown to customers).', 'wc-pricebook' ); ?></p>
				</div>
			</div>
			<?php /* Price/sale meta are hidden from the UI but preserved on save. The tier key IS the selected role slug (the dropdown above). */ ?>
			<input type="hidden" name="<?php echo esc_attr( $base . '[price_meta]' ); ?>" value="<?php echo esc_attr( (string) ( $tier['price_meta'] ?? '' ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base . '[sale_meta]' ); ?>" value="<?php echo esc_attr( (string) ( $tier['sale_meta'] ?? '' ) ); ?>">
			<div class="wc-pricebook-tier-categories">
				<?php
				$pricing = isset( $tier['pricing_categories'] ) && is_array( $tier['pricing_categories'] ) ? $tier['pricing_categories'] : array();
				$this->render_category_set( $base . '[pricing_categories]', __( 'Pricing categories', 'wc-pricebook' ), __( 'Which products this tier prices.', 'wc-pricebook' ), $categories, $pricing );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the visibility-roles repeater (one row per role + a blank template).
	 *
	 * @param string                            $name  Option name prefix.
	 * @param array<string,array<string,mixed>> $roles Configured visibility roles.
	 * @return void
	 */
	private function render_visibility_roles( $name, array $roles ) {
		$categories = $this->product_categories();
		?>
		<h2><?php esc_html_e( 'Visibility roles', 'wc-pricebook' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Each visibility role controls which product categories its users can see, independent of pricing. Pick the roles it applies to and whether a user must match ANY or ALL of them; "MSRP Customer" matches shoppers with no pricing tier. A user’s own visibility settings (on their profile) override these.', 'wc-pricebook' ); ?></p>
		<div class="wc-pricebook-repeater" data-repeater>
			<div class="wc-pricebook-repeater__list" data-repeater-list>
				<?php
				$index = 0;
				foreach ( $roles as $role ) {
					$this->render_visibility_role_row( $name, (string) $index, is_array( $role ) ? $role : array(), $categories );
					$index++;
				}
				?>
			</div>
			<template data-repeater-template>
				<?php $this->render_visibility_role_row( $name, '__INDEX__', array(), $categories ); ?>
			</template>
			<button type="button" class="button wc-pricebook-repeater__add" data-repeater-add><?php esc_html_e( 'Add visibility role', 'wc-pricebook' ); ?></button>
		</div>
		<?php
	}


	/**
	 * Render a single visibility-role row: a label plus a category-scope set.
	 *
	 * @param string              $name       Option name prefix.
	 * @param string              $index      Row index (or "__INDEX__" placeholder).
	 * @param array<string,mixed> $role       Role values.
	 * @param array<int,\WP_Term> $categories Product categories.
	 * @return void
	 */
	private function render_visibility_role_row( $name, $index, array $role, array $categories ) {
		$base = $name . '[visibility_roles][' . $index . ']';
		?>
		<div class="wc-pricebook-repeater__item" data-repeater-item>
			<a href="#" class="wc-pricebook-repeater__remove dashicons dashicons-trash" data-repeater-remove role="button" aria-label="<?php esc_attr_e( 'Remove visibility role', 'wc-pricebook' ); ?>"></a>
			<div class="wc-pricebook-repeater__grid">
				<div class="wc-pricebook-field wc-pricebook-field--full">
					<label><?php esc_html_e( 'Name', 'wc-pricebook' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $base . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $role['label'] ?? '' ) ); ?>">
				</div>
				<div class="wc-pricebook-field wc-pricebook-field--full">
					<label><?php esc_html_e( 'Roles', 'wc-pricebook' ); ?></label>
					<?php
					$selected_roles = isset( $role['roles'] ) && is_array( $role['roles'] ) ? array_map( 'strval', $role['roles'] ) : array();
					?>
					<select multiple class="wc-enhanced-select" name="<?php echo esc_attr( $base . '[roles][]' ); ?>" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Select roles&hellip;', 'wc-pricebook' ); ?>">
						<?php foreach ( $this->role_options() as $slug => $role_label ) : ?>
							<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php echo in_array( (string) $slug, $selected_roles, true ) ? 'selected="selected"' : ''; ?>><?php echo esc_html( $role_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Users are matched by these roles. "MSRP Customer" matches anyone without a pricing tier (retail customers, subscribers, guests).', 'wc-pricebook' ); ?></p>
				</div>
				<div class="wc-pricebook-field wc-pricebook-field--full">
					<label><?php esc_html_e( 'Specific users', 'wc-pricebook' ); ?></label>
					<?php $selected_users = isset( $role['users'] ) && is_array( $role['users'] ) ? array_map( 'intval', $role['users'] ) : array(); ?>
					<select multiple class="wc-customer-search" name="<?php echo esc_attr( $base . '[users][]' ); ?>" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'wc-pricebook' ); ?>" data-allow_clear="true">
						<?php
						foreach ( $selected_users as $uid ) {
							$u = get_userdata( $uid );
							if ( ! $u ) {
								continue;
							}
							printf( '<option value="%d" selected="selected">%s</option>', (int) $uid, esc_html( sprintf( '%1$s (#%2$d &ndash; %3$s)', $u->display_name, (int) $uid, $u->user_email ) ) );
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'These specific users are matched in addition to the roles above.', 'wc-pricebook' ); ?></p>
				</div>
				<div class="wc-pricebook-field">
					<label><?php esc_html_e( 'Match', 'wc-pricebook' ); ?></label>
					<?php $match = isset( $role['match'] ) && 'all' === $role['match'] ? 'all' : 'any'; ?>
					<select name="<?php echo esc_attr( $base . '[match]' ); ?>">
						<option value="any" <?php selected( 'any', $match ); ?>><?php esc_html_e( 'ANY of these roles', 'wc-pricebook' ); ?></option>
						<option value="all" <?php selected( 'all', $match ); ?>><?php esc_html_e( 'ALL of these roles', 'wc-pricebook' ); ?></option>
					</select>
				</div>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $base . '[key]' ); ?>" value="<?php echo esc_attr( (string) ( $role['key'] ?? '' ) ); ?>">
			<div class="wc-pricebook-repeater__grid">
				<div class="wc-pricebook-tier-categories">
					<?php
					$set = isset( $role['categories'] ) && is_array( $role['categories'] ) ? $role['categories'] : array();
					$this->render_category_set( $base . '[categories]', __( 'Categories', 'wc-pricebook' ), __( 'The products this role acts on for matched users.', 'wc-pricebook' ), $categories, $set );
					?>
				</div>
				<div class="wc-pricebook-field">
					<label><?php esc_html_e( 'Hide', 'wc-pricebook' ); ?></label>
					<?php $hide = in_array( $role['hide'] ?? '', array( 'product', 'pricing' ), true ) ? $role['hide'] : ''; ?>
					<select name="<?php echo esc_attr( $base . '[hide]' ); ?>">
						<option value="" <?php selected( '', $hide ); ?>><?php esc_html_e( 'None', 'wc-pricebook' ); ?></option>
						<option value="product" <?php selected( 'product', $hide ); ?>><?php esc_html_e( 'Hide Product', 'wc-pricebook' ); ?></option>
						<option value="pricing" <?php selected( 'pricing', $hide ); ?>><?php esc_html_e( 'Hide Pricing', 'wc-pricebook' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Hide Product removes these categories’ products from the catalog; Hide Pricing shows “Call for Price” — for matched users.', 'wc-pricebook' ); ?></p>
				</div>
				<div class="wc-pricebook-field wc-pricebook-field--full">
					<label><?php esc_html_e( 'Notes', 'wc-pricebook' ); ?></label>
					<textarea class="large-text" rows="2" name="<?php echo esc_attr( $base . '[notes]' ); ?>"><?php echo esc_textarea( (string) ( $role['notes'] ?? '' ) ); ?></textarea>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a labeled dropdown for a tier field. If the stored value is not among
	 * the options (e.g. a tier that was later removed), it is appended so saving the
	 * row does not silently change it.
	 *
	 * @param string                $field_name Full field name.
	 * @param string                $label      Field label.
	 * @param string                $help       Description text.
	 * @param array<string,string>  $options    Option map (value => label).
	 * @param string                $current    Stored value.
	 * @return void
	 */
	private function render_select_field( $field_name, $label, $help, array $options, $current ) {
		if ( '' !== $current && ! array_key_exists( $current, $options ) ) {
			$options[ $current ] = $current;
		}
		?>
		<div class="wc-pricebook-field">
			<label><?php echo esc_html( $label ); ?></label>
			<select name="<?php echo esc_attr( $field_name ); ?>">
				<?php foreach ( $options as $value => $opt_label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $value, $current ); ?>><?php echo esc_html( $opt_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a category-scope set: an all/include/exclude mode plus a checkbox list.
	 *
	 * @param string              $base       Field name base (e.g. ...[pricing_categories]).
	 * @param string              $heading    Set heading.
	 * @param string              $help       Description text.
	 * @param array<int,\WP_Term> $categories Available categories.
	 * @param array<string,mixed> $set        Stored set { mode, categories }.
	 * @return void
	 */
	private function render_category_set( $base, $heading, $help, array $categories, array $set ) {
		$mode     = isset( $set['mode'] ) ? (string) $set['mode'] : 'all';
		$selected = isset( $set['categories'] ) && is_array( $set['categories'] ) ? array_map( 'intval', $set['categories'] ) : array();
		$modes    = array(
			'all'     => __( 'All categories', 'wc-pricebook' ),
			'include' => __( 'Only selected categories', 'wc-pricebook' ),
			'exclude' => __( 'All except selected', 'wc-pricebook' ),
		);
		?>
		<fieldset class="wc-pricebook-catset">
			<legend><?php echo esc_html( $heading ); ?></legend>
			<p class="description"><?php echo esc_html( $help ); ?></p>
			<div class="wc-pricebook-catset__modes">
				<?php foreach ( $modes as $value => $mode_label ) : ?>
					<label><input type="radio" name="<?php echo esc_attr( $base . '[mode]' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?> data-catset-mode> <?php echo esc_html( $mode_label ); ?></label>
				<?php endforeach; ?>
			</div>
			<?php // Categories are hidden when the mode is "all" (toggled by settings.js). ?>
			<div data-catset-categories <?php echo 'all' === $mode ? 'hidden' : ''; ?>>
				<?php if ( empty( $categories ) ) : ?>
					<p class="description"><em><?php esc_html_e( 'No product categories exist yet.', 'wc-pricebook' ); ?></em></p>
				<?php else : ?>
					<div class="wc-pricebook-catset__list">
						<?php foreach ( $categories as $term ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $base . '[categories][]' ); ?>" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $selected, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</fieldset>
		<?php
	}

}
