<?php
/**
 * Context tests: pricing-user resolution, manager detection, tier membership.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

/**
 * @covers \WCPricebook\Context
 */
class ContextTest extends TestCase {

	public function test_pricing_user_is_current_user_by_default() {
		Store::add_user( 5, array( 'dealer' ) );
		Store::$current_user = 5;
		$this->assertSame( 5, $this->context->pricing_user_id() );
	}

	public function test_manager_detected_by_capability() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		$this->assertTrue( $this->context->is_manager() );
	}

	public function test_role_without_capability_is_not_manager() {
		// Manager identity is hardcoded to the manage_woocommerce capability with no
		// roles; a role alone (without the capability) is not a manager. Extend via
		// the wc_pricebook_is_manager filter in theme code.
		Store::add_user( 9, array( 'shop_manager' ) ); // role only, no capability.
		Store::$current_user = 9;
		$this->assertFalse( $this->context->is_manager() );
	}

	public function test_non_manager_is_not_manager() {
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 5;
		$this->assertFalse( $this->context->is_manager() );
	}

	public function test_user_has_any_tier() {
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::add_user( 7, array( 'customer' ) );
		$this->assertTrue( $this->context->user_has_any_tier( 5 ) );
		$this->assertFalse( $this->context->user_has_any_tier( 7 ) );
	}

	public function test_switcher_role_reads_configured_meta_key() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'dealer_4';
		$this->assertSame( 'dealer_4', $this->context->switcher_role() );
	}

	public function test_visibility_role_categories_unions_user_roles() {
		// region_a hides [555] from users with the dealer role (hide_visibility on).
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		$result = $this->context->visibility_role_categories( 5 );
		$this->assertSame( array(), $result['include'] );
		$this->assertSame( array( 555 ), $result['exclude'] );
	}

	public function test_effective_visibility_falls_back_to_role_without_user_meta() {
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		$result = $this->context->effective_visibility_categories( 5 );
		$this->assertSame( array( 555 ), $result['exclude'] );
	}

	public function test_user_visibility_meta_overrides_role() {
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		// User's own list wins over the role's categories.
		Store::$user_meta[5]['pricebook_exclude_categories'] = array( 777 );
		$result = $this->context->effective_visibility_categories( 5 );
		$this->assertSame( array( 777 ), $result['exclude'] );
	}

	public function test_membership_is_role_based_not_meta() {
		// No separation: tier membership comes only from a WP role/capability. A stray
		// pricebook_pricing_role meta must NOT grant a tier.
		Store::add_user( 7, array( 'customer' ) );
		Store::$user_meta[7]['pricebook_pricing_role'] = 'dealer';
		$this->assertFalse( $this->context->user_has_tier( 7, 'dealer' ) );
		$this->assertFalse( $this->context->user_has_any_tier( 7 ) );

		// Holding the capability does grant it.
		Store::add_user( 8, array( 'dealer' ), array( 'dealer' ) );
		$this->assertTrue( $this->context->user_has_tier( 8, 'dealer' ) );
		$this->assertSame( array( 'dealer' ), $this->context->user_pricing_roles( 8 ) );
	}

	public function test_manager_can_preview_as_specific_user() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_user'] = 5;

		// Pricing resolves as the impersonated customer.
		$this->assertSame( 5, $this->context->pricing_user_id() );
		$this->assertSame( 5, $this->context->switcher_user_id() );
		// The real logged-in user is still a manager (UI gating), but the resolved
		// pricing user (the customer) is not.
		$this->assertTrue( $this->context->current_user_is_manager() );
		$this->assertFalse( $this->context->is_manager() );
	}

	public function test_resolve_pricing_user_id_honors_parent_filter() {
		Store::add_user( 5, array( 'customer' ) );      // child account.
		Store::add_user( 3, array( 'dealer' ), array( 'dealer' ) ); // parent account.

		add_filter(
			'wc_pricebook_pricing_user',
			static function ( $user ) {
				return ( (int) $user->ID === 5 ) ? get_user_by( 'id', 3 ) : $user;
			}
		);

		$this->assertSame( 3, $this->context->resolve_pricing_user_id( 5 ) ); // child → parent.
		$this->assertSame( 3, $this->context->resolve_pricing_user_id( 3 ) ); // parent → itself.
		$this->assertSame( 9, $this->context->resolve_pricing_user_id( 9 ) ); // unknown user unchanged.
	}

	public function test_non_manager_cannot_impersonate() {
		Store::add_user( 7, array( 'customer' ) );
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );
		Store::$current_user = 7;
		Store::$user_meta[7]['pricebook_switcher_user'] = 5;

		// A non-manager's switcher_user meta is ignored: they are priced as themselves.
		$this->assertSame( 7, $this->context->pricing_user_id() );
		$this->assertFalse( $this->context->current_user_is_manager() );
	}

	public function test_active_tier_label_uses_manager_switcher() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		Store::$user_meta[9]['pricebook_switcher_role'] = 'dealer';
		// The switcher selection drives the displayed price, so the badge follows it.
		$this->assertSame( 'Dealer Base', $this->context->active_tier_label() );
	}

	public function test_active_tier_label_empty_for_manager_at_msrp() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$current_user = 9;
		$this->assertSame( '', $this->context->active_tier_label() );
	}

	public function test_product_visible_for_user_unrestricted() {
		Store::add_user( 7, array( 'customer' ) ); // No visibility lists.
		$this->set_terms( 100, 'product_cat', array( 42 ) );
		$result = $this->context->product_visible_for_user( 100, 7 );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( 'unrestricted', $result['reason'] );
	}

	public function test_product_visible_for_user_include_match_and_miss() {
		Store::add_user( 7, array( 'customer' ) );
		Store::$user_meta[7]['pricebook_include_categories'] = array( 555 );

		$this->set_terms( 100, 'product_cat', array( 555 ) ); // In the allowed list.
		$in = $this->context->product_visible_for_user( 100, 7 );
		$this->assertTrue( $in['visible'] );
		$this->assertSame( 'allowed', $in['reason'] );

		$this->set_terms( 101, 'product_cat', array( 42 ) ); // Outside the allowed list.
		$out = $this->context->product_visible_for_user( 101, 7 );
		$this->assertFalse( $out['visible'] );
		$this->assertSame( 'not_in_include', $out['reason'] );
	}

	public function test_product_hidden_when_in_excluded_category() {
		Store::add_user( 7, array( 'customer' ) );
		Store::$user_meta[7]['pricebook_exclude_categories'] = array( 666 );
		$this->set_terms( 100, 'product_cat', array( 666 ) );

		$result = $this->context->product_visible_for_user( 100, 7 );
		$this->assertFalse( $result['visible'] );
		$this->assertSame( 'excluded', $result['reason'] );
	}

	public function test_visibility_categories_parses_comma_separated_string() {
		// Legacy stored include/exclude as a comma-separated text field, not an array.
		Store::add_user( 5, array( 'customer' ) );
		Store::$user_meta[5]['pricebook_include_categories'] = '176, 249';
		Store::$user_meta[5]['pricebook_exclude_categories'] = '300';
		$result = $this->context->visibility_categories( 5 );
		$this->assertSame( array( 176, 249 ), $result['include'] );
		$this->assertSame( array( 300 ), $result['exclude'] );
	}

	public function test_remapped_user_meta_key_is_read() {
		// A store can point a logical meta key at its own key via the user_meta config.
		Store::$options[ \WCPricebook\Config::OPTION ]['user_meta'] = array(
			'category_roles' => 'category-pricing-roles',
		);
		$this->config = new \WCPricebook\Config();
		$this->context = new \WCPricebook\Context( $this->config );

		Store::add_user( 5, array( 'customer' ) );
		Store::$user_meta[5]['category-pricing-roles'] = array(
			'item-0' => array( 'product-category' => array( 176 ), 'pricing-role' => 'msrp' ),
		);
		$roles = $this->context->category_roles( 5 );
		$this->assertSame( 'msrp', $roles['item-0']['pricing-role'] );
	}

	public function test_product_always_visible_to_manager() {
		Store::add_user( 9, array( 'shop_manager' ), array( 'manage_woocommerce' ) );
		Store::$user_meta[9]['pricebook_include_categories'] = array( 555 ); // Restriction is ignored for managers.
		$this->set_terms( 100, 'product_cat', array( 42 ) );

		$result = $this->context->product_visible_for_user( 100, 9 );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( 'manager', $result['reason'] );
	}

	/**
	 * Rebuild Context after injecting a visibility-role config.
	 *
	 * @param array<string,mixed> $roles Visibility roles config.
	 * @return void
	 */
	private function with_visibility_roles( array $roles ) {
		Store::$options[ \WCPricebook\Config::OPTION ]['visibility_roles'] = $roles;
		$this->config  = new \WCPricebook\Config();
		$this->context = new \WCPricebook\Context( $this->config );
	}

	public function test_visibility_role_match_any() {
		$this->with_visibility_roles(
			array(
				'r' => array( 'label' => 'R', 'roles' => array( 'dealer', 'operator' ), 'match' => 'any', 'categories' => array( 'mode' => 'include', 'categories' => array( 555 ) ), 'hide' => 'product' ),
			)
		);
		Store::add_user( 5, array( 'operator' ) );      // has one of the two → matches.
		Store::add_user( 6, array( 'customer' ) );      // has neither → no match.
		$this->assertSame( array( 555 ), $this->context->visibility_role_categories( 5 )['exclude'] );
		$this->assertSame( array(), $this->context->visibility_role_categories( 6 )['exclude'] );
	}

	public function test_visibility_role_match_all() {
		$this->with_visibility_roles(
			array(
				'r' => array( 'label' => 'R', 'roles' => array( 'dealer', 'operator' ), 'match' => 'all', 'categories' => array( 'mode' => 'include', 'categories' => array( 555 ) ), 'hide' => 'product' ),
			)
		);
		Store::add_user( 5, array( 'dealer', 'operator' ) ); // has both → matches.
		Store::add_user( 6, array( 'dealer' ) );             // missing operator → no match.
		$this->assertSame( array( 555 ), $this->context->visibility_role_categories( 5 )['exclude'] );
		$this->assertSame( array(), $this->context->visibility_role_categories( 6 )['exclude'] );
	}

	public function test_role_targeting_is_capability_based() {
		// Role targeting (visibility roles, force overrides, bulk pricing) uses the same
		// capability check as tier membership: user_can($user, $slug). A capability
		// granted by a differently-named role matches, and so does the role name itself.
		Store::add_user( 9, array( 'vip' ), array( 'gold' ) ); // 'vip' role grants the 'gold' cap.

		$this->assertTrue( $this->context->user_in_role_set( 9, array( 'gold' ) ) );   // by capability
		$this->assertTrue( $this->context->user_in_role_set( 9, array( 'vip' ) ) );    // by role name
		$this->assertTrue( $this->context->user_has_tier( 9, 'gold' ) );               // tiers agree
		$this->assertFalse( $this->context->user_in_role_set( 9, array( 'silver' ) ) );
	}

	public function test_visibility_role_matches_specific_user() {
		// A visibility role can target specific users (no roles configured) — only those
		// users are matched.
		$this->with_visibility_roles(
			array(
				'poa' => array( 'label' => 'POA', 'roles' => array(), 'users' => array( 7 ), 'match' => 'any', 'categories' => array( 'mode' => 'include', 'categories' => array( 249 ) ), 'hide' => 'pricing' ),
			)
		);
		Store::add_user( 7, array( 'customer' ) );
		Store::add_user( 8, array( 'customer' ) );
		$this->set_terms( 100, 'product_cat', array( 249 ) );

		$this->assertTrue( $this->context->price_hidden_by_visibility_role( 100, 7 ) );
		$this->assertFalse( $this->context->price_hidden_by_visibility_role( 100, 8 ) );
	}

	public function test_visibility_role_msrp_customer_matches_non_tier_user() {
		// The synthetic MSRP_CUSTOMER role matches users with no pricing tier.
		$this->with_visibility_roles(
			array(
				'retail' => array( 'label' => 'Retail', 'roles' => array( \WCPricebook\Context::MSRP_CUSTOMER ), 'match' => 'any', 'categories' => array( 'mode' => 'include', 'categories' => array( 666 ) ), 'hide' => 'product' ),
			)
		);
		Store::add_user( 7, array( 'customer' ) );                       // no tier → MSRP customer → matches.
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) );      // holds a tier → not MSRP → no match.
		$this->assertSame( array( 666 ), $this->context->visibility_role_categories( 7 )['exclude'] );
		$this->assertSame( array(), $this->context->visibility_role_categories( 5 )['exclude'] );
	}

	public function test_product_force_visible_roles_override_hide_visibility() {
		$this->with_visibility_roles(
			array(
				'r' => array( 'label' => 'R', 'roles' => array( \WCPricebook\Context::MSRP_CUSTOMER ), 'match' => 'any', 'categories' => array( 'mode' => 'include', 'categories' => array( 176 ) ), 'hide' => 'product' ),
			)
		);
		Store::add_user( 7, array( 'customer' ) );
		$this->set_terms( 100, 'product_cat', array( 176 ) );

		// Hidden by the role by default.
		$this->assertFalse( $this->context->product_visible_for_user( 100, 7 )['visible'] );

		// The product forces itself visible to the customer role.
		Store::$post_meta[100][ \WCPricebook\Context::FORCE_VISIBLE_ROLES_META ] = array( 'customer' );
		$result = $this->context->product_visible_for_user( 100, 7 );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( 'forced_role', $result['reason'] );
	}

	public function test_visibility_role_hide_pricing_switch() {
		// hide_pricing does NOT affect catalog visibility, but does hide the price for a
		// matched user on a product in the role's categories.
		$this->with_visibility_roles(
			array(
				'poa' => array( 'label' => 'POA', 'roles' => array( \WCPricebook\Context::MSRP_CUSTOMER ), 'match' => 'any', 'categories' => array( 'mode' => 'include', 'categories' => array( 249 ) ), 'hide' => 'pricing' ),
			)
		);
		Store::add_user( 7, array( 'customer' ) );                  // MSRP customer → matched.
		Store::add_user( 5, array( 'dealer' ), array( 'dealer' ) ); // tier holder → not matched.
		$this->set_terms( 100, 'product_cat', array( 249 ) );
		$this->set_terms( 101, 'product_cat', array( 42 ) );

		// hide_pricing does not add a catalog exclude.
		$this->assertSame( array(), $this->context->visibility_role_categories( 7 )['exclude'] );
		// Price hidden for the MSRP customer on the in-category product only.
		$this->assertTrue( $this->context->price_hidden_by_visibility_role( 100, 7 ) );
		$this->assertFalse( $this->context->price_hidden_by_visibility_role( 101, 7 ) );
		$this->assertFalse( $this->context->price_hidden_by_visibility_role( 100, 5 ) );
	}
}
