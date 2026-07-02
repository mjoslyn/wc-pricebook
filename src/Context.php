<?php
/**
 * Pricing context: who is the pricing user, are they a manager, which tiers do
 * they belong to, and their per-user pricing settings.
 *
 * Designed to be data-driven (settings/options) first so a host site needs no
 * glue code. Each resolution also exposes a filter as an escape hatch.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the effective pricing user and their entitlements.
 */
class Context {

	/**
	 * Config provider.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Plugin config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * The user whose pricing applies.
	 *
	 * Multiaccount / parent-account resolution is intentionally not built in: a host
	 * site implements it in theme code via the wc_pricebook_pricing_user filter,
	 * returning the parent WP_User when the current user should be priced as another
	 * account.
	 *
	 * @return \WP_User
	 */
	public function pricing_user() {
		$current = wp_get_current_user();
		$user    = $current;

		// A manager previewing pricing as a specific customer via the switcher. The
		// manager check uses the real current user (not the resolved pricing user) to
		// avoid recursion and so impersonating a non-manager still works.
		if ( $this->user_is_manager( $current ) ) {
			$key         = $this->meta_key( 'switcher_user' );
			$impersonate = '' !== $key ? (int) get_user_meta( $current->ID, $key, true ) : 0;
			if ( $impersonate > 0 && $impersonate !== (int) $current->ID ) {
				$as = get_user_by( 'id', $impersonate );
				if ( $as ) {
					$user = $as;
				}
			}
		}

		/**
		 * Filters the resolved pricing user. Use this to resolve a multiaccount /
		 * parent account: return the parent WP_User to price the current user as it.
		 *
		 * @param \WP_User $user The effective pricing user.
		 */
		return apply_filters( 'wc_pricebook_pricing_user', $user );
	}

	/**
	 * Convenience: pricing user ID.
	 *
	 * @return int
	 */
	public function pricing_user_id() {
		return (int) $this->pricing_user()->ID;
	}

	/**
	 * Resolve an explicit user ID to the user ID whose pricing applies, honoring the
	 * multiaccount parent filter (the same one {@see self::pricing_user} uses). Returns
	 * the input ID unchanged when the user does not exist or no parent resolution
	 * applies. Use this wherever a specific user is priced (not just the current one)
	 * so explicit lookups follow multiaccounts too.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function resolve_pricing_user_id( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return (int) $user_id;
		}
		/** This filter is documented in {@see self::pricing_user}. */
		$resolved = apply_filters( 'wc_pricebook_pricing_user', $user );
		return ( is_object( $resolved ) && isset( $resolved->ID ) ) ? (int) $resolved->ID : (int) $user_id;
	}

	/**
	 * Whether the (resolved) current user is a pricing manager.
	 *
	 * Managers may use the switcher and see all-tier pricing displays.
	 *
	 * @return bool
	 */
	public function is_manager() {
		return $this->user_is_manager( $this->pricing_user() );
	}

	/**
	 * Whether the actual logged-in user (ignoring any switcher impersonation) is a
	 * manager. Use this to gate manager-only UI so impersonating a customer does not
	 * hide the controls needed to switch back.
	 *
	 * @return bool
	 */
	public function current_user_is_manager() {
		return $this->user_is_manager( wp_get_current_user() );
	}

	/**
	 * Whether a given user is a pricing manager (by capability or role).
	 *
	 * @param \WP_User $user User to test.
	 * @return bool
	 */
	private function user_is_manager( $user ) {
		$manager = $this->config->manager();

		$result = false;
		if ( ! empty( $manager['capability'] ) && user_can( $user, $manager['capability'] ) ) {
			$result = true;
		}
		if ( ! $result && ! empty( $manager['roles'] ) && is_array( $manager['roles'] ) ) {
			if ( ! empty( array_intersect( (array) $user->roles, $manager['roles'] ) ) ) {
				$result = true;
			}
		}

		/**
		 * Filters whether a user is a pricing manager.
		 *
		 * @param bool     $result Whether the user is a manager.
		 * @param \WP_User $user   The user tested.
		 */
		return (bool) apply_filters( 'wc_pricebook_is_manager', $result, $user );
	}

	/**
	 * Whether a user belongs to a given tier.
	 *
	 * Default maps a tier key to a user capability of the same name (the common
	 * WooCommerce role-based pattern). Override via filter for custom mapping.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $tier_key Tier key.
	 * @return bool
	 */
	public function user_has_tier( $user_id, $tier_key ) {
		// Membership is a WP role/capability of the same name — the WooCommerce-native
		// pattern. Roles are managed entirely by the store (the plugin never creates,
		// removes, or assigns them); custom mapping is available via the filter below.
		$result = ( '' !== (string) $tier_key ) && user_can( $user_id, $tier_key );

		/**
		 * Filters whether a user belongs to a tier.
		 *
		 * @param bool   $result   Whether the user belongs to the tier.
		 * @param int    $user_id  User ID.
		 * @param string $tier_key Tier key.
		 */
		return (bool) apply_filters( 'wc_pricebook_user_tier', $result, $user_id, $tier_key );
	}

	/**
	 * Whether a user belongs to any configured tier (generic "is a dealer/operator").
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function user_has_any_tier( $user_id ) {
		foreach ( $this->config->tiers() as $key => $tier ) {
			if ( $this->user_has_tier( $user_id, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The tier keys a user holds — the configured tiers whose WP role/capability the
	 * user has. Used for display (e.g. the switcher badge). Membership is role-based;
	 * there is no separate meta assignment.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string>
	 */
	public function user_pricing_roles( $user_id ) {
		$roles = array();
		foreach ( array_keys( $this->config->tiers() ) as $key ) {
			if ( $this->user_has_tier( $user_id, $key ) ) {
				$roles[] = $key;
			}
		}
		return $roles;
	}

	/**
	 * The first pricing role assigned to a user, or '' — for single-value display
	 * (e.g. tier label). Membership checks use {@see self::user_pricing_roles}.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function user_pricing_role( $user_id ) {
		$roles = $this->user_pricing_roles( $user_id );
		return empty( $roles ) ? '' : (string) $roles[0];
	}

	/**
	 * Label of the tier driving the currently displayed price, accounting for a
	 * manager's switcher selection, or '' when pricing is plain MSRP.
	 *
	 * Mirrors the resolution order in {@see PriceEngine::price_for_user}: a manager's
	 * switcher override wins, otherwise the user's assigned/membership tier.
	 *
	 * @return string
	 */
	public function active_tier_label() {
		if ( $this->is_manager() ) {
			$role = $this->switcher_role();
			if ( '' !== $role && 'msrp' !== $role ) {
				$tiers = $this->config->tiers();
				return isset( $tiers[ $role ] ) ? (string) $tiers[ $role ]['label'] : '';
			}
		}
		return $this->user_tier_label( $this->pricing_user_id() );
	}

	/**
	 * Label of the user's effective pricing tier (the first tier they belong to via a
	 * WP role/capability), or '' when they have none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function user_tier_label( $user_id ) {
		foreach ( $this->config->tiers() as $key => $tier ) {
			if ( $this->user_has_tier( $user_id, $key ) ) {
				return (string) $tier['label'];
			}
		}
		return '';
	}

	/**
	 * Whether a product falls inside a category set ({ mode: all|include|exclude,
	 * categories: int[] }) — the same scoping model the pricing tiers use.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $set        Category set.
	 * @return bool
	 */
	public function product_in_category_set( $product_id, array $set ) {
		$mode = isset( $set['mode'] ) ? (string) $set['mode'] : 'all';
		$cats = isset( $set['categories'] ) && is_array( $set['categories'] ) ? array_map( 'intval', $set['categories'] ) : array();
		if ( 'all' === $mode || empty( $cats ) ) {
			return true;
		}
		$in = has_term( $cats, 'product_cat', $product_id );
		return 'include' === $mode ? $in : ! $in;
	}

	/**
	 * The manager's currently selected switcher role (or '').
	 *
	 * @return string
	 */
	public function switcher_role() {
		$meta_key = $this->meta_key( 'switcher_role' );
		if ( '' === $meta_key ) {
			return '';
		}
		return (string) get_user_meta( get_current_user_id(), $meta_key, true );
	}

	/**
	 * The user ID a manager is previewing pricing as via the switcher (0 = none).
	 *
	 * @return int
	 */
	public function switcher_user_id() {
		$meta_key = $this->meta_key( 'switcher_user' );
		if ( '' === $meta_key ) {
			return 0;
		}
		return (int) get_user_meta( get_current_user_id(), $meta_key, true );
	}

	/**
	 * Category → tier-role mappings for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function category_roles( $user_id ) {
		$mappings = array();
		$meta_key = $this->meta_key( 'category_roles' );
		if ( '' !== $meta_key ) {
			$stored = get_user_meta( $user_id, $meta_key, true );
			if ( is_array( $stored ) ) {
				$mappings = $stored;
			}
		}

		/**
		 * Filters a user's category→role mappings (escape hatch for capability-driven rules).
		 *
		 * @param array<int,array<string,mixed>> $mappings Category role mappings.
		 * @param int                            $user_id  User ID.
		 */
		return apply_filters( 'wc_pricebook_category_roles', $mappings, $user_id );
	}

	/**
	 * Visibility include/exclude category lists for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array{include:array<int,int>,exclude:array<int,int>}
	 */
	public function visibility_categories( $user_id ) {
		return array(
			'include' => $this->normalize_id_list( get_user_meta( $user_id, $this->meta_key( 'include_categories' ), true ) ),
			'exclude' => $this->normalize_id_list( get_user_meta( $user_id, $this->meta_key( 'exclude_categories' ), true ) ),
		);
	}

	/**
	 * Normalize a stored ID list to an int array. Accepts an array of IDs (the
	 * plugin's own format) or a comma/space-separated string (the legacy text-field
	 * format, e.g. "176, 249"). Empty/zero values are dropped.
	 *
	 * @param mixed $value Stored meta value.
	 * @return array<int,int>
	 */
	private function normalize_id_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $value ) ) );
	}

	/**
	 * Synthetic role that matches "MSRP customers": any user who holds no pricing tier
	 * — retail customers, subscribers, and guests. Selectable in a visibility role's
	 * roles list so a role can target non-dealer shoppers.
	 */
	const MSRP_CUSTOMER = '_msrp_customer';

	/**
	 * Per-product meta: roles that always see the product in the catalog (overriding
	 * any Hide-visibility role), and roles that always see the price (overriding any
	 * Hide-pricing role or the price_requires_tier gate). Each stores a list of role
	 * slugs (the synthetic MSRP_CUSTOMER is allowed).
	 */
	const FORCE_VISIBLE_ROLES_META = '_wc_pricebook_force_visible_roles';
	const FORCE_PRICE_ROLES_META   = '_wc_pricebook_force_price_roles';

	/**
	 * Per-product meta: specific user IDs that always see the product / its price,
	 * alongside the role-based force overrides above.
	 */
	const FORCE_VISIBLE_USERS_META = '_wc_pricebook_force_visible_users';
	const FORCE_PRICE_USERS_META   = '_wc_pricebook_force_price_users';

	/**
	 * Whether a user matches ANY of a set of role slugs (the synthetic MSRP_CUSTOMER
	 * matches users with no pricing tier). Empty set matches nobody.
	 *
	 * @param int               $user_id User ID.
	 * @param array<int,string> $slugs   Role slugs.
	 * @return bool
	 */
	public function user_in_role_set( $user_id, array $slugs ) {
		if ( empty( $slugs ) ) {
			return false;
		}
		$user       = $user_id ? get_userdata( $user_id ) : false;
		$user_roles = ( $user && is_array( $user->roles ) ) ? $user->roles : array();
		foreach ( $slugs as $slug ) {
			if ( self::MSRP_CUSTOMER === $slug ) {
				if ( ! $this->user_has_any_tier( $user_id ) ) {
					return true;
				}
			} elseif ( in_array( $slug, $user_roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Roles a product forces visible in the catalog (per-product override).
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,string>
	 */
	public function product_force_visible_roles( $product_id ) {
		$roles = get_post_meta( $product_id, self::FORCE_VISIBLE_ROLES_META, true );
		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Roles a product forces the price visible for (per-product override).
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,string>
	 */
	public function product_force_price_roles( $product_id ) {
		$roles = get_post_meta( $product_id, self::FORCE_PRICE_ROLES_META, true );
		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * User IDs a product forces the catalog visibility / price visible for.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int,int>
	 */
	private function product_force_users( $product_id, $meta_key ) {
		$ids = get_post_meta( $product_id, $meta_key, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Whether a product forces the catalog visible for this user — by role or by an
	 * explicit user-ID override.
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID.
	 * @return bool
	 */
	public function user_force_visible( $product_id, $user_id ) {
		return $this->user_in_role_set( $user_id, $this->product_force_visible_roles( $product_id ) )
			|| in_array( (int) $user_id, $this->product_force_users( $product_id, self::FORCE_VISIBLE_USERS_META ), true );
	}

	/**
	 * Whether a product forces the price visible for this user — by role or by an
	 * explicit user-ID override.
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID.
	 * @return bool
	 */
	public function user_force_price( $product_id, $user_id ) {
		return $this->user_in_role_set( $user_id, $this->product_force_price_roles( $product_id ) )
			|| in_array( (int) $user_id, $this->product_force_users( $product_id, self::FORCE_PRICE_USERS_META ), true );
	}

	/**
	 * Visibility-role-derived include/exclude lists for a user, unioned across the
	 * visibility roles the user belongs to. Membership: the user matches the role's
	 * configured roles per its ANY/ALL rule (the synthetic MSRP_CUSTOMER role matches
	 * users with no pricing tier), OR the user is explicitly assigned the role. Roles
	 * in 'all' mode (or with an empty category list) add no restriction.
	 *
	 * @param int $user_id User ID.
	 * @return array{include:array<int,int>,exclude:array<int,int>}
	 */
	public function visibility_role_categories( $user_id ) {
		$exclude = array();

		$user       = $user_id ? get_userdata( $user_id ) : false;
		$user_roles = ( $user && is_array( $user->roles ) ) ? $user->roles : array();
		$is_msrp    = ! $this->user_has_any_tier( $user_id );

		$include = array();
		foreach ( $this->config->visibility_roles() as $role ) {
			if ( 'product' !== ( $role['hide'] ?? '' ) ) {
				continue;
			}
			$set  = isset( $role['categories'] ) && is_array( $role['categories'] ) ? $role['categories'] : array();
			$mode = isset( $set['mode'] ) ? (string) $set['mode'] : 'all';
			$cats = isset( $set['categories'] ) && is_array( $set['categories'] ) ? array_map( 'intval', $set['categories'] ) : array();
			if ( empty( $cats ) ) {
				continue; // "All categories" scope can't be expressed as an archive include/exclude.
			}
			if ( ! $this->user_matches_visibility_role( $user_id, $role, $user_roles, $is_msrp ) ) {
				continue;
			}
			// "Only selected" → hide those categories; "All except selected" → show only them.
			if ( 'include' === $mode ) {
				$exclude = array_merge( $exclude, $cats );
			} elseif ( 'exclude' === $mode ) {
				$include = array_merge( $include, $cats );
			}
		}

		return array(
			'include' => array_values( array_unique( $include ) ),
			'exclude' => array_values( array_unique( $exclude ) ),
		);
	}

	/**
	 * Whether a product's price should be hidden ("Call for Price") for a user because
	 * they match a visibility role with "Hide pricing" on whose categories cover the
	 * product. Independent of catalog visibility — a product can be visible but priced
	 * "Call for Price".
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID (resolved pricing user).
	 * @return bool
	 */
	public function price_hidden_by_visibility_role( $product_id, $user_id ) {
		$user       = $user_id ? get_userdata( $user_id ) : false;
		$user_roles = ( $user && is_array( $user->roles ) ) ? $user->roles : array();
		$is_msrp    = ! $this->user_has_any_tier( $user_id );

		foreach ( $this->config->visibility_roles() as $role ) {
			if ( 'pricing' !== ( $role['hide'] ?? '' ) ) {
				continue;
			}
			if ( ! $this->user_matches_visibility_role( $user_id, $role, $user_roles, $is_msrp ) ) {
				continue;
			}
			$set = isset( $role['categories'] ) && is_array( $role['categories'] ) ? $role['categories'] : array();
			if ( $this->product_in_category_set( $product_id, $set ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a user's roles satisfy a visibility role's role list and ANY/ALL rule.
	 * The synthetic MSRP_CUSTOMER entry is satisfied when the user holds no pricing
	 * tier. An empty role list matches nobody (membership then requires explicit
	 * per-user assignment).
	 *
	 * @param array<string,mixed> $role       Normalized visibility role.
	 * @param array<int,string>   $user_roles The user's WP roles.
	 * @param bool                $is_msrp    Whether the user is an MSRP customer (no tier).
	 * @return bool
	 */
	private function user_matches_visibility_role( $user_id, array $role, array $user_roles, $is_msrp ) {
		// Explicit per-user targeting always matches, regardless of the roles rule.
		$users = isset( $role['users'] ) && is_array( $role['users'] ) ? array_map( 'intval', $role['users'] ) : array();
		if ( in_array( (int) $user_id, $users, true ) ) {
			return true;
		}

		$want = isset( $role['roles'] ) && is_array( $role['roles'] ) ? $role['roles'] : array();
		if ( empty( $want ) ) {
			return false;
		}
		$match = isset( $role['match'] ) && 'all' === $role['match'] ? 'all' : 'any';

		$hits = array();
		foreach ( $want as $slug ) {
			$hits[] = ( self::MSRP_CUSTOMER === $slug ) ? (bool) $is_msrp : in_array( $slug, $user_roles, true );
		}

		return 'all' === $match ? ! in_array( false, $hits, true ) : in_array( true, $hits, true );
	}

	/**
	 * Effective visibility lists for a user: the user's own include/exclude meta
	 * always overrides the visibility-role lists; the roles are the fallback when
	 * the user has no visibility settings of their own.
	 *
	 * @param int $user_id User ID.
	 * @return array{include:array<int,int>,exclude:array<int,int>}
	 */
	public function effective_visibility_categories( $user_id ) {
		$user = $this->visibility_categories( $user_id );
		if ( ! empty( $user['include'] ) || ! empty( $user['exclude'] ) ) {
			return $user;
		}
		return $this->visibility_role_categories( $user_id );
	}

	/**
	 * Whether a product is visible in the catalog to a user, per their effective
	 * visibility category lists. Mirrors WooHooks::filter_archive_visibility:
	 * managers see everything; an exclude list hides products in those categories;
	 * an include list limits the catalog to products in those categories.
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID (already resolved through any multiaccount parent).
	 * @return array{visible:bool,reason:string,categories:array<int,int>}
	 */
	public function product_visible_for_user( $product_id, $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && $this->user_is_manager( $user ) ) {
			return array( 'visible' => true, 'reason' => 'manager', 'categories' => array() );
		}

		// Per-product force-visible (by role or specific user) overrides Hide-visibility.
		if ( $this->user_force_visible( $product_id, $user_id ) ) {
			return array( 'visible' => true, 'reason' => 'forced_role', 'categories' => array() );
		}

		$cats    = $this->effective_visibility_categories( $user_id );
		$exclude = array_map( 'intval', $cats['exclude'] );
		$include = array_map( 'intval', $cats['include'] );

		if ( ! empty( $exclude ) && has_term( $exclude, 'product_cat', $product_id ) ) {
			return array( 'visible' => false, 'reason' => 'excluded', 'categories' => $exclude );
		}
		if ( ! empty( $include ) && ! has_term( $include, 'product_cat', $product_id ) ) {
			return array( 'visible' => false, 'reason' => 'not_in_include', 'categories' => $include );
		}
		if ( empty( $include ) && empty( $exclude ) ) {
			return array( 'visible' => true, 'reason' => 'unrestricted', 'categories' => array() );
		}
		return array( 'visible' => true, 'reason' => 'allowed', 'categories' => ! empty( $include ) ? $include : $exclude );
	}

	/**
	 * Resolve a configured user-meta key by its logical name.
	 *
	 * @param string $name Logical key name.
	 * @return string
	 */
	public function meta_key( $name ) {
		$keys = $this->config->user_meta_keys();
		return isset( $keys[ $name ] ) ? (string) $keys[ $name ] : '';
	}
}
