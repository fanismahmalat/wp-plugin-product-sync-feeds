<?php
/**
 * Product filters. This is the authoritative check used by full and delta runs
 * alike; ProductQuery only narrows the candidate list for speed.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Product;

use ProductSyncFeeds\Feed\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a product (and each of its variations) belongs in a feed.
 */
class ProductFilter {

	/** @var AttributeResolver */
	private $resolver;

	/** @var array<string,int[]> Expanded category/tag ids per feed and taxonomy. */
	private $term_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AttributeResolver $resolver Resolver used for advanced conditions.
	 */
	public function __construct( AttributeResolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Product-level checks (status, type, visibility, categories, tags, ids).
	 *
	 * @param Feed        $feed Feed.
	 * @param \WC_Product $p    Parent-level product.
	 * @return bool
	 */
	public function passes_parent( Feed $feed, \WC_Product $p ): bool {
		$f = $feed->get( 'filters' );

		if ( ! in_array( $p->get_status(), (array) $f['post_status'], true ) ) {
			return false;
		}
		if ( $f['product_types'] && ! in_array( $p->get_type(), (array) $f['product_types'], true ) ) {
			return false;
		}
		if ( $f['exclude_hidden'] && 'hidden' === $p->get_catalog_visibility() ) {
			return false;
		}
		if ( '' !== $p->get_post_password() ) {
			return false;
		}
		if ( ! $this->term_rule( $feed, 'product_cat', $f['categories'], $f['categories_mode'], $p->get_category_ids() ) ) {
			return false;
		}
		if ( ! $this->term_rule( $feed, 'product_tag', $f['tags'], $f['tags_mode'], $p->get_tag_ids() ) ) {
			return false;
		}
		if ( $f['ids'] ) {
			$listed = in_array( $p->get_id(), (array) $f['ids'], true );
			if ( ( 'include' === $f['ids_mode'] ) !== $listed ) {
				return false;
			}
		}
		return (bool) apply_filters( 'psf_include_product', true, $p, $feed );
	}

	/**
	 * Row-level checks (stock, empty fields, price range, conditions).
	 *
	 * @param Feed             $feed   Feed.
	 * @param \WC_Product      $item   Item.
	 * @param \WC_Product|null $parent Parent of a variation.
	 * @return bool
	 */
	public function passes_item( Feed $feed, \WC_Product $item, ?\WC_Product $parent ): bool {
		$f = $feed->get( 'filters' );

		if ( $parent && 'publish' !== $item->get_status() ) {
			return false; // Disabled variation.
		}
		if ( $f['stock_status'] && ! in_array( $item->get_stock_status(), (array) $f['stock_status'], true ) ) {
			return false;
		}
		$price = (string) $item->get_price();
		if ( $f['exclude_empty_price'] && '' === $price ) {
			return false;
		}
		if ( $f['exclude_empty_image'] && ! $item->get_image_id() && ! ( $parent && $parent->get_image_id() ) ) {
			return false;
		}
		if ( $f['exclude_empty_description'] && '' === trim( (string) $this->resolver->resolve( 'description', $item, $parent, $feed ) ) ) {
			return false;
		}
		if ( '' !== $f['price_min'] && ( '' === $price || (float) $price < (float) $f['price_min'] ) ) {
			return false;
		}
		if ( '' !== $f['price_max'] && ( '' === $price || (float) $price > (float) $f['price_max'] ) ) {
			return false;
		}
		if ( $f['conditions'] && ! $this->conditions( $feed, $item, $parent ) ) {
			return false;
		}
		return (bool) apply_filters( 'psf_include_item', true, $item, $parent, $feed );
	}

	/**
	 * Advanced conditions.
	 *
	 * @param Feed             $feed   Feed.
	 * @param \WC_Product      $item   Item.
	 * @param \WC_Product|null $parent Parent.
	 * @return bool
	 */
	private function conditions( Feed $feed, \WC_Product $item, ?\WC_Product $parent ): bool {
		$any = 'any' === $feed->get( 'filters.conditions_match' );
		foreach ( (array) $feed->get( 'filters.conditions' ) as $cond ) {
			$value = $this->resolver->resolve( (string) $cond['source'], $item, $parent, $feed );
			$ok    = ConditionEvaluator::matches( $value, (string) $cond['operator'], (string) $cond['value'] );
			if ( $any && $ok ) {
				return true;
			}
			if ( ! $any && ! $ok ) {
				return false;
			}
		}
		return ! $any;
	}

	/**
	 * Include/exclude rule on term ids, child terms included.
	 *
	 * @param Feed   $feed     Feed.
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $selected Selected term ids.
	 * @param string $mode     include|exclude.
	 * @param int[]  $assigned Term ids on the product.
	 * @return bool
	 */
	private function term_rule( Feed $feed, string $taxonomy, array $selected, string $mode, array $assigned ): bool {
		if ( ! $selected ) {
			return true;
		}
		$hit = (bool) array_intersect( array_map( 'intval', $assigned ), $this->expand_terms( $feed, $taxonomy, $selected ) );
		return 'include' === $mode ? $hit : ! $hit;
	}

	/**
	 * Selected terms plus all descendants.
	 *
	 * @param Feed   $feed     Feed.
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @return int[]
	 */
	public function expand_terms( Feed $feed, string $taxonomy, array $ids ): array {
		$key = $feed->id . ':' . $taxonomy . ':' . implode( ',', $ids );
		if ( ! isset( $this->term_cache[ $key ] ) ) {
			$all = array_map( 'intval', $ids );
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				foreach ( $ids as $id ) {
					$children = get_term_children( (int) $id, $taxonomy );
					if ( is_array( $children ) ) {
						$all = array_merge( $all, array_map( 'intval', $children ) );
					}
				}
			}
			$this->term_cache[ $key ] = array_values( array_unique( $all ) );
		}
		return $this->term_cache[ $key ];
	}
}
