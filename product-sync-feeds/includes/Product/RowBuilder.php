<?php
/**
 * Turns products into mapped feed rows.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Product;

use ProductSyncFeeds\Feed\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Builds rows for a product family (a product and its variations).
 */
class RowBuilder {

	/** @var AttributeResolver */
	private $resolver;

	/** @var ProductFilter */
	private $filter;

	/**
	 * Constructor.
	 *
	 * @param AttributeResolver $resolver Resolver.
	 */
	public function __construct( AttributeResolver $resolver ) {
		$this->resolver = $resolver;
		$this->filter   = new ProductFilter( $resolver );
	}

	/**
	 * Value transforms for the admin picker.
	 *
	 * @return array<string,string>
	 */
	public static function transforms(): array {
		return array(
			'strip_tags'      => __( 'Strip HTML', 'product-sync-feeds' ),
			'collapse_spaces' => __( 'Collapse whitespace', 'product-sync-feeds' ),
			'html_decode'     => __( 'Decode HTML entities', 'product-sync-feeds' ),
			'upper'           => __( 'UPPERCASE', 'product-sync-feeds' ),
			'lower'           => __( 'lowercase', 'product-sync-feeds' ),
			'ucfirst'         => __( 'Capitalize first letter', 'product-sync-feeds' ),
			'ucwords'         => __( 'Capitalize Each Word', 'product-sync-feeds' ),
			'first_value'     => __( 'Keep only the first value of a list', 'product-sync-feeds' ),
			'join_values'     => __( 'Join list into one comma separated value', 'product-sync-feeds' ),
		);
	}

	/**
	 * Rows for a product and, depending on the feed, its variations.
	 *
	 * @param Feed $feed      Feed.
	 * @param int  $parent_id Parent-level product id.
	 * @return array<int,array{parent:int,key:string,row:array}> Keyed by item id. Empty when nothing qualifies.
	 */
	public function family( Feed $feed, int $parent_id ): array {
		$product = wc_get_product( $parent_id );
		if ( ! $product || $product->is_type( 'variation' ) || ! $this->filter->passes_parent( $feed, $product ) ) {
			return array();
		}

		$items = array(); // [ item, parent|null ].
		if ( $product instanceof \WC_Product_Variable ) {
			$mode = (string) $feed->get( 'variations', 'both' );
			if ( 'variations' !== $mode ) {
				$items[] = array( $product, null );
			}
			if ( 'parents' !== $mode ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$items[] = array( $child, $product );
					}
				}
			}
		} else {
			$items[] = array( $product, null );
		}

		$rows = array();
		foreach ( $items as list( $item, $parent ) ) {
			if ( ! $this->filter->passes_item( $feed, $item, $parent ) ) {
				continue;
			}
			$row = $this->build( $feed, $item, $parent );
			if ( ! $row ) {
				continue;
			}
			$rows[ $item->get_id() ] = array(
				'parent' => $parent_id,
				'key'    => $this->key( $feed, $row, $item ),
				'row'    => $row,
			);
		}
		return $rows;
	}

	/**
	 * Build one mapped row.
	 *
	 * @param Feed             $feed   Feed.
	 * @param \WC_Product      $item   Item.
	 * @param \WC_Product|null $parent Parent.
	 * @return array<string,string|string[]>
	 */
	public function build( Feed $feed, \WC_Product $item, ?\WC_Product $parent ): array {
		$row = array();
		foreach ( (array) $feed->get( 'mapping' ) as $map ) {
			$value = $this->resolver->resolve( (string) $map['source'], $item, $parent, $feed );
			$value = $this->apply_transforms( $value, (array) $map['transforms'] );

			if ( $this->is_empty( $value ) && '' !== (string) $map['default'] ) {
				$value = (string) $map['default'];
			}
			if ( ! $this->is_empty( $value ) ) {
				$value = $this->wrap( $value, (string) $map['prefix'], (string) $map['suffix'], (int) $map['limit'] );
			}
			$row[ (string) $map['target'] ] = $value;
		}
		return (array) apply_filters( 'psf_row', $row, $item, $parent, $feed );
	}

	/**
	 * Stable key a channel uses to identify the row: the value mapped from the
	 * "id" source, so delete rows match what the channel received earlier.
	 *
	 * @param Feed        $feed Feed.
	 * @param array       $row  Row.
	 * @param \WC_Product $item Item.
	 * @return string
	 */
	public function key( Feed $feed, array $row, \WC_Product $item ): string {
		$column = self::key_column( $feed );
		if ( '' !== $column && isset( $row[ $column ] ) && is_scalar( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
			return substr( (string) $row[ $column ], 0, 191 );
		}
		return (string) $item->get_id();
	}

	/**
	 * Column that carries the item key: first mapping from source "id", else the first column.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	public static function key_column( Feed $feed ): string {
		$mapping = (array) $feed->get( 'mapping' );
		foreach ( $mapping as $map ) {
			if ( 'id' === $map['source'] ) {
				return (string) $map['target'];
			}
		}
		return $mapping ? (string) $mapping[0]['target'] : '';
	}

	/**
	 * Apply transforms in order.
	 *
	 * @param string|string[] $value      Value.
	 * @param string[]        $transforms Transform ids.
	 * @return string|string[]
	 */
	private function apply_transforms( $value, array $transforms ) {
		foreach ( $transforms as $t ) {
			if ( 'first_value' === $t ) {
				$value = is_array( $value ) ? (string) ( reset( $value ) ?: '' ) : $value;
				continue;
			}
			if ( 'join_values' === $t ) {
				$value = is_array( $value ) ? implode( ', ', $value ) : $value;
				continue;
			}
			$value = is_array( $value ) ? array_map( fn( $v ) => $this->transform( (string) $v, $t ), $value ) : $this->transform( (string) $value, $t );
		}
		return $value;
	}

	/**
	 * One text transform.
	 *
	 * @param string $value Value.
	 * @param string $t     Transform id.
	 * @return string
	 */
	private function transform( string $value, string $t ): string {
		switch ( $t ) {
			case 'strip_tags':
				return wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			case 'collapse_spaces':
				return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
			case 'html_decode':
				return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			case 'upper':
				return mb_strtoupper( $value );
			case 'lower':
				return mb_strtolower( $value );
			case 'ucfirst':
				return mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
			case 'ucwords':
				return mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' );
		}
		return $value;
	}

	/**
	 * Prefix, suffix and character limit (limit applies to the core value).
	 *
	 * @param string|string[] $value  Value.
	 * @param string          $prefix Prefix.
	 * @param string          $suffix Suffix.
	 * @param int             $limit  Max characters, 0 = none.
	 * @return string|string[]
	 */
	private function wrap( $value, string $prefix, string $suffix, int $limit ) {
		$one = static function ( string $v ) use ( $prefix, $suffix, $limit ) {
			if ( $limit > 0 && mb_strlen( $v ) > $limit ) {
				$v = rtrim( mb_substr( $v, 0, $limit ) );
			}
			return $prefix . $v . $suffix;
		};
		return is_array( $value ) ? array_map( static fn( $v ) => $one( (string) $v ), $value ) : $one( (string) $value );
	}

	/**
	 * Empty string or empty list.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_empty( $value ): bool {
		return is_array( $value ) ? ! $value : '' === trim( (string) $value );
	}
}
