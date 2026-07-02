<?php
/**
 * Rule evaluation: maps named pricing behaviors to taxonomy terms.
 *
 * Replaces hardcoded term IDs (e.g. "flag 363", "category 218") with named rules
 * whose taxonomy/term bindings live in configuration.
 *
 * @package WCPricebook
 */

namespace WCPricebook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates configured rules against a product.
 */
class Rules {

	/**
	 * Prefix for the per-product rule flag meta ("<prefix><rule>" = '1' when set on the
	 * product editor). A product flagged this way triggers the rule in addition to any
	 * configured taxonomy bindings.
	 */
	const FLAG_META_PREFIX = '_wc_pricebook_rule_';

	/**
	 * The meta key storing a rule's per-product flag.
	 *
	 * @param string $rule Rule key.
	 * @return string
	 */
	public static function flag_meta_key( $rule ) {
		return self::FLAG_META_PREFIX . $rule;
	}

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
	 * Whether a named rule applies to a product (any configured binding matches).
	 *
	 * @param string $rule       Rule key (e.g. skip_matrix, no_tier_discount).
	 * @param int    $product_id Product ID.
	 * @return bool
	 */
	public function applies( $rule, $product_id ) {
		// Per-product flag set on the product editor.
		if ( '1' === (string) get_post_meta( $product_id, self::flag_meta_key( $rule ), true ) ) {
			return true;
		}
		foreach ( $this->bindings( $rule ) as $binding ) {
			$taxonomy = isset( $binding['taxonomy'] ) ? $binding['taxonomy'] : '';
			$terms    = isset( $binding['terms'] ) ? $binding['terms'] : array();
			if ( '' === $taxonomy || empty( $terms ) ) {
				continue;
			}
			if ( has_term( $terms, $taxonomy, $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The specific terms that cause a rule to apply to a product, resolved to
	 * their display names. Useful for debug/UI ("why does this rule apply?").
	 *
	 * @param string $rule       Rule key.
	 * @param int    $product_id Product ID.
	 * @return array<int,array{taxonomy:string,term_id:int,name:string}>
	 */
	public function matched_terms( $rule, $product_id ) {
		$matched = array();
		foreach ( $this->bindings( $rule ) as $binding ) {
			$taxonomy = isset( $binding['taxonomy'] ) ? $binding['taxonomy'] : '';
			$terms    = isset( $binding['terms'] ) ? $binding['terms'] : array();
			if ( '' === $taxonomy || empty( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term_id ) {
				if ( ! has_term( (int) $term_id, $taxonomy, $product_id ) ) {
					continue;
				}
				$term      = get_term( (int) $term_id, $taxonomy );
				$matched[] = array(
					'taxonomy' => $taxonomy,
					'term_id'  => (int) $term_id,
					'name'     => ( $term && ! is_wp_error( $term ) ) ? $term->name : (string) $term_id,
				);
			}
		}
		return $matched;
	}

	/**
	 * Term IDs bound to a rule, flattened across all of its bindings.
	 *
	 * Useful for visibility math (e.g. which categories are "hidden").
	 *
	 * @param string $rule     Rule key.
	 * @param string $taxonomy Optional taxonomy filter.
	 * @return array<int,int>
	 */
	public function term_ids( $rule, $taxonomy = '' ) {
		$ids = array();
		foreach ( $this->bindings( $rule ) as $binding ) {
			if ( '' !== $taxonomy && ( ! isset( $binding['taxonomy'] ) || $binding['taxonomy'] !== $taxonomy ) ) {
				continue;
			}
			if ( ! empty( $binding['terms'] ) && is_array( $binding['terms'] ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $binding['terms'] ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Bindings for a rule.
	 *
	 * @param string $rule Rule key.
	 * @return array<int,array<string,mixed>>
	 */
	private function bindings( $rule ) {
		$rules = $this->config->rules();
		if ( empty( $rules[ $rule ]['bindings'] ) || ! is_array( $rules[ $rule ]['bindings'] ) ) {
			return array();
		}
		return $rules[ $rule ]['bindings'];
	}
}
