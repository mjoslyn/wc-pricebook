<?php
/**
 * Manager pricing-view switcher (admin-bar control).
 *
 * Generic port of the theme's PricebookSwitcher: lets a manager preview prices as
 * any tier. Available roles, the meta key, and labels all come from config.
 *
 * @package WCPricebook
 */

namespace WCPricebook\Switcher;

use WCPricebook\Config;
use WCPricebook\Context;
use WCPricebook\PriceEngine;
use WCPricebook\Flowchart\Flowchart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an admin-bar pricing-view switcher for managers.
 */
class Switcher {

	const AJAX_SWITCH      = 'wc_pricebook_switch_role';
	const AJAX_SWITCH_USER = 'wc_pricebook_switch_user';
	const AJAX_SEARCH_USER = 'wc_pricebook_search_user';
	const NONCE            = 'wc_pricebook_switcher';

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
		add_action( 'wp_ajax_' . self::AJAX_SWITCH, array( $this, 'handle_switch' ) );
		add_action( 'wp_ajax_' . self::AJAX_SWITCH_USER, array( $this, 'handle_switch_user' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_USER, array( $this, 'ajax_search_user' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );
	}

	/**
	 * Available pricing roles: MSRP plus each configured tier.
	 *
	 * @return array<string,string>
	 */
	public function available_roles() {
		$roles = array( 'msrp' => __( 'MSRP Pricing', 'wc-pricebook' ) );
		foreach ( $this->config->tiers() as $key => $tier ) {
			$roles[ $key ] = $tier['label'];
		}
		return $roles;
	}

	/**
	 * The manager's currently selected role (defaults to MSRP).
	 *
	 * @return string
	 */
	public function current_role() {
		$role = $this->context->switcher_role();
		if ( '' === $role || ! isset( $this->available_roles()[ $role ] ) ) {
			return 'msrp';
		}
		return $role;
	}

	/**
	 * AJAX: switch the manager's pricing view.
	 *
	 * @return void
	 */
	public function handle_switch() {
		if ( ! $this->context->current_user_is_manager() ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 400 );
		}

		$role  = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
		$roles = $this->available_roles();
		if ( ! isset( $roles[ $role ] ) ) {
			wp_send_json_error( 'invalid_role', 400 );
		}

		$meta_key = $this->context->meta_key( 'switcher_role' );
		if ( '' !== $meta_key ) {
			update_user_meta( get_current_user_id(), $meta_key, $role );
		}

		// Switching to a role clears any customer impersonation so the role view wins.
		$user_key = $this->context->meta_key( 'switcher_user' );
		if ( '' !== $user_key ) {
			delete_user_meta( get_current_user_id(), $user_key );
		}

		$this->refresh_cart();

		wp_send_json_success(
			array(
				'role'    => $role,
				'label'   => $roles[ $role ],
				'message' => sprintf(
					/* translators: %s: pricing role label. */
					__( 'Pricing view switched to: %s', 'wc-pricebook' ),
					$roles[ $role ]
				),
			)
		);
	}

	/**
	 * AJAX: preview pricing as a specific customer (or clear it when user is 0).
	 *
	 * @return void
	 */
	public function handle_switch_user() {
		if ( ! $this->context->current_user_is_manager() ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 400 );
		}

		$user_id  = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		$meta_key = $this->context->meta_key( 'switcher_user' );
		if ( '' === $meta_key ) {
			wp_send_json_error( 'disabled', 400 );
		}

		if ( $user_id > 0 && get_user_by( 'id', $user_id ) ) {
			update_user_meta( get_current_user_id(), $meta_key, $user_id );
			// Previewing as a user supersedes the role view; clear it.
			$role_key = $this->context->meta_key( 'switcher_role' );
			if ( '' !== $role_key ) {
				delete_user_meta( get_current_user_id(), $role_key );
			}
		} else {
			delete_user_meta( get_current_user_id(), $meta_key );
			$user_id = 0;
		}

		$this->refresh_cart();

		wp_send_json_success( array( 'user' => $user_id ) );
	}

	/**
	 * AJAX: search users for the "preview as customer" control (manager-only).
	 *
	 * @return void
	 */
	public function ajax_search_user() {
		if ( ! $this->context->current_user_is_manager() ) {
			wp_send_json_error( 'unauthorized', 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce', 400 );
		}

		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => 20,
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'   => (int) $user->ID,
				'text' => sprintf( '%s (%s)', $user->display_name, $user->user_email ),
			);
		}
		wp_send_json_success( $results );
	}

	/**
	 * Recalculate cart item prices after a switch.
	 *
	 * @return void
	 */
	private function refresh_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['bundled_by'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$product    = $cart_item['data'];
			$product_id = $cart_item['product_id'];
			$new_price  = $this->engine->effective_price( null, wc_get_product( $product_id ) );
			$product->set_price( $new_price );
			WC()->cart->cart_contents[ $cart_item_key ]['new_price'] = $new_price;
		}
		WC()->cart->calculate_totals();
	}

	/**
	 * Cache-busting version for a bundled asset: its file modification time, falling
	 * back to the plugin version. Keeps the toolbar JS/CSS fresh as they change.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	private function asset_version( $relative_path ) {
		$path = WC_PRICEBOOK_DIR . $relative_path;
		return file_exists( $path ) ? (string) filemtime( $path ) : WC_PRICEBOOK_VERSION;
	}

	/**
	 * Enqueue assets for managers when the admin bar is visible.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->context->current_user_is_manager() || ! is_admin_bar_showing() ) {
			return;
		}

		wp_enqueue_style(
			'wc-pricebook-switcher',
			WC_PRICEBOOK_URL . 'src/Switcher/assets/switcher.css',
			array(),
			$this->asset_version( 'src/Switcher/assets/switcher.css' )
		);
		wp_enqueue_script(
			'wc-pricebook-switcher',
			WC_PRICEBOOK_URL . 'src/Switcher/assets/switcher.js',
			array( 'jquery' ),
			$this->asset_version( 'src/Switcher/assets/switcher.js' ),
			true
		);
		$impersonate_id    = $this->context->switcher_user_id();
		$impersonate_user  = $impersonate_id ? get_userdata( $impersonate_id ) : false;
		$impersonate_label = $impersonate_user ? $impersonate_user->display_name : '';

		wp_localize_script(
			'wc-pricebook-switcher',
			'wcPricebookSwitcher',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'action'             => self::AJAX_SWITCH,
				'switchUser'         => self::AJAX_SWITCH_USER,
				'searchUser'         => self::AJAX_SEARCH_USER,
				'nonce'              => wp_create_nonce( self::NONCE ),
				'currentRole'        => $this->current_role(),
				'impersonating'      => $impersonate_id,
				'impersonatingLabel' => $impersonate_label,
				'labels'             => $this->available_roles(),
				'i18n'               => array(
					'title'       => __( 'Preview pricing as customer', 'wc-pricebook' ),
					'placeholder' => __( 'Search by name or email…', 'wc-pricebook' ),
					'noResults'   => __( 'No customers found', 'wc-pricebook' ),
					'previewing'  => __( 'Previewing as', 'wc-pricebook' ),
					'clear'       => __( 'Clear preview', 'wc-pricebook' ),
					'close'       => __( 'Close', 'wc-pricebook' ),
				),
			)
		);
	}

	/**
	 * Build the admin-bar menu.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar_menu( $bar ) {
		if ( ! $this->context->current_user_is_manager() ) {
			return;
		}

		$roles        = $this->available_roles();
		$current_role = $this->current_role();

		// A specific customer being previewed takes precedence in the toolbar label.
		$impersonate_id = $this->context->switcher_user_id();
		$impersonate    = $impersonate_id ? get_userdata( $impersonate_id ) : false;
		if ( $impersonate ) {
			/* translators: %s: customer display name. */
			$current = sprintf( __( 'As: %s', 'wc-pricebook' ), $impersonate->display_name );
		} else {
			$current = isset( $roles[ $current_role ] ) ? $roles[ $current_role ] : $roles['msrp'];
		}

		// When viewing a product, flag tiers that have quantity-break pricing for it so
		// the manager knows the switched view is the base price and bulk applies on top.
		$product_id = Flowchart::current_product_id();

		$bar->add_menu(
			array(
				'id'    => 'wc-pricebook-switcher',
				'title' => '<span class="ab-icon dashicons dashicons-money-alt"></span> ' . esc_html( $current ),
				'href'  => '#',
				'meta'  => array(
					'class' => 'wc-pricebook-admin-bar-menu',
					'title' => __( 'Switch pricing view', 'wc-pricebook' ),
				),
			)
		);

		foreach ( $roles as $role_key => $role_label ) {
			$is_current = ( $role_key === $current_role );

			$title = esc_html( $role_label );
			if ( 'msrp' !== $role_key && $product_id && ! empty( $this->engine->bulk_breaks( $product_id, $role_key ) ) ) {
				$title .= ' <span class="wc-pricebook-qty-note">(' . esc_html__( 'quantity pricing applies', 'wc-pricebook' ) . ')</span>';
			}
			$title .= $is_current ? ' &#10003;' : '';

			$bar->add_menu(
				array(
					'parent' => 'wc-pricebook-switcher',
					'id'     => 'wc-pricebook-role-' . $role_key,
					'title'  => $title,
					'href'   => '#',
					'meta'   => array(
						'class' => 'wc-pricebook-role-item' . ( $is_current ? ' current-role' : '' ),
						'html'  => '',
					),
				)
			);
		}

		// "Preview as a specific customer" — opens a modal (built by the script).
		$bar->add_menu(
			array(
				'parent' => 'wc-pricebook-switcher',
				'id'     => 'wc-pricebook-user-switch',
				'title'  => $impersonate
					? sprintf(
						/* translators: %s: customer display name. */
						esc_html__( 'Previewing as: %s', 'wc-pricebook' ),
						esc_html( $impersonate->display_name )
					)
					: esc_html__( 'Preview as customer…', 'wc-pricebook' ),
				'href'   => '#',
				'meta'   => array( 'class' => 'wc-pricebook-user-switch-trigger' ),
			)
		);

		if ( $this->config->module_enabled( 'flowchart' ) ) {
			$bar->add_menu(
				array(
					'parent' => 'wc-pricebook-switcher',
					'id'     => 'wc-pricebook-flowchart',
					'title'  => __( 'View pricing flowchart', 'wc-pricebook' ),
					'href'   => Flowchart::url( Flowchart::current_product_id() ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}

}
