<?php
/**
 * E2E fixtures for the Playwright customer-pricing tests. Run inside wp-env via:
 *   wp eval-file .../e2e/setup-fixtures.php
 *
 * Sets up: an open storefront, a tier layered on a non-MSRP tier, category-scoped
 * (include/exclude) tiers, products (global, custom, in/out of a category), and
 * customers with assigned pricing roles.
 *
 * @package WCPricebook
 */

// Open the storefront to logged-in customers.
update_option( 'woocommerce_coming_soon', 'no' );

/**
 * Ensure a customer exists with the given assigned pricing role.
 *
 * @param string $login Username.
 * @param string $role  Pricing role (tier key).
 * @return void
 */
function pb_e2e_customer( $login, $pricing_role = '', $visibility_role = '' ) {
	$user = get_user_by( 'login', $login );
	$id   = $user ? $user->ID : wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'password',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		return;
	}
	update_user_meta( $id, 'pricebook_pricing_role', $pricing_role );
	update_user_meta( $id, 'pricebook_visibility_role', $visibility_role );
	delete_user_meta( $id, '_woocommerce_persistent_cart_1' );
}

/**
 * Ensure a simple, purchasable product exists, optionally in a category.
 *
 * @param string $title   Product title.
 * @param string $slug    Product slug.
 * @param int    $regular Regular price.
 * @param int    $cat_id  product_cat term ID (0 = none).
 * @param array  $meta    Extra post meta.
 * @return int
 */
function pb_e2e_product( $title, $slug, $regular, $cat_id = 0, $meta = array() ) {
	$existing = get_posts(
		array(
			'post_type'   => 'product',
			'name'        => $slug,
			'post_status' => 'publish',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$id = $existing ? (int) $existing[0] : (int) wp_insert_post(
		array(
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_type'   => 'product',
			'post_status' => 'publish',
		)
	);
	wp_set_object_terms( $id, 'simple', 'product_type' );
	update_post_meta( $id, '_regular_price', (string) $regular );
	update_post_meta( $id, '_price', (string) $regular );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_manage_stock', 'no' );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	wp_set_object_terms( $id, $cat_id ? array( (int) $cat_id ) : array(), 'product_cat' );
	return $id;
}

// A product category used for the scoped-tier tests.
$term     = term_exists( 'Scoped', 'product_cat' );
$term     = $term ? $term : wp_insert_term( 'Scoped', 'product_cat' );
$scoped_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

// Tiers: distributor (layered on wholesale) + category-scoped include/exclude.
$config = get_option( 'wc_pricebook_config' );
if ( is_array( $config ) ) {
	$config['tiers']['distributor'] = array(
		'key'         => 'distributor',
		'label'       => 'Distributor',
		'multiplier'  => 0.8,
		'base_meta'   => 'wholesale',
		'fallback_to' => 'msrp',
	);
	$config['tiers']['cat_include'] = array(
		'key'                => 'cat_include',
		'label'              => 'Category Include',
		'multiplier'         => 0.5,
		'base_meta'          => 'msrp',
		'fallback_to'        => 'msrp',
		'pricing_categories' => array( 'mode' => 'include', 'categories' => array( $scoped_id ) ),
	);
	$config['tiers']['cat_exclude'] = array(
		'key'                => 'cat_exclude',
		'label'              => 'Category Exclude',
		'multiplier'         => 0.5,
		'base_meta'          => 'msrp',
		'fallback_to'        => 'msrp',
		'pricing_categories' => array( 'mode' => 'exclude', 'categories' => array( $scoped_id ) ),
	);
	$config['visibility_roles'] = array(
		'vis_include' => array(
			'key'        => 'vis_include',
			'label'      => 'Visibility Include',
			'mode'       => 'include',
			'categories' => array( $scoped_id ),
		),
		'vis_exclude' => array(
			'key'        => 'vis_exclude',
			'label'      => 'Visibility Exclude',
			'mode'       => 'exclude',
			'categories' => array( $scoped_id ),
		),
	);
	update_option( 'wc_pricebook_config', $config );
}

// Customers with assigned roles.
pb_e2e_customer( 'pb_wholesale', 'wholesale' );
pb_e2e_customer( 'pb_distributor', 'distributor' );
pb_e2e_customer( 'pb_mix', 'wholesale' );
pb_e2e_customer( 'pb_catinc', 'cat_include' );
pb_e2e_customer( 'pb_catexc', 'cat_exclude' );
pb_e2e_customer( 'pb_visinc', '', 'vis_include' );
pb_e2e_customer( 'pb_visexc', '', 'vis_exclude' );
pb_e2e_customer( 'pb_none', '', '' ); // no tier, no visibility role

// Products: custom-priced, plus in/out of the scoped category (all MSRP $100).
$custom_id = pb_e2e_product( 'PB Custom Product', 'pb-custom-product', 200, 0, array( '_wholesale_price' => '65' ) );
pb_e2e_product( 'PB In Category', 'pb-in-category', 100, $scoped_id );
pb_e2e_product( 'PB Out Category', 'pb-out-category', 100, 0 );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions" ); // phpcs:ignore WordPress.DB

echo 'custom_product_id=' . $custom_id . "\n";
echo 'scoped_term_id=' . $scoped_id . "\n";
echo "fixtures-ready\n";
