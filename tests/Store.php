<?php
/**
 * In-memory backing store for the WordPress accessor shims used in unit tests.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

namespace WCPricebook\Tests;

/**
 * Static in-memory store standing in for the WP database during tests.
 */
class Store {

	/** @var array<string,mixed> */
	public static $options = array();

	/** @var array<int,array<string,mixed>> post_id => [meta_key => value] */
	public static $post_meta = array();

	/** @var array<int,array<string,array<int,int>>> post_id => [taxonomy => [term_ids]] */
	public static $post_terms = array();

	/** @var array<int,array<string,bool>> user_id => [capability => true] */
	public static $user_caps = array();

	/** @var array<int,array<string,mixed>> user_id => [meta_key => value] */
	public static $user_meta = array();

	/** @var array<int,object> user_id => WP_User-like */
	public static $users = array();

	/** @var int */
	public static $current_user = 0;

	/** @var array<string,array<int,callable>> filter tag => callbacks */
	public static $filters = array();

	/**
	 * Reset all state between tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$options      = array();
		self::$post_meta    = array();
		self::$post_terms   = array();
		self::$user_caps    = array();
		self::$user_meta    = array();
		self::$users        = array();
		self::$current_user = 0;
		self::$filters      = array();
	}

	/**
	 * Register a user with roles + capabilities.
	 *
	 * @param int                $id    User ID.
	 * @param array<int,string>  $roles Role slugs.
	 * @param array<int,string>  $caps  Capability slugs (tier keys, manager caps, …).
	 * @return void
	 */
	public static function add_user( $id, array $roles = array(), array $caps = array() ) {
		$user        = new \WP_User();
		$user->ID    = $id;
		$user->roles = $roles;
		self::$users[ $id ] = $user;

		$cap_map = array();
		foreach ( array_merge( $roles, $caps ) as $cap ) {
			$cap_map[ $cap ] = true;
		}
		self::$user_caps[ $id ] = $cap_map;
	}
}
