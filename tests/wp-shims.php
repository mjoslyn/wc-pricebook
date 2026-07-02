<?php
/**
 * Minimal WordPress function/class shims backed by {@see \WCPricebook\Tests\Store}.
 *
 * Only the functions the engine, config, context, and rules actually call are
 * implemented. They are intentionally simple, synchronous, and side-effect free
 * beyond the in-memory store.
 *
 * @package WCPricebook\Tests
 */

declare( strict_types=1 );

use WCPricebook\Tests\Store;

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User stand-in.
	 */
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var array<int,string> */
		public $roles = array();
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback in the in-memory store (priority/arg-count ignored).
	 *
	 * @param string   $tag           Filter tag.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority (ignored).
	 * @param int      $accepted_args Accepted args (ignored).
	 * @return bool
	 */
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		Store::$filters[ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Apply any callbacks registered via add_filter; pass-through when none.
	 *
	 * @param string $tag   Filter tag.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( $tag, $value = null ) {
		$args      = array_slice( func_get_args(), 1 );
		$callbacks = Store::$filters[ $tag ] ?? array();
		foreach ( $callbacks as $callback ) {
			$value   = call_user_func_array( $callback, $args );
			$args[0] = $value;
		}
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, Store::$options ) ? Store::$options[ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $name, $value ) {
		Store::$options[ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function add_option( $name, $value ) {
		Store::$options[ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$value = Store::$post_meta[ $post_id ][ $key ] ?? '';
		return $value;
	}
}

if ( ! function_exists( 'has_term' ) ) {
	/**
	 * @param int|int[] $term     Term ID(s).
	 * @param string    $taxonomy Taxonomy.
	 * @param int       $post_id  Post ID.
	 * @return bool
	 */
	function has_term( $term, $taxonomy, $post_id ) {
		$assigned = Store::$post_terms[ $post_id ][ $taxonomy ] ?? array();
		$want     = array_map( 'intval', (array) $term );
		return count( array_intersect( $want, array_map( 'intval', $assigned ) ) ) > 0;
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Args (ignored; always returns IDs).
	 * @return array<int,int>
	 */
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		return Store::$post_terms[ $post_id ][ $taxonomy ] ?? array();
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * @param int|WP_User $user User ID or object.
	 * @param string      $cap  Capability.
	 * @return bool
	 */
	function user_can( $user, $cap ) {
		$id = is_object( $user ) ? (int) $user->ID : (int) $user;
		return ! empty( Store::$user_caps[ $id ][ $cap ] );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		return Store::$user_meta[ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		Store::$user_meta[ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * @return WP_User
	 */
	function wp_get_current_user() {
		$id = Store::$current_user;
		if ( isset( Store::$users[ $id ] ) ) {
			return Store::$users[ $id ];
		}
		$u     = new WP_User();
		$u->ID = $id;
		return $u;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * @return int
	 */
	function get_current_user_id() {
		return (int) Store::$current_user;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * @param string $field Field (only 'id' supported).
	 * @param int    $value User ID.
	 * @return WP_User|false
	 */
	function get_user_by( $field, $value ) {
		return Store::$users[ (int) $value ] ?? false;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * @param int $user_id User ID.
	 * @return WP_User|false
	 */
	function get_userdata( $user_id ) {
		return Store::$users[ (int) $user_id ] ?? false;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		Store::$post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_post_meta( $post_id, $key ) {
		unset( Store::$post_meta[ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value (no slashes added in tests, so pass through).
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Test stub: the literal 'valid' is the only accepted nonce.
	 *
	 * @param string     $nonce  Nonce value.
	 * @param string|int $action Action (ignored).
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return 'valid' === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Capability check against the current user's cap map in {@see Store}.
	 *
	 * @param string $capability Capability.
	 * @param mixed  ...$args    Extra args (e.g. object ID; ignored).
	 * @return bool
	 */
	function current_user_can( $capability, ...$args ) {
		$id = (int) Store::$current_user;
		return ! empty( Store::$user_caps[ $id ][ $capability ] );
	}
}
