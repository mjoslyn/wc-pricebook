<?php
/**
 * The pricing resolution engine.
 *
 * All site-specific term IDs, role names, and multipliers come from {@see Config};
 * user/manager resolution from {@see Context}; product-behavior rules from
 * {@see Rules}.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves product prices for tiers and for the current/explicit user.
 */
class PriceEngine {

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
	 * Rule evaluator.
	 *
	 * @var Rules
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Config  $config  Plugin config.
	 * @param Context $context Pricing context.
	 * @param Rules   $rules   Rule evaluator.
	 */
	public function __construct( Config $config, Context $context, Rules $rules ) {
		$this->config  = $config;
		$this->context = $context;
		$this->rules   = $rules;
	}

	/**
	 * Price for a product as a specific named tier ("price as role").
	 *
	 * @param int                   $product_id Product ID.
	 * @param string                $tier_key   Tier key, or 'msrp'.
	 * @param bool                  $sale       Whether to resolve the sale price.
	 * @param array<string,bool>    $guard      Tiers already on the resolution chain (cycle guard).
	 * @return array{0:mixed,1:array<int,string>} [ price, history ]
	 */
	public function price_as_tier( $product_id, $tier_key, $sale = false, $guard = array() ) {
		$base     = $this->config->base_meta();
		$original = get_post_meta( $product_id, $base['regular'], true );
		$history  = array();

		if ( 'msrp' === $tier_key ) {
			if ( $sale ) {
				$sale_price = get_post_meta( $product_id, $base['sale'], true );
				if ( '' !== (string) $sale_price && $sale_price != $original ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					$history[] = $sale_price . ' - MSRP sale';
					return array( $sale_price, $history );
				}
				$history[] = 'No MSRP sale price set; using regular price';
				return array( $original, $history );
			}
			$history[] = $original . ' - MSRP';
			return array( $original, $history );
		}

		$tier = $this->config->tier( $tier_key );
		if ( null === $tier ) {
			if ( $sale ) {
				return array( '', array( 'Unknown tier: ' . $tier_key ) );
			}
			return array( $original, array( $original . ' - MSRP (unknown tier ' . $tier_key . ')' ) );
		}

		// Per-tier category scope: a tier only prices products inside its included
		// categories and never prices products in its excluded categories. Out of
		// scope the tier does not apply, so fall back as if it had no price.
		if ( ! $this->tier_in_category_scope( $product_id, $tier ) ) {
			$history[] = $tier_key . ' - out of tier category scope';
			if ( $sale ) {
				return array( '', $history );
			}
			return $this->tier_fallback( $product_id, $tier, $tier_key, $history, $original, $guard );
		}

		// no_tier_discount: a discounted tier (multiplier != 1) drops its discount and
		// is priced as its fallback role instead, carrying the sale flag.
		if (
			(float) $tier['multiplier'] !== 1.0 &&
			'' !== (string) $tier['fallback_to'] &&
			$tier['fallback_to'] !== $tier_key &&
			$this->rules->applies( 'no_tier_discount', $product_id )
		) {
			$history[] = "Rule no_tier_discount: {$tier_key} -> {$tier['fallback_to']}";
			$collapsed = $this->price_as_tier( $product_id, $tier['fallback_to'], $sale, $guard + array( $tier_key => true ) );
			return array( $collapsed[0], array_merge( $history, $collapsed[1] ) );
		}

		// Specific tier price wins (its own sale price when on sale).
		$specific_meta = $sale ? $tier['sale_meta'] : $tier['price_meta'];
		$specific      = '' !== $specific_meta ? get_post_meta( $product_id, $specific_meta, true ) : '';
		if ( '' !== (string) $specific ) {
			$history[] = $specific . ' - ' . $tier_key . ' specific price';
			return array( $specific, $history );
		}

		// On sale, but this tier has no sale price of its own. By default the tier is
		// priced at its regular price, but falls back to the product's MSRP sale price
		// when that is LOWER than the tier's regular price, so a storewide sale still
		// benefits tier customers. (e.g. MSRP 100 / MSRP sale 60 / operator 83, no
		// operator sale -> 60; if MSRP sale were 90 -> 83.) The
		// wc_pricebook_tier_requires_explicit_sale_price filter flips this off: when it
		// returns true a tier is "on sale" only via its own sale price and otherwise
		// always uses its regular price.
		if ( $sale ) {
			$regular = $this->price_as_tier( $product_id, $tier_key, false, $guard );
			$history = array_merge( $history, $regular[1] );

			/**
			 * Filters whether a tier must carry its own explicit sale price to be "on
			 * sale". Default false: a tier with no sale price falls back to the MSRP
			 * sale price when that is lower than the tier's regular price.
			 *
			 * @param bool   $require_explicit Whether an explicit tier sale price is required.
			 * @param string $tier_key         Tier key being resolved.
			 * @param int    $product_id       Product ID.
			 */
			$require_explicit = (bool) apply_filters( 'wc_pricebook_tier_requires_explicit_sale_price', false, $tier_key, $product_id );

			if ( ! $require_explicit && is_numeric( $regular[0] ) ) {
				$msrp_sale = get_post_meta( $product_id, $base['sale'], true );
				if ( is_numeric( $msrp_sale ) && (float) $msrp_sale > 0 && (float) $msrp_sale < (float) $regular[0] ) {
					$history[] = $msrp_sale . ' - MSRP sale (lower than ' . $tier_key . ' regular price)';
					return array( $msrp_sale, $history );
				}
			}

			$history[] = 'No ' . $tier_key . ' sale price; using regular tier price';
			return array( $regular[0], $history );
		}

		// Multiplier fallback from the base pricing role's price.
		$base_price = $this->base_role_price( $product_id, $tier['base_meta'], $guard + array( $tier_key => true ) );
		if ( is_numeric( $base_price ) ) {
			$calculated = (float) $base_price * (float) $tier['multiplier'];
			$base_role  = '' !== (string) $tier['base_meta'] ? $tier['base_meta'] : 'base';
			$history[]  = $calculated . ' - ' . $tier_key . ' calculated (' . $tier['multiplier'] . ' x ' . $base_price . ' of ' . $base_role . ')';
			return array( $calculated, $history );
		}

		// Chained fallback to another tier or MSRP.
		return $this->tier_fallback( $product_id, $tier, $tier_key, $history, $original, $guard );
	}

	/**
	 * The base pricing role's own defined price, used as a tier's multiplier base.
	 *
	 * Returns the base role's specific product price, or (failing that) its own
	 * multiplier computation off its base — but NOT its fallback_to. When the base
	 * role has no price of its own, '' is returned so the dependent tier falls back
	 * to its own fallback rather than discounting the base role's fallback (e.g.
	 * dealer_4 → MSRP, not MSRP x 0.96, when no dealer price exists). 'msrp' resolves
	 * to the base regular price; a layered/computed base (e.g. wholesale = 0.5 x
	 * MSRP) still chains because that is a defined multiplier price.
	 *
	 * @param int                $product_id Product ID.
	 * @param string             $role       Base pricing role ('', 'msrp', or a tier key).
	 * @param array<string,bool> $guard      Tiers already on the resolution chain.
	 * @return mixed
	 */
	private function base_role_price( $product_id, $role, array $guard ) {
		if ( '' === (string) $role ) {
			return '';
		}
		if ( 'msrp' === $role ) {
			$base = $this->config->base_meta();
			return isset( $base['regular'] ) ? get_post_meta( $product_id, $base['regular'], true ) : '';
		}
		if ( isset( $guard[ $role ] ) ) {
			return '';
		}
		$tier = $this->config->tier( $role );
		if ( null === $tier ) {
			return '';
		}
		$specific = '' !== $tier['price_meta'] ? get_post_meta( $product_id, $tier['price_meta'], true ) : '';
		if ( '' !== (string) $specific ) {
			return $specific;
		}
		$sub = $this->base_role_price( $product_id, $tier['base_meta'], $guard + array( $role => true ) );
		return is_numeric( $sub ) ? (float) $sub * (float) $tier['multiplier'] : '';
	}

	/**
	 * Whether a tier prices a product, per the tier's pricing-category scope.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $tier       Normalized tier.
	 * @return bool
	 */
	private function tier_in_category_scope( $product_id, array $tier ) {
		$set = isset( $tier['pricing_categories'] ) ? $tier['pricing_categories'] : array();
		return $this->category_set_matches( is_array( $set ) ? $set : array(), $product_id );
	}

	/**
	 * Whether a tier carries its own price for a product — its price_meta, or (on sale)
	 * its sale_meta or price_meta. Used by the 'when_priced' override so the tier wins
	 * only where it actually has a price, never via a fallback to another tier or MSRP.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $tier       Normalized tier.
	 * @param bool                $sale       Sale context.
	 * @return bool
	 */
	private function tier_has_own_price( $product_id, array $tier, $sale ) {
		if ( '' !== (string) $tier['price_meta'] && '' !== (string) get_post_meta( $product_id, $tier['price_meta'], true ) ) {
			return true;
		}
		if ( $sale && '' !== (string) $tier['sale_meta'] && '' !== (string) get_post_meta( $product_id, $tier['sale_meta'], true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a product matches a category-scope set { mode, categories }.
	 *
	 * mode 'all' (or an empty list) matches everything; 'include' requires the
	 * product to be in one of the categories; 'exclude' requires it not to be.
	 *
	 * @param array<string,mixed> $set        Category set.
	 * @param int                 $product_id Product ID.
	 * @return bool
	 */
	private function category_set_matches( array $set, $product_id ) {
		$mode       = isset( $set['mode'] ) ? (string) $set['mode'] : 'all';
		$categories = isset( $set['categories'] ) && is_array( $set['categories'] ) ? array_map( 'intval', $set['categories'] ) : array();

		if ( 'all' === $mode || empty( $categories ) ) {
			return true;
		}
		$in_categories = has_term( $categories, 'product_cat', $product_id );
		return 'include' === $mode ? $in_categories : ! $in_categories;
	}

	/**
	 * Resolve a tier's chained fallback (another tier or MSRP). Non-sale only —
	 * sale prices never imply a fallback price.
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $tier       Normalized tier.
	 * @param string              $tier_key   Tier key.
	 * @param array<int,string>   $history    Accumulated history.
	 * @param mixed               $original   MSRP regular price.
	 * @param array<string,bool>  $guard      Tiers already on the resolution chain.
	 * @return array{0:mixed,1:array<int,string>}
	 */
	private function tier_fallback( $product_id, array $tier, $tier_key, array $history, $original, array $guard = array() ) {
		if ( '' !== $tier['fallback_to'] && $tier['fallback_to'] !== $tier_key && ! isset( $guard[ $tier['fallback_to'] ] ) ) {
			if ( 'msrp' === $tier['fallback_to'] ) {
				$history[] = $original . ' - MSRP (fallback)';
				return array( $original, $history );
			}
			$chained = $this->price_as_tier( $product_id, $tier['fallback_to'], false, $guard + array( $tier_key => true ) );
			return array( $chained[0], array_merge( $history, $chained[1] ) );
		}

		$history[] = $original . ' - MSRP (fallback)';
		return array( $original, $history );
	}

	/**
	 * Price for a product for the current (or explicit) user, with full history.
	 *
	 * Resolution order: switcher override → per-user price override → call_for_price →
	 * Hide-Pricing visibility role → category-role mapping → skip_matrix → tier
	 * resolution (always > when_priced > lowest candidate).
	 *
	 * @param mixed       $price      Incoming WooCommerce price (may be filtered upstream).
	 * @param \WC_Product $product    Product object.
	 * @param int|null    $user_id    Specific user ID, or null for the current pricing user.
	 * @param bool        $sale       Whether to resolve the sale price.
	 * @return array{0:mixed,1:array<int,string>} [ price, history ]
	 */
	public function price_for_user( $price, $product, $user_id = null, $sale = false ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array( $price, array( 'Original price (invalid product)' ) );
		}

		$product_id    = $product->get_id();
		$explicit_user = ( null !== $user_id );
		if ( ! $explicit_user ) {
			$user_id = $this->context->pricing_user_id();
		} else {
			// An explicit user still resolves through multiaccounts to the parent.
			$user_id = $this->context->resolve_pricing_user_id( $user_id );
		}

		// Manager switcher override (only for the current-user context).
		if ( ! $explicit_user && $this->context->is_manager() ) {
			$switcher_role = $this->context->switcher_role();
			if ( '' !== $switcher_role && 'msrp' !== $switcher_role ) {
				return $this->price_as_tier( $product_id, $switcher_role, $sale );
			}
		}

		$base     = $this->config->base_meta();
		$original = get_post_meta( $product_id, $base['regular'], true );
		if ( $sale ) {
			$original = ( ! empty( $price ) && $price > 0 ) ? $price : get_post_meta( $product_id, $base['sale'], true );
		}
		$new_price = $original;
		$history   = array( $original . ' - ' . ( $sale ? 'SALE' : 'MSRP' ) );

		// Per-product, per-user override.
		$override = $this->user_override_price( $product_id, $user_id );
		if ( '' !== (string) $override ) {
			$history[] = $override . ' - per-user override (user ' . $user_id . ')';
			return array( $override, $history );
		}

		// call_for_price: a bound product has no public price — it resolves to empty so
		// WooCommerce renders the "Call for Price" label (woocommerce_empty_price_html).
		// A per-user negotiated override (above) still wins for that customer.
		if ( $this->rules->applies( 'call_for_price', $product_id ) ) {
			$history[] = ' - call_for_price rule (price hidden; shows the Call for Price label)';
			return array( '', $history );
		}

		// Per-product "force price visible" — the generic force_visible flag (everyone)
		// or the per-product force-price-roles (this user's role) — un-hides the price
		// for every hide mechanism below.
		$price_forced = $this->rules->applies( 'force_visible', $product_id )
			|| $this->context->user_force_price( $product_id, $user_id );

		// A visibility role with "Hide pricing" on, matching this user, whose categories
		// cover this product — the price is hidden (Call for Price) for this customer.
		if ( ! $price_forced && $this->context->price_hidden_by_visibility_role( $product_id, $user_id ) ) {
			$history[] = ' - hidden pricing via a visibility role (Call for Price)';
			return array( '', $history );
		}

		$cat_roles = $this->context->category_roles( $user_id );

		// skip_matrix: leave the incoming price untouched.
		if ( $this->rules->applies( 'skip_matrix', $product_id ) ) {
			$history[] = ' - skip_matrix rule (price unchanged)';
			return array( $price, $history );
		}

		// Category → role mapping (per-user).
		if ( is_array( $cat_roles ) && ! empty( $cat_roles ) ) {
			$role = $this->match_category_role( $product_id, $cat_roles );
			if ( 'msrp' === $role ) {
				$history[] = $original . ' - MSRP via user-level category-role mapping';
				return array( $original, $history );
			}
			if ( $role ) {
				$mapped = $this->price_as_tier( $product_id, $role, $sale );
				if ( '' !== (string) $mapped[0] ) {
					$history[] = $mapped[0] . ' - ' . $role . ' via user-level category-role mapping';
					return array( $mapped[0], $history );
				}
			}
		}

		// Tier membership: a user may belong to several tiers; the lowest resolved price
		// wins — UNLESS an overriding tier applies (see Config tier 'override'):
		//   'always'      — wins outright using its resolved price (fallback included).
		//   'when_priced' — wins only when it has its own price set for this product;
		//                   otherwise it is inert and the lowest-wins comparison runs.
		// An override only takes effect in the tier's category scope. When a user holds
		// both kinds for the same product, precedence is by TYPE: always > when_priced >
		// lowest candidate (config order does not decide it). Two tiers of the SAME
		// override type on one product is still order-dependent — avoid that.
		// Override precedence is by TYPE, not tier order: an 'always' override beats a
		// 'when_priced' override beats the lowest ordinary candidate. Track each kind
		// separately so config order never decides the winner (e.g. an operator
		// when_priced price must not beat a parts_dealer 'always' price on the parts
		// category just because operator is listed first).
		$best              = null;
		$forced_always     = null;
		$forced_always_key = '';
		$forced_when       = null;
		$forced_when_key   = '';
		foreach ( $this->config->tiers() as $key => $tier ) {
			if ( ! $this->context->user_has_tier( $user_id, $key ) ) {
				continue;
			}
			$override = isset( $tier['override'] ) ? (string) $tier['override'] : '';
			$in_scope = $this->tier_in_category_scope( $product_id, $tier );

			// A category-scoped tier only prices products inside its scope. Out of scope
			// it is INERT — it must not contribute its fallback (e.g. MSRP) as a candidate,
			// which would otherwise undercut the tiers that DO price this product (e.g. a
			// parts tier scoped to the parts category dragging a non-parts product to $0).
			if ( ! $in_scope ) {
				$history[] = $key . ' - out of tier category scope (skipped)';
				continue;
			}

			// 'when_priced': the tier participates only when it carries its own price for
			// this product; with no own price it is inert (no fallback, no candidate).
			if ( 'when_priced' === $override ) {
				if ( null === $forced_when && $this->tier_has_own_price( $product_id, $tier, $sale ) ) {
					$tier_price = $this->price_as_tier( $product_id, $key, $sale );
					if ( '' !== (string) $tier_price[0] && null !== $tier_price[0] ) {
						$history[]       = $tier_price[0] . ' - tier ' . $key . ' (override: when_priced)';
						$forced_when     = $tier_price[0];
						$forced_when_key = $key;
					}
				}
				continue;
			}

			$tier_price = $this->price_as_tier( $product_id, $key, $sale );
			if ( '' === (string) $tier_price[0] || null === $tier_price[0] ) {
				continue;
			}

			if ( 'always' === $override ) {
				if ( null === $forced_always ) {
					$history[]         = $tier_price[0] . ' - tier ' . $key . ' (override: always)';
					$forced_always     = $tier_price[0];
					$forced_always_key = $key;
				}
				continue;
			}

			$history[] = $tier_price[0] . ' - tier ' . $key . ' (candidate)';
			if ( null === $best || (float) $tier_price[0] < (float) $best ) {
				$best = $tier_price[0];
			}
		}
		if ( null !== $forced_always ) {
			$new_price = $forced_always;
			$history[] = $forced_always . ' - override price (tier ' . $forced_always_key . ', always)';
		} elseif ( null !== $forced_when ) {
			$new_price = $forced_when;
			$history[] = $forced_when . ' - override price (tier ' . $forced_when_key . ', when priced)';
		} elseif ( null !== $best ) {
			$new_price = $best;
			$history[] = $best . ' - lowest tier price';
		}

		return array( $new_price, $history );
	}

	/**
	 * Resolve the simple effective price WooCommerce should display (lowest of
	 * regular vs sale), for the current/explicit user.
	 *
	 * @param mixed       $price   Incoming price.
	 * @param \WC_Product $product Product.
	 * @param int|null    $user_id Optional explicit user.
	 * @return mixed
	 */
	public function effective_price( $price, $product, $user_id = null ) {
		$regular = $this->price_for_user( $price, $product, $user_id, false );
		$sale    = $this->price_for_user( $price, $product, $user_id, true );

		if ( '' === (string) $sale[0] || null === $sale[0] ) {
			return $regular[0];
		}
		if ( (float) $sale[0] < (float) $regular[0] ) {
			return $sale[0];
		}
		return $regular[0];
	}

	/**
	 * Effective per-unit price for a product at a given quantity, honoring per-role
	 * bulk (quantity-break) pricing. Below the lowest break, or when the user is not
	 * priced as a tier, this is just {@see self::effective_price}. A break price only
	 * applies when it improves on the normal price.
	 *
	 * @param mixed       $price   Incoming price (passed through to effective_price).
	 * @param \WC_Product $product Product.
	 * @param int         $qty     Line quantity.
	 * @param int|null    $user_id Optional explicit user.
	 * @return mixed
	 */
	public function effective_price_qty( $price, $product, $qty, $user_id = null ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $price;
		}
		$explicit = ( null !== $user_id );
		$normal   = $this->effective_price( $price, $product, $explicit ? $user_id : null );

		$qty = (int) $qty;
		if ( $qty <= 1 ) {
			return $normal;
		}

		$resolved_user = $explicit ? $this->context->resolve_pricing_user_id( $user_id ) : $this->context->pricing_user_id();

		// A customer-specific price is a fixed, negotiated price and wins over
		// quantity-break pricing at any quantity.
		if ( '' !== (string) $this->user_override_price( $product->get_id(), $resolved_user ) ) {
			return $normal;
		}

		$keys = $this->bulk_matching_keys( $product->get_id(), $resolved_user, $explicit );

		// Lowest bulk price across every role the user is priced under.
		$bulk = '';
		foreach ( $keys as $key ) {
			$candidate = $this->bulk_break_price( $product->get_id(), $key, $qty );
			if ( '' === (string) $candidate ) {
				continue;
			}
			if ( '' === $bulk || (float) $candidate < (float) $bulk ) {
				$bulk = (string) $candidate;
			}
		}
		if ( '' === $bulk ) {
			return $normal;
		}
		if ( '' === (string) $normal ) {
			return $bulk;
		}
		return (float) $bulk < (float) $normal ? $bulk : $normal;
	}

	/**
	 * The lowest achievable per-unit price for a product given the user's bulk breaks,
	 * for a "From $X" catalog treatment. Empty string when no break beats the user's
	 * normal price (so callers can leave the price untouched).
	 *
	 * @param mixed       $price   Incoming price (passed through to effective_price).
	 * @param \WC_Product $product Product.
	 * @param int|null    $user_id Optional explicit user.
	 * @return string
	 */
	public function from_price( $price, $product, $user_id = null ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return '';
		}
		$explicit      = ( null !== $user_id );
		$resolved_user = $explicit ? $this->context->resolve_pricing_user_id( $user_id ) : $this->context->pricing_user_id();

		// A customer-specific price is fixed, so there is no "from" bulk teaser.
		if ( '' !== (string) $this->user_override_price( $product->get_id(), $resolved_user ) ) {
			return '';
		}

		$keys = $this->bulk_matching_keys( $product->get_id(), $resolved_user, $explicit );

		$min = '';
		foreach ( $keys as $key ) {
			foreach ( $this->bulk_breaks( $product->get_id(), $key ) as $row ) {
				if ( '' === $min || (float) $row['price'] < (float) $min ) {
					$min = (string) $row['price'];
				}
			}
		}
		if ( '' === $min ) {
			return '';
		}

		$normal = $this->effective_price( $price, $product, $explicit ? $user_id : null );
		if ( '' !== (string) $normal && (float) $min >= (float) $normal ) {
			return '';
		}
		return $min;
	}

	/**
	 * The bulk-pricing meta keys whose quantity breaks apply to a user for a product.
	 *
	 * Bulk breaks can target any WP role or the synthetic "MSRP Customer", not only
	 * configured pricing tiers. This returns the pricing tiers the user is priced under
	 * (via {@see self::resolved_tier_keys}, preserving switcher/category-role behavior)
	 * PLUS any other bulk-meta keys the user matches by role membership.
	 *
	 * @param int  $product_id Product ID.
	 * @param int  $user_id    Resolved pricing user ID.
	 * @param bool $explicit   Whether the user was passed explicitly (no switcher).
	 * @return array<int,string> Role/tier keys.
	 */
	private function bulk_matching_keys( $product_id, $user_id, $explicit ) {
		$keys = $this->resolved_tier_keys( $product_id, $user_id, $explicit );

		// A manager previewing as a tier via the switcher sees only that tier's breaks.
		if ( ! $explicit && $this->context->is_manager() ) {
			$switcher = $this->context->switcher_role();
			if ( '' !== $switcher && 'msrp' !== $switcher ) {
				return $keys;
			}
		}

		// Add any role-targeted breaks (WP roles / MSRP Customer) the user matches.
		$meta_key = $this->config->bulk_pricing_meta();
		$all      = '' !== $meta_key ? get_post_meta( $product_id, $meta_key, true ) : array();
		if ( is_array( $all ) ) {
			foreach ( array_keys( $all ) as $key ) {
				$key = (string) $key;
				if ( ! in_array( $key, $keys, true ) && $this->context->user_in_role_set( $user_id, array( $key ) ) ) {
					$keys[] = $key;
				}
			}
		}
		return $keys;
	}

	/**
	 * The tier keys a user is priced under for a product, for bulk-pricing lookup.
	 * Follows the precedence {@see self::price_for_user} uses: a manager switcher
	 * override or a category→role mapping each resolve to a single role; otherwise
	 * every tier the user is a member of is returned (lowest price wins downstream).
	 * Returns an empty array for MSRP/no tier. Rules that bypass or hide pricing
	 * (skip_matrix, price_requires_tier) are intentionally not considered here.
	 *
	 * @param int  $product_id Product ID.
	 * @param int  $user_id    User ID.
	 * @param bool $explicit   Whether the user was passed explicitly (no switcher).
	 * @return array<int,string> Tier keys.
	 */
	private function resolved_tier_keys( $product_id, $user_id, $explicit ) {
		if ( ! $explicit && $this->context->is_manager() ) {
			$switcher = $this->context->switcher_role();
			if ( '' !== $switcher && 'msrp' !== $switcher ) {
				return array( $switcher );
			}
		}

		$cat_roles = $this->context->category_roles( $user_id );
		if ( is_array( $cat_roles ) && ! empty( $cat_roles ) ) {
			$role = $this->match_category_role( $product_id, $cat_roles );
			if ( 'msrp' === $role ) {
				return array();
			}
			if ( $role && '' !== (string) $this->price_as_tier( $product_id, $role, false )[0] ) {
				return array( $role );
			}
		}

		$keys = array();
		foreach ( $this->config->tiers() as $key => $tier ) {
			if ( ! $this->context->user_has_tier( $user_id, $key ) ) {
				continue;
			}
			$tier_price = $this->price_as_tier( $product_id, $key, false );
			if ( '' !== (string) $tier_price[0] && null !== $tier_price[0] ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * The bulk price for a tier at a quantity: the price of the highest min-quantity
	 * break whose threshold is met. Empty string when no break applies.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $tier_key   Tier key.
	 * @param int    $qty        Quantity.
	 * @return string
	 */
	private function bulk_break_price( $product_id, $tier_key, $qty ) {
		$qty        = (int) $qty;
		$best_min   = -1;
		$best_price = '';
		foreach ( $this->bulk_breaks( $product_id, $tier_key ) as $row ) {
			$min = (int) $row['min_qty'];
			$max = (int) $row['max_qty'];
			$in_range = $min <= $qty && ( 0 === $max || $qty <= $max );
			// On overlap the most specific (highest) lower bound wins.
			if ( $in_range && $min > $best_min ) {
				$best_min   = $min;
				$best_price = (string) $row['price'];
			}
		}
		return $best_price;
	}

	/**
	 * Normalized, ascending-by-quantity bulk break rows for a product/tier, each a
	 * { min_qty, max_qty, price } range (max_qty 0 means unbounded/"and up"). Rows
	 * without a positive minimum, without a price, or with a max below the min are
	 * dropped. Public so the bulk-table shortcode can render the same data the cart
	 * prices from.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $tier_key   Tier key.
	 * @return array<int,array{min_qty:int,max_qty:int,price:string}>
	 */
	public function bulk_breaks( $product_id, $tier_key ) {
		$meta_key = $this->config->bulk_pricing_meta();
		if ( '' === $meta_key ) {
			return array();
		}
		$all = get_post_meta( $product_id, $meta_key, true );
		if ( ! is_array( $all ) || empty( $all[ $tier_key ] ) || ! is_array( $all[ $tier_key ] ) ) {
			return array();
		}

		$rows = array();
		foreach ( $all[ $tier_key ] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['min_qty'], $row['price'] ) ) {
				continue;
			}
			$min = (int) $row['min_qty'];
			$max = isset( $row['max_qty'] ) ? (int) $row['max_qty'] : 0;
			if ( $min < 1 || '' === (string) $row['price'] ) {
				continue;
			}
			if ( $max > 0 && $max < $min ) {
				continue;
			}
			$rows[] = array( 'min_qty' => $min, 'max_qty' => $max, 'price' => (string) $row['price'] );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['min_qty'] <=> $b['min_qty'];
			}
		);
		return $rows;
	}

	/**
	 * Whether a user has a customer-specific override price for a product. Defaults to
	 * the current pricing user. Lets callers (e.g. the bulk-pricing table) hide
	 * quantity pricing that the override would supersede anyway.
	 *
	 * @param int      $product_id Product ID.
	 * @param int|null $user_id    User ID, or null for the current pricing user.
	 * @return bool
	 */
	public function user_has_override( $product_id, $user_id = null ) {
		$user_id = ( null === $user_id ) ? $this->context->pricing_user_id() : $this->context->resolve_pricing_user_id( $user_id );
		return '' !== (string) $this->user_override_price( $product_id, $user_id );
	}

	/**
	 * A customer-specific override price for a product/user, or '' when none.
	 *
	 * @param int $product_id Product ID.
	 * @param int $user_id    User ID.
	 * @return string
	 */
	private function user_override_price( $product_id, $user_id ) {
		$meta_key = $this->config->user_pricing_meta();
		if ( '' === $meta_key ) {
			return '';
		}
		$rows = get_post_meta( $product_id, $meta_key, true );
		if ( ! is_array( $rows ) ) {
			return '';
		}
		foreach ( $rows as $row ) {
			if ( isset( $row['user-id'], $row['price'] ) && (int) $row['user-id'] === (int) $user_id ) {
				return (string) $row['price'];
			}
		}
		return '';
	}

	/**
	 * Match a product's categories against a user's category→role mappings.
	 *
	 * @param int                            $product_id Product ID.
	 * @param array<int,array<string,mixed>> $mappings   Category role mappings.
	 * @return string|null Tier/role key or null.
	 */
	private function match_category_role( $product_id, $mappings ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return null;
		}
		$role = null;
		foreach ( $mappings as $mapping ) {
			if ( empty( $mapping['product-category'] ) || ! isset( $mapping['pricing-role'] ) ) {
				continue;
			}
			$cat_id = (int) ( is_array( $mapping['product-category'] ) ? $mapping['product-category'][0] : $mapping['product-category'] );
			if ( in_array( $cat_id, array_map( 'intval', $terms ), true ) ) {
				$role = $mapping['pricing-role'];
			}
		}
		return $role;
	}
}
