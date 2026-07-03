<?php
/**
 * Plugin configuration: tiers, base meta, rules, user-meta keys, context, modules.
 *
 * Every value is backed by a stored option ({@see self::OPTION}) and additionally
 * filterable, so a host site can drive behavior entirely from data (settings or a
 * migration script) without writing glue code. Filters remain as an escape hatch.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and normalizes plugin configuration.
 */
class Config {

	/**
	 * Option key holding the full configuration array.
	 */
	const OPTION = 'wc_pricebook_config';

	/**
	 * User-meta keys the engine reads. Hardcoded (not user-configurable); the
	 * wc_pricebook_user_meta_keys filter remains as an escape hatch.
	 *
	 * @var array<string,string>
	 */
	const USER_META_KEYS = array(
		'category_roles'     => 'pricebook_category_roles',
		'include_products'   => 'pricebook_include_products',
		'include_categories' => 'pricebook_include_categories',
		'exclude_categories' => 'pricebook_exclude_categories',
		'switcher_role'      => 'pricebook_switcher_role',
		'switcher_user'      => 'pricebook_switcher_user',
	);

	/**
	 * Shortcode tags. Hardcoded (not user-configurable).
	 *
	 * @var array<string,string>
	 */
	const SHORTCODES = array(
		'table'      => 'pricebook_table',
		'products'   => 'pricebook_user_products',
		'bulk_table' => 'pricebook_bulk_table',
	);

	/**
	 * Base (MSRP) price meta keys. Hardcoded; the wc_pricebook_base_meta filter
	 * remains as an escape hatch.
	 *
	 * @var array<string,string>
	 */
	const BASE_META = array(
		'regular' => '_regular_price',
		'sale'    => '_sale_price',
	);

	/**
	 * Per-user product price override meta key. Hardcoded; the
	 * wc_pricebook_user_pricing_meta filter remains as an escape hatch.
	 */
	const USER_PRICING_META = '_pricebook_user_pricing';

	/**
	 * Per-product, per-role quantity-break ("bulk") pricing meta key. Stores a map of
	 * tier key => list of { min_qty, price } rows. Hardcoded; the
	 * wc_pricebook_bulk_pricing_meta filter remains as an escape hatch.
	 */
	const BULK_PRICING_META = '_pricebook_bulk_pricing';

	/**
	 * Manager identity. Hardcoded; extend manager detection in theme code via the
	 * wc_pricebook_is_manager filter rather than configuring roles here.
	 *
	 * @var array{capability:string,roles:array<int,string>}
	 */
	const MANAGER = array(
		'capability' => 'manage_woocommerce',
		'roles'      => array(),
	);

	/**
	 * Cached, normalized configuration.
	 *
	 * @var array<string,mixed>|null
	 */
	private $config = null;

	/**
	 * Default, intentionally generic configuration.
	 *
	 * Ships one example "wholesale" tier so the mechanism is demonstrable out of
	 * the box. Site-specific tiers/rules are applied via the settings page or a
	 * store's own migration adapter, not baked in here.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'tiers'             => array(
				'wholesale' => array(
					'key'        => 'wholesale',
					'label'      => 'Wholesale',
					'price_meta' => '_wholesale_price',
					'sale_meta'  => '_wholesale_sale_price',
					'multiplier' => 1.0,
					/* Base pricing role ('msrp' or a tier key) whose price is multiplied when no specific price is set. Empty = no multiplier fallback. */
					'base_meta'  => '',
					/* 'msrp', another tier key, or '' for what to return when nothing matches (also used by the no_tier_discount rule). */
					'fallback_to' => 'msrp',
					/* Category scope for which products this tier prices. */
					'pricing_categories'    => array( 'mode' => 'all', 'categories' => array() ),
				),
			),
			/* Visibility roles: named category-visibility scopes, independent of pricing. */
			'visibility_roles'  => array(),
			/* User-meta key overrides (logical name => meta key). Empty = use USER_META_KEYS defaults. */
			'user_meta'         => array(),
			/* Per-user product override meta key. Empty = use USER_PRICING_META default. */
			'user_pricing_meta' => '',
			'rules'             => array(
				'skip_matrix'       => array( 'bindings' => array() ),
				'no_tier_discount'  => array( 'bindings' => array() ),
				'force_visible'     => array( 'bindings' => array() ),
				'call_for_price'    => array( 'bindings' => array() ),
			),
			/* "Call for Price" label shown for empty-priced products. Empty = leave WooCommerce's default. */
			'modules'           => array(
				'switcher'       => true,
				'flowchart'      => false,
				'product_prices' => false,
				'product_meta'   => true,
			),
			/* Pricelist CSV export: emailed recipient, cron cadence, and an optional role filter. */
			'export'            => array(
				/* Recipient email for the scheduled/emailed pricelist. Empty = site admin_email at send time. */
				'recipient' => '',
				/* Cron cadence: 'off' (no scheduled export), 'daily', or 'weekly'. */
				'schedule'  => 'off',
				/* Limit the export to users in these WP roles. Empty = every user in the system. */
				'roles'     => array(),
			),
		);
	}

	/**
	 * Full normalized configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null === $this->config ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$this->config = $this->merge_defaults( $stored );
		}
		return $this->config;
	}

	/**
	 * Deep-merge stored config over defaults (one level into known sub-arrays).
	 *
	 * @param array<string,mixed> $stored Stored config.
	 * @return array<string,mixed>
	 */
	private function merge_defaults( array $stored ) {
		$defaults = self::defaults();
		$merged   = $defaults;

		// Keys whose stored value fully REPLACES the default rather than merging into
		// it: a configured tier set is authoritative, so the example "wholesale" tier
		// the defaults ship only seeds a fresh (unconfigured) install. Other sub-arrays
		// (rules, modules) merge so the defaults fill in any keys the store omits.
		$replace = array( 'tiers' );

		foreach ( $stored as $key => $value ) {
			if ( ! in_array( $key, $replace, true ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$merged[ $key ] = array_merge( $defaults[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * Tier definitions keyed by tier key, normalized with all expected fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function tiers() {
		$tiers = $this->all()['tiers'];
		$tiers = is_array( $tiers ) ? $tiers : array();

		$normalized = array();
		foreach ( $tiers as $key => $tier ) {
			$tier               = is_array( $tier ) ? $tier : array();
			$tier['key']        = isset( $tier['key'] ) ? $tier['key'] : $key;
			$tier['label']      = isset( $tier['label'] ) ? $tier['label'] : ucfirst( $key );
			// Price meta keys default to a stable per-tier key so a manual per-product
			// price (set in the product meta box) always has somewhere to live.
			$tier['price_meta'] = isset( $tier['price_meta'] ) && '' !== $tier['price_meta'] ? $tier['price_meta'] : '_pricebook_' . $tier['key'] . '_price';
			$tier['sale_meta']  = isset( $tier['sale_meta'] ) && '' !== $tier['sale_meta'] ? $tier['sale_meta'] : '_pricebook_' . $tier['key'] . '_sale_price';
			$tier['multiplier'] = isset( $tier['multiplier'] ) ? (float) $tier['multiplier'] : 1.0;
			$tier['base_meta']  = isset( $tier['base_meta'] ) ? $tier['base_meta'] : '';
			$tier['fallback_to'] = isset( $tier['fallback_to'] ) ? $tier['fallback_to'] : 'msrp';
			// How the tier overrides the lowest-wins comparison when it applies (the
			// user belongs to it and it is in category scope):
			//   'always'      — always wins, using its resolved price (fallback included),
			//                   e.g. a parts tier pinning a category to dealer or MSRP.
			//   'when_priced' — wins ONLY when the tier has its own price set for the
			//                   product; otherwise it is inert and lowest-wins runs, e.g.
			//                   operator pricing that overrides only where it is set.
			//   ''            — no override; the tier is a normal lowest-wins candidate.
			$override          = isset( $tier['override'] ) ? (string) $tier['override'] : '';
			$tier['override']  = in_array( $override, array( 'always', 'when_priced' ), true ) ? $override : '';
			$tier['pricing_categories'] = $this->normalize_category_set( isset( $tier['pricing_categories'] ) ? $tier['pricing_categories'] : array() );
			$tier['notes']      = isset( $tier['notes'] ) ? (string) $tier['notes'] : '';
			$normalized[ $tier['key'] ] = $tier;
		}

		/**
		 * Filters the configured price tiers.
		 *
		 * @param array<string,array<string,mixed>> $normalized Tier definitions.
		 */
		return apply_filters( 'wc_pricebook_tiers', $normalized );
	}

	/**
	 * Visibility roles keyed by role key: named category-visibility scopes that are
	 * independent of pricing. Each is { key, label, mode, categories }.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function visibility_roles() {
		$roles = $this->all()['visibility_roles'];
		$roles = is_array( $roles ) ? $roles : array();

		$normalized = array();
		foreach ( $roles as $key => $role ) {
			$role       = is_array( $role ) ? $role : array();
			$key        = isset( $role['key'] ) ? $role['key'] : $key;
			$want       = isset( $role['roles'] ) && is_array( $role['roles'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $role['roles'] ) ) ) ) : array();
			$users      = isset( $role['users'] ) && is_array( $role['users'] ) ? array_values( array_unique( array_filter( array_map( 'intval', $role['users'] ) ) ) ) : array();
			$match      = isset( $role['match'] ) && 'all' === $role['match'] ? 'all' : 'any';
			// Category SET ({ mode, categories }) — the same scoping model as pricing tiers.
			$categories = $this->normalize_category_set( isset( $role['categories'] ) ? $role['categories'] : array() );
			$hide               = isset( $role['hide'] ) ? (string) $role['hide'] : '';
			$hide               = in_array( $hide, array( 'product', 'pricing' ), true ) ? $hide : '';
			$normalized[ $key ] = array(
				'key'        => $key,
				'label'      => isset( $role['label'] ) ? $role['label'] : ucfirst( $key ),
				'roles'      => $want,
				'users'      => $users,
				'match'      => $match,
				'categories' => $categories,
				// Single action: '' none, 'product' (remove from catalog), 'pricing' (Call for Price).
				'hide'       => $hide,
				'notes'      => isset( $role['notes'] ) ? (string) $role['notes'] : '',
			);
		}

		/**
		 * Filters the configured visibility roles.
		 *
		 * @param array<string,array<string,mixed>> $normalized Visibility role definitions.
		 */
		return apply_filters( 'wc_pricebook_visibility_roles', $normalized );
	}

	/**
	 * Normalize a category-scope set to { mode: all|include|exclude, categories: int[] }.
	 *
	 * @param mixed $set Raw set.
	 * @return array{mode:string,categories:array<int,int>}
	 */
	private function normalize_category_set( $set ) {
		$set  = is_array( $set ) ? $set : array();
		$mode = isset( $set['mode'] ) ? (string) $set['mode'] : 'all';
		if ( ! in_array( $mode, array( 'all', 'include', 'exclude' ), true ) ) {
			$mode = 'all';
		}
		$categories = isset( $set['categories'] ) && is_array( $set['categories'] ) ? array_map( 'intval', $set['categories'] ) : array();
		return array(
			'mode'       => $mode,
			'categories' => array_values( array_unique( $categories ) ),
		);
	}

	/**
	 * A single tier definition, or null if unknown.
	 *
	 * @param string $key Tier key.
	 * @return array<string,mixed>|null
	 */
	public function tier( $key ) {
		$tiers = $this->tiers();
		return isset( $tiers[ $key ] ) ? $tiers[ $key ] : null;
	}

	/**
	 * Base (MSRP) regular + sale meta keys.
	 *
	 * @return array{regular:string,sale:string}
	 */
	public function base_meta() {
		/** This filter is documented above. */
		return apply_filters( 'wc_pricebook_base_meta', self::BASE_META );
	}

	/**
	 * Per-user product override meta key (empty disables the feature). Defaults to
	 * USER_PRICING_META; a stored 'user_pricing_meta' config string overrides it so a
	 * host store can point the plugin at its existing key (e.g. a legacy 'user-pricing'
	 * meta key). The wc_pricebook_user_pricing_meta filter remains the final escape
	 * hatch.
	 *
	 * @return string
	 */
	public function user_pricing_meta() {
		$configured = $this->all()['user_pricing_meta'] ?? '';
		$meta_key   = ( is_string( $configured ) && '' !== $configured ) ? $configured : self::USER_PRICING_META;
		/** This filter is documented above. */
		return apply_filters( 'wc_pricebook_user_pricing_meta', $meta_key );
	}

	/**
	 * Per-product bulk (quantity-break) pricing meta key (empty disables the feature).
	 *
	 * @return string
	 */
	public function bulk_pricing_meta() {
		/** This filter is documented above. */
		return apply_filters( 'wc_pricebook_bulk_pricing_meta', self::BULK_PRICING_META );
	}

	/**
	 * Rule → taxonomy/term bindings.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function rules() {
		/** This filter is documented above. */
		return apply_filters( 'wc_pricebook_rules', $this->all()['rules'] );
	}

	/**
	 * User-meta key map (category roles, my-products, include/exclude categories,
	 * visibility + switcher role). The USER_META_KEYS constants are defaults; a
	 * stored 'user_meta' config map overrides individual keys so a host store can
	 * point the plugin at its existing meta keys (e.g. repointing 'category_roles' at
	 * a legacy 'category-pricing-roles' key). The wc_pricebook_user_meta_keys filter
	 * remains the final escape hatch.
	 *
	 * @return array<string,string>
	 */
	public function user_meta_keys() {
		$configured = isset( $this->all()['user_meta'] ) && is_array( $this->all()['user_meta'] ) ? $this->all()['user_meta'] : array();
		$keys       = self::USER_META_KEYS;
		foreach ( $configured as $name => $key ) {
			if ( is_string( $key ) && '' !== $key ) {
				$keys[ $name ] = $key;
			}
		}
		/** This filter is documented above. */
		return apply_filters( 'wc_pricebook_user_meta_keys', $keys );
	}

	/**
	 * Manager identification config (capability + role list). Hardcoded; extend via
	 * the wc_pricebook_is_manager filter in theme code.
	 *
	 * @return array{capability:string,roles:array<int,string>}
	 */
	public function manager() {
		return self::MANAGER;
	}

	/**
	 * Pricelist-export settings, normalized: emailed recipient, cron cadence, and an
	 * optional role filter. The wc_pricebook_export_settings filter is the escape hatch
	 * (e.g. to force a recipient in code).
	 *
	 * @return array{recipient:string,schedule:string,roles:array<int,string>}
	 */
	public function export() {
		$export   = isset( $this->all()['export'] ) && is_array( $this->all()['export'] ) ? $this->all()['export'] : array();
		$schedule = isset( $export['schedule'] ) ? (string) $export['schedule'] : 'off';
		$settings = array(
			'recipient' => isset( $export['recipient'] ) ? (string) $export['recipient'] : '',
			'schedule'  => in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : 'off',
			'roles'     => isset( $export['roles'] ) && is_array( $export['roles'] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_key', $export['roles'] ) ) ) )
				: array(),
		);

		/**
		 * Filters the pricelist-export settings.
		 *
		 * @param array{recipient:string,schedule:string,roles:array<int,string>} $settings Export settings.
		 */
		return apply_filters( 'wc_pricebook_export_settings', $settings );
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module Module key (switcher|flowchart).
	 * @return bool
	 */
	public function module_enabled( $module ) {
		$modules = $this->all()['modules'];
		return ! empty( $modules[ $module ] );
	}

	/**
	 * Shortcode tags map.
	 *
	 * @return array<string,string>
	 */
	public function shortcodes() {
		return self::SHORTCODES;
	}
}
