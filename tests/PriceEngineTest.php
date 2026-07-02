<?php
/**
 * Engine tests: tier resolution, rules, per-user pricing, fallbacks.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

/**
 * @covers \WCPricebook\PriceEngine
 */
class PriceEngineTest extends TestCase {

	public function test_msrp_regular_returns_regular_price() {
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$result = $this->engine->price_as_tier( 10, 'msrp', false );
		$this->assertSame( '100', $result[0] );
	}

	public function test_msrp_sale_returns_sale_when_lower_and_set() {
		$this->set_meta( 10, array( '_regular_price' => '100', '_sale_price' => '80' ) );
		$this->assertSame( '80', $this->engine->price_as_tier( 10, 'msrp', true )[0] );
	}

	public function test_msrp_sale_uses_regular_when_no_sale_price() {
		// No MSRP sale price -> not on sale -> regular MSRP.
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'msrp', true )[0] );
	}

	public function test_tier_falls_back_to_msrp_sale_when_lower_than_tier_regular() {
		// MSRP 100, MSRP sale 60, operator 83, no operator sale -> 60 (MSRP sale is
		// lower than the operator regular price, so the storewide sale wins).
		$this->set_meta( 10, array( '_regular_price' => '100', '_sale_price' => '60', 'operator_price' => '83' ) );
		$this->assertSame( '60', $this->engine->price_as_tier( 10, 'operator', true )[0] );
	}

	public function test_tier_uses_regular_when_msrp_sale_not_lower() {
		// MSRP 100, MSRP sale 90, dealer 70, no dealer sale -> 70 (dealer regular is
		// already lower than the MSRP sale, so no fallback).
		$this->set_meta( 10, array( '_regular_price' => '100', '_sale_price' => '90', 'dealer_price' => '70' ) );
		$this->assertSame( '70', $this->engine->price_as_tier( 10, 'dealer', true )[0] );
	}

	public function test_tier_uses_its_own_sale_price_when_set() {
		// operator sale 50 -> operator gets 50.
		$this->set_meta( 10, array( '_regular_price' => '100', '_sale_price' => '60', 'operator_price' => '83', 'operator_sale_price' => '50' ) );
		$this->assertSame( '50', $this->engine->price_as_tier( 10, 'operator', true )[0] );
	}

	public function test_require_explicit_sale_price_filter_disables_msrp_fallback() {
		// With the filter on, a tier with no sale price uses its regular price and
		// ignores the (lower) MSRP sale price.
		add_filter(
			'wc_pricebook_tier_requires_explicit_sale_price',
			static function () {
				return true;
			}
		);
		$this->set_meta( 10, array( '_regular_price' => '100', '_sale_price' => '60', 'operator_price' => '83' ) );
		$this->assertSame( '83', $this->engine->price_as_tier( 10, 'operator', true )[0] );
	}

	public function test_dealer_specific_price_wins() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->assertSame( '70', $this->engine->price_as_tier( 10, 'dealer', false )[0] );
	}

	public function test_tier_specific_price_used_when_set() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'dealer_4_price' => '65' ) );
		$this->assertSame( '65', $this->engine->price_as_tier( 10, 'dealer_4', false )[0] );
	}

	public function test_tier_multiplier_fallback_from_base_meta() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '100' ) );
		// dealer_4 = 100 * 0.96.
		$this->assertEqualsWithDelta( 96.0, (float) $this->engine->price_as_tier( 10, 'dealer_4', false )[0], 0.001 );
	}

	public function test_no_tier_discount_rule_uses_fallback_price() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'dealer_4_price' => '60' ) );
		$this->set_terms( 10, 'product_flag', array( 363 ) );
		// With the rule, dealer_4 drops its discount and uses its fallback_to (msrp) = 100, not 60.
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'dealer_4', false )[0] );
	}

	public function test_no_tier_discount_via_per_product_flag() {
		// The rule triggers from the per-product checkbox meta, with no taxonomy term.
		$this->set_meta(
			10,
			array(
				'_regular_price' => '100',
				'dealer_price'   => '70',
				'dealer_4_price' => '60',
				\WCPricebook\Rules::flag_meta_key( 'no_tier_discount' ) => '1',
			)
		);
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'dealer_4', false )[0] );
	}

	public function test_operator_falls_back_to_msrp_when_no_operator_price() {
		// Operator pricing: operator_price when set, otherwise MSRP (not the dealer price).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'operator', false )[0] );
	}

	public function test_operator_override_inert_uses_dealer_when_no_operator_price() {
		// operator+dealer, no operator price -> operator override (when_priced) is inert,
		// so the dealer tier resolves -> dealer price (case 2).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 5, array( 'operator', 'dealer' ), array( 'operator', 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '70', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_operator_override_wins_when_priced_even_if_higher() {
		// operator price 90 > dealer 70: operator override is set, so it wins outright.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'operator_price' => '90' ) );
		Store::add_user( 5, array( 'operator', 'dealer' ), array( 'operator', 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '90', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_operator_override_keeps_dealer_n_discount_when_no_op_price() {
		// operator+dealer+dealer_4 combo, NO operator price -> operator inert, so the
		// dealer_4 discount stands (0.96 x dealer 70 = 67.2), not the full dealer price.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 5, array( 'operator', 'dealer', 'dealer_4' ), array( 'operator', 'dealer', 'dealer_4' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '67.2', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_operator_override_beats_dealer_n_when_priced() {
		// Same combo but WITH an operator price 90 -> operator override wins over the
		// cheaper dealer_4 discount (67.2).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'operator_price' => '90' ) );
		Store::add_user( 5, array( 'operator', 'dealer', 'dealer_4' ), array( 'operator', 'dealer', 'dealer_4' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '90', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_operator_only_user_gets_msrp_when_no_operator_price() {
		// operator with no dealer role, no operator price -> override inert -> MSRP (case 1).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 5, array( 'operator' ), array( 'operator' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '100', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_unknown_tier_falls_back_to_msrp() {
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'nope', false )[0] );
	}

	public function test_tier_multiplier_off_msrp_base_role() {
		// "half" has no stored price; it is MSRP x 0.5.
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->assertEqualsWithDelta( 50.0, (float) $this->engine->price_as_tier( 10, 'half', false )[0], 0.001 );
	}

	public function test_tier_based_off_non_msrp_tier_chains_resolved_price() {
		// "quarter" = 0.5 x the resolved "half" price (50) = 25, even though "half"
		// itself has no stored price (it is computed off MSRP).
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->assertEqualsWithDelta( 25.0, (float) $this->engine->price_as_tier( 10, 'quarter', false )[0], 0.001 );
	}

	public function test_tier_without_explicit_price_meta_uses_derived_key() {
		// The "plain" tier has no price_meta, so a manual price lives under the
		// derived _pricebook_plain_price key (what the product meta box writes).
		$this->set_meta( 10, array( '_regular_price' => '100', '_pricebook_plain_price' => '55' ) );
		$this->assertSame( '55', $this->engine->price_as_tier( 10, 'plain', false )[0] );
	}

	public function test_include_mode_tier_prices_product_in_category() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'scoped_price' => '70' ) );
		$this->set_terms( 10, 'product_cat', array( 555 ) );
		$this->assertSame( '70', $this->engine->price_as_tier( 10, 'scoped_inc', false )[0] );
	}

	public function test_include_mode_tier_falls_back_when_product_not_in_category() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'scoped_price' => '70' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		// Out of the tier's include scope → falls back to MSRP, ignoring scoped_price.
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'scoped_inc', false )[0] );
	}

	public function test_exclude_mode_tier_falls_back_for_product_in_category() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'scoped2_price' => '80' ) );
		$this->set_terms( 10, 'product_cat', array( 666 ) );
		// In the excluded category → falls back to MSRP, ignoring scoped2_price.
		$this->assertSame( '100', $this->engine->price_as_tier( 10, 'scoped_exc', false )[0] );
	}

	public function test_exclude_mode_tier_prices_product_outside_category() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'scoped2_price' => '80' ) );
		$this->set_terms( 10, 'product_cat', array( 555 ) );
		$this->assertSame( '80', $this->engine->price_as_tier( 10, 'scoped_exc', false )[0] );
	}

	public function test_price_for_user_applies_tier_membership() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_multiple_tiers_lowest_price_wins() {
		// User belongs to both dealer (70) and dealer_4 (80); the lower price applies,
		// regardless of tier order.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'dealer_4_price' => '80' ) );
		Store::add_user( 5, array( 'dealer', 'dealer_4' ), array( 'dealer', 'dealer_4' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_forced_tier_overrides_lower_tier_in_scope() {
		// In the forced tier's category, forced_dealer (dealer price 70) wins even
		// though the user's dealer_4 tier (50) is cheaper — override:'always' beats lowest-wins.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'dealer_4_price' => '50' ) );
		$this->set_terms( 10, 'product_cat', array( 777 ) );
		Store::add_user( 5, array( 'forced_dealer', 'dealer_4' ), array( 'forced_dealer', 'dealer_4' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_forced_tier_does_not_apply_out_of_scope() {
		// Same user on a product outside the forced tier's category: override does not
		// kick in, so normal lowest-wins applies (dealer_4 50).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'dealer_4_price' => '50' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 5, array( 'forced_dealer', 'dealer_4' ), array( 'forced_dealer', 'dealer_4' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '50', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_forced_msrp_tier_suppresses_discount_in_scope() {
		// parts_retail style: a forced tier that resolves to MSRP pins MSRP (100) in
		// its category, overriding the user's dealer discount (70).
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->set_terms( 10, 'product_cat', array( 777 ) );
		Store::add_user( 5, array( 'forced_msrp', 'dealer' ), array( 'forced_msrp', 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '100', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_multiple_roles_lowest_price_wins() {
		// Membership is role-based (capabilities). Operator override (when_priced) wins
		// when it has a price: operator 60 beats dealer 70.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'operator_price' => '60' ) );
		Store::add_user( 7, array( 'dealer', 'operator' ), array( 'dealer', 'operator' ) );
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '60', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_multiple_tiers_lowest_price_wins_other_order() {
		// Same membership, but now operator is the cheaper tier.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70', 'operator_price' => '60' ) );
		Store::add_user( 5, array( 'dealer', 'operator' ), array( 'dealer', 'operator' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '60', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_multiple_tiers_lowest_bulk_break_wins() {
		// Both tiers have a 10+ break; the cheaper break wins at quantity.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'dealer_4_price'          => '80',
				'_pricebook_bulk_pricing' => array(
					'dealer'   => array( array( 'min_qty' => 10, 'price' => '65' ) ),
					'dealer_4' => array( array( 'min_qty' => 10, 'price' => '62' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer', 'dealer_4' ), array( 'dealer', 'dealer_4' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		// qty 1: lowest normal tier price (dealer 70). qty 10: lowest break (dealer_4 62).
		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 1 ) );
		$this->assertSame( '62', (string) $this->engine->effective_price_qty( null, $product, 10 ) );
	}

	public function test_bulk_pricing_targets_a_wp_role_not_only_tiers() {
		// A bulk break keyed by a plain WP role ('club') that is not a configured pricing
		// tier applies to members of that role, and only to them.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'_pricebook_bulk_pricing' => array(
					'club' => array( array( 'min_qty' => 5, 'price' => '80' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'club' ) );     // in the role, no pricing tier.
		Store::add_user( 6, array( 'customer' ) ); // not in the role.
		$product = new FakeProduct( 10 );

		// Member: below the break sees MSRP, at/above sees the role break.
		$this->assertSame( '100', (string) $this->engine->effective_price_qty( null, $product, 1, 5 ) );
		$this->assertSame( '80', (string) $this->engine->effective_price_qty( null, $product, 5, 5 ) );
		// Non-member: never gets the role break.
		$this->assertSame( '100', (string) $this->engine->effective_price_qty( null, $product, 5, 6 ) );
	}

	public function test_from_price_uses_lowest_break_across_roles() {
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'operator_price'          => '80',
				'_pricebook_bulk_pricing' => array(
					'dealer'   => array( array( 'min_qty' => 10, 'price' => '65' ) ),
					'operator' => array( array( 'min_qty' => 50, 'price' => '58' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer', 'operator' ), array( 'dealer', 'operator' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '58', (string) $this->engine->from_price( null, $product ) );
	}

	public function test_price_for_user_respects_per_user_override() {
		$this->set_meta(
			10,
			array(
				'_regular_price'         => '100',
				'dealer_price'           => '70',
				'_pricebook_user_pricing' => array( array( 'user-id' => 5, 'price' => '42' ) ),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '42', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_per_user_override_reads_remapped_meta_key() {
		// A store can point the override reader at its own product meta key via the
		// user_pricing_meta config (e.g. repointing it at a legacy 'user-pricing' key).
		Store::$options[ \WCPricebook\Config::OPTION ]['user_pricing_meta'] = 'user-pricing';
		$this->config = new \WCPricebook\Config();
		$this->context = new \WCPricebook\Context( $this->config );
		$this->engine  = new \WCPricebook\PriceEngine( $this->config, $this->context, new \WCPricebook\Rules( $this->config ) );

		$this->set_meta(
			10,
			array(
				'_regular_price' => '100',
				'dealer_price'   => '70',
				'user-pricing'   => array( array( 'user-id' => 5, 'price' => '42' ) ),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '42', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_per_user_override_only_applies_to_matching_user() {
		// Override targets user 5; user 6 (also a dealer) gets the ordinary tier price.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'_pricebook_user_pricing' => array( array( 'user-id' => 5, 'price' => '42' ) ),
			)
		);
		Store::add_user( 6, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 6;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_per_user_override_resolves_for_explicit_user_id() {
		// No current user; the override is selected by the explicit $user_id argument.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'_pricebook_user_pricing' => array(
					array( 'user-id' => 5, 'price' => '42' ),
					array( 'user-id' => 8, 'price' => '33' ),
				),
			)
		);

		$product = new FakeProduct( 10 );
		$this->assertSame( '33', $this->engine->price_for_user( null, $product, 8, false )[0] );
	}

	public function test_per_user_override_wins_over_price_requires_tier_hiding() {
		// price_requires_tier would hide the price from a user with no tier, but a
		// per-user override is resolved earlier and still applies.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'_pricebook_user_pricing' => array( array( 'user-id' => 7, 'price' => '25' ) ),
			)
		);
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 7 ); // no tier caps.
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '25', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	/**
	 * A dealer with quantity breaks 10→60, 50→55 on a product whose plain dealer
	 * price is 70.
	 *
	 * @return FakeProduct Product id 10.
	 */
	private function seed_bulk_dealer() {
		$this->set_meta(
			10,
			array(
				'_regular_price'           => '100',
				'dealer_price'             => '70',
				'_pricebook_bulk_pricing'  => array(
					'dealer' => array(
						array( 'min_qty' => 10, 'price' => '60' ),
						array( 'min_qty' => 50, 'price' => '55' ),
					),
				),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		return new FakeProduct( 10 );
	}

	public function test_bulk_below_first_break_uses_normal_price() {
		$product = $this->seed_bulk_dealer();
		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 5 ) );
		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 1 ) );
	}

	public function test_bulk_applies_break_at_threshold() {
		$product = $this->seed_bulk_dealer();
		$this->assertSame( '60', (string) $this->engine->effective_price_qty( null, $product, 10 ) );
		$this->assertSame( '60', (string) $this->engine->effective_price_qty( null, $product, 49 ) );
		$this->assertSame( '55', (string) $this->engine->effective_price_qty( null, $product, 50 ) );
		$this->assertSame( '55', (string) $this->engine->effective_price_qty( null, $product, 999 ) );
	}

	public function test_bulk_ignored_for_user_without_that_tier() {
		$this->seed_bulk_dealer();
		// User 7 has no tier: priced at MSRP, and bulk (a per-role table) never applies.
		Store::add_user( 7 );
		Store::$current_user = 7;
		$product = new FakeProduct( 10 );
		$this->assertSame( '100', (string) $this->engine->effective_price_qty( null, $product, 50 ) );
	}

	public function test_bulk_only_applies_when_cheaper_than_normal() {
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				// Break is more expensive than the dealer's normal price: ignored.
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'price' => '80' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 20 ) );
	}

	public function test_bulk_breaks_normalizes_and_sorts() {
		$this->set_meta(
			10,
			array(
				'_pricebook_bulk_pricing' => array(
					'dealer' => array(
						array( 'min_qty' => 50, 'price' => '55' ),                  // no max → unbounded.
						array( 'min_qty' => 0, 'price' => '99' ),                   // invalid qty, dropped.
						array( 'min_qty' => 10, 'price' => '' ),                    // empty price, dropped.
						array( 'min_qty' => 5, 'max_qty' => 2, 'price' => '40' ),   // inverted range, dropped.
						'garbage',                                                  // not an array, dropped.
						array( 'min_qty' => 10, 'max_qty' => 49, 'price' => '60' ),
					),
				),
			)
		);
		$this->assertSame(
			array(
				array( 'min_qty' => 10, 'max_qty' => 49, 'price' => '60' ),
				array( 'min_qty' => 50, 'max_qty' => 0, 'price' => '55' ),
			),
			$this->engine->bulk_breaks( 10, 'dealer' )
		);
	}

	public function test_bulk_range_with_max_leaves_gap_at_normal_price() {
		// A single bounded range 10–20; quantities outside it pay the normal price.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'max_qty' => 20, 'price' => '60' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );

		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 9 ) );
		$this->assertSame( '60', (string) $this->engine->effective_price_qty( null, $product, 10 ) );
		$this->assertSame( '60', (string) $this->engine->effective_price_qty( null, $product, 20 ) );
		// Above the range max, with no further break: back to the normal price.
		$this->assertSame( '70', (string) $this->engine->effective_price_qty( null, $product, 21 ) );
	}

	public function test_from_price_returns_lowest_break_when_cheaper() {
		$product = $this->seed_bulk_dealer();
		$this->assertSame( '55', (string) $this->engine->from_price( null, $product ) );
	}

	public function test_from_price_empty_for_non_tier_user() {
		$this->seed_bulk_dealer();
		Store::add_user( 7 );
		Store::$current_user = 7;
		$product = new FakeProduct( 10 );
		$this->assertSame( '', (string) $this->engine->from_price( null, $product ) );
	}

	public function test_from_price_empty_when_no_break_beats_normal() {
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'price' => '80' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );
		$this->assertSame( '', (string) $this->engine->from_price( null, $product ) );
	}

	public function test_manager_previewing_as_user_sees_that_users_price() {
		// Manager (9) impersonating dealer customer (5) sees the dealer price, not MSRP
		// and not a switcher-role override.
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_user'] = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_user_override_wins_over_bulk_pricing() {
		// Customer 5 has both a negotiated custom price and a tier with a cheaper bulk
		// break. The fixed custom price wins at every quantity.
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				'_pricebook_user_pricing' => array( array( 'user-id' => 5, 'price' => '42' ) ),
				'_pricebook_bulk_pricing' => array(
					'dealer' => array( array( 'min_qty' => 10, 'price' => '30' ) ),
				),
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		$product = new FakeProduct( 10 );

		$this->assertSame( '42', (string) $this->engine->effective_price_qty( null, $product, 1 ) );
		// Quantity 50 would hit the $30 break, but the custom price still wins.
		$this->assertSame( '42', (string) $this->engine->effective_price_qty( null, $product, 50 ) );
		// And no "From <lower>" teaser is shown for a customer with a fixed price.
		$this->assertSame( '', (string) $this->engine->from_price( null, $product ) );
	}

	public function test_explicit_user_resolves_to_parent_for_pricing() {
		$this->set_meta(
			10,
			array(
				'_regular_price'          => '100',
				'dealer_price'            => '70',
				// Override is on the PARENT account, not the child.
				'_pricebook_user_pricing' => array( array( 'user-id' => 3, 'price' => '42' ) ),
			)
		);
		Store::add_user( 5, array( 'customer' ) );                  // child: no tier of its own.
		Store::add_user( 3, array( 'dealer' ), array( 'dealer' ) ); // parent: dealer.
		add_filter(
			'wc_pricebook_pricing_user',
			static function ( $user ) {
				return ( (int) $user->ID === 5 ) ? get_user_by( 'id', 3 ) : $user;
			}
		);
		$product = new FakeProduct( 10 );

		// Explicit child 5 is priced as parent 3 — so the parent's override applies.
		$this->assertSame( '42', (string) $this->engine->price_for_user( null, $product, 5, false )[0] );
		// And the parent's override is detected for the child via resolution.
		$this->assertTrue( $this->engine->user_has_override( 10, 5 ) );
	}

	public function test_user_has_override_detects_customer_price() {
		$this->set_meta(
			10,
			array( '_pricebook_user_pricing' => array( array( 'user-id' => 5, 'price' => '42' ) ) )
		);
		$this->assertTrue( $this->engine->user_has_override( 10, 5 ) );
		$this->assertFalse( $this->engine->user_has_override( 10, 6 ) );
	}

	public function test_skip_matrix_rule_returns_incoming_price() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->set_terms( 10, 'product_flag', array( 478 ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( 123, $this->engine->price_for_user( 123, $product, null, false )[0] );
	}

	public function test_price_requires_tier_hides_price_from_non_tier_user() {
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 7 ); // no tier caps.
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_price_requires_tier_visible_to_tier_user() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_gate_does_not_hide_from_a_tier_holder() {
		// The gate hides pricing only from MSRP customers (no tier). A user who holds ANY
		// tier — even one out of scope for this product — is not an MSRP customer, so the
		// price shows (here MSRP, since scoped_inc is out of scope on cat 999).
		$this->set_meta( 10, array( '_regular_price' => '100', 'scoped_price' => '80' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 5, array( 'scoped_inc' ), array( 'scoped_inc' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '100', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_gate_hides_price_from_msrp_customer() {
		// A user with no tier (MSRP customer) sees "Call for Price" (empty) on a gated
		// category — the visibility-role replacement for price_requires_tier.
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 7 ); // no tier.
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_always_override_beats_when_priced_override_regardless_of_order() {
		// A user holds operator (when_priced, listed FIRST) and forced_dealer (always,
		// listed later, scoped to cat 777). On a cat-777 product with an operator price
		// set, the 'always' tier must still win — precedence is by type, not tier order.
		// (Mirrors a parts_dealer user with an operator price on a parts product.)
		$this->set_meta( 10, array( '_regular_price' => '218.40', 'dealer_price' => '173.60', 'operator_price' => '218.40' ) );
		$this->set_terms( 10, 'product_cat', array( 777 ) ); // forced_dealer scope.
		Store::add_user( 5, array( 'operator', 'forced_dealer' ), array( 'operator', 'forced_dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		// forced_dealer (always) → dealer_price 173.60, beating operator's when_priced 218.40.
		$this->assertSame( '173.60', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_out_of_scope_scoped_tier_does_not_undercut() {
		// A user holds both a normal tier and a category-scoped tier (scoped_inc → cat 555).
		// On a product NOT in 555, the scoped tier is out of scope and must stay inert —
		// it must not fall back to MSRP (0 here) and win the lowest-price comparison over
		// the tier that actually prices the product. (Mirrors a parts_dealer user on a
		// non-parts, zero-MSRP product: dealer $995 should win, not parts' $0 fallback.)
		$this->set_meta( 10, array( '_regular_price' => '0', 'dealer_price' => '995', 'scoped_price' => '80' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) ); // not 555, so scoped_inc is out of scope.
		Store::add_user( 5, array( 'dealer', 'scoped_inc' ), array( 'dealer', 'scoped_inc' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '995', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_call_for_price_rule_returns_empty_price() {
		// A product bound to the call_for_price rule resolves to an empty price for
		// every user, so WooCommerce renders the "Call for Price" label.
		Store::$options[ \WCPricebook\Config::OPTION ]['rules']['call_for_price'] = array(
			'bindings' => array( array( 'taxonomy' => 'product_flag', 'terms' => array( 900 ) ) ),
		);
		$this->config = new \WCPricebook\Config();
		$this->context = new \WCPricebook\Context( $this->config );
		$this->engine  = new \WCPricebook\PriceEngine( $this->config, $this->context, new \WCPricebook\Rules( $this->config ) );

		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		$this->set_terms( 10, 'product_flag', array( 900 ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_per_user_override_beats_call_for_price() {
		// A negotiated per-user price still shows even on a call_for_price product.
		Store::$options[ \WCPricebook\Config::OPTION ]['rules']['call_for_price'] = array(
			'bindings' => array( array( 'taxonomy' => 'product_flag', 'terms' => array( 900 ) ) ),
		);
		$this->config = new \WCPricebook\Config();
		$this->context = new \WCPricebook\Context( $this->config );
		$this->engine  = new \WCPricebook\PriceEngine( $this->config, $this->context, new \WCPricebook\Rules( $this->config ) );

		$this->set_meta( 10, array( '_regular_price' => '100', '_pricebook_user_pricing' => array( array( 'user-id' => 5, 'price' => '42' ) ) ) );
		$this->set_terms( 10, 'product_flag', array( 900 ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertSame( '42', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_force_price_roles_unhide_gated_product_for_that_role() {
		// price_requires_tier would hide the price from a non-tier user, but the product
		// forces the price visible to the 'customer' role.
		$this->set_meta(
			10,
			array(
				'_regular_price' => '100',
				\WCPricebook\Context::FORCE_PRICE_ROLES_META => array( 'customer' ),
			)
		);
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 7, array( 'customer' ) ); // no tier.
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '100', (string) $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_force_price_users_unhide_gated_product_for_that_user() {
		// price_requires_tier would hide the price, but the product forces the price
		// visible to this specific user (by ID).
		$this->set_meta(
			10,
			array(
				'_regular_price' => '100',
				\WCPricebook\Context::FORCE_PRICE_USERS_META => array( 7 ),
			)
		);
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		Store::add_user( 7, array( 'customer' ) ); // no tier.
		Store::add_user( 8, array( 'customer' ) ); // a different customer, not forced.
		Store::$current_user = 7;
		$product = new FakeProduct( 10 );

		$this->assertSame( '100', (string) $this->engine->price_for_user( null, $product, 7, false )[0] );
		$this->assertSame( '', (string) $this->engine->price_for_user( null, $product, 8, false )[0] );
	}

	public function test_force_visible_overrides_price_requires_tier() {
		$this->set_meta( 10, array( '_regular_price' => '100' ) );
		$this->set_terms( 10, 'product_cat', array( 999 ) );
		$this->set_terms( 10, 'product_flag', array( 432 ) );
		Store::add_user( 7 ); // no tier.
		Store::$current_user = 7;

		$product = new FakeProduct( 10 );
		$this->assertSame( '100', $this->engine->price_for_user( null, $product, null, false )[0] );
	}

	public function test_effective_price_picks_lower_of_regular_and_sale() {
		$this->set_meta(
			10,
			array(
				'_regular_price'    => '100',
				'dealer_price'      => '70',
				'dealer_sale_price' => '60',
			)
		);
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;

		$product = new FakeProduct( 10 );
		$this->assertEqualsWithDelta( 60.0, (float) $this->engine->effective_price( null, $product ), 0.001 );
	}

	public function test_switcher_override_changes_managers_view() {
		$this->set_meta( 10, array( '_regular_price' => '100', 'dealer_price' => '70' ) );
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'dealer';

		$product = new FakeProduct( 10 );
		$this->assertSame( '70', $this->engine->price_for_user( null, $product, null, false )[0] );
	}
}
