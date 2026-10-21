<?php
/**
 * Resolves a mapping "source" to a product value.
 *
 * Sources are either a built-in key (see sources()) or a prefixed dynamic source:
 *   meta:{meta_key}     product meta (variations fall back to the parent)
 *   attr:{attribute}    product attribute, e.g. attr:pa_color or attr:Material
 *   tax:{taxonomy}      term names of any taxonomy (taken from the parent)
 *   static:{text}       fixed text
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Product;

use ProductSyncFeeds\Feed\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Product value lookup.
 */
class AttributeResolver {

	/** Taxonomies checked, in order, for the brand source. */
	const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'brand' );

	/** Meta keys checked, in order, for the gtin source. */
	const GTIN_META = array( '_gtin', '_ean', '_upc', '_isbn', 'gtin', 'ean', '_wpm_gtin_code', 'hwp_product_gtin', '_alg_ean' );

	/**
	 * Built-in sources for the admin picker.
	 *
	 * @return array<string,string>
	 */
	public static function sources(): array {
		return array(
			'id'                        => __( 'Product ID', 'product-sync-feeds' ),
			'parent_id'                 => __( 'Parent ID (0 for non-variations)', 'product-sync-feeds' ),
			'item_group_id'             => __( 'Item group ID (parent ID for variations)', 'product-sync-feeds' ),
			'sku'                       => __( 'SKU', 'product-sync-feeds' ),
			'parent_sku'                => __( 'Parent SKU', 'product-sync-feeds' ),
			'title'                     => __( 'Title', 'product-sync-feeds' ),
			'parent_title'              => __( 'Parent title', 'product-sync-feeds' ),
			'description'               => __( 'Description (falls back to short description)', 'product-sync-feeds' ),
			'short_description'         => __( 'Short description', 'product-sync-feeds' ),
			'link'                      => __( 'Product URL', 'product-sync-feeds' ),
			'image_link'                => __( 'Main image URL', 'product-sync-feeds' ),
			'additional_image_link'     => __( 'Gallery image URLs', 'product-sync-feeds' ),
			'type'                      => __( 'Product type (simple, variable...)', 'product-sync-feeds' ),
			'product_type'              => __( 'Category path (A > B > C)', 'product-sync-feeds' ),
			'category'                  => __( 'Primary category name', 'product-sync-feeds' ),
			'categories'                => __( 'All category names', 'product-sync-feeds' ),
			'tags'                      => __( 'Tag names', 'product-sync-feeds' ),
			'brand'                     => __( 'Brand', 'product-sync-feeds' ),
			'gtin'                      => __( 'GTIN / EAN / UPC', 'product-sync-feeds' ),
			'condition'                 => __( 'Condition (new)', 'product-sync-feeds' ),
			'availability'              => __( 'Availability (in_stock, out_of_stock, backorder)', 'product-sync-feeds' ),
			'availability_text'         => __( 'Availability (in stock, out of stock, available for order)', 'product-sync-feeds' ),
			'stock_status'              => __( 'Stock status (raw)', 'product-sync-feeds' ),
			'stock_quantity'            => __( 'Stock quantity', 'product-sync-feeds' ),
			'price'                     => __( 'Regular price with currency', 'product-sync-feeds' ),
			'sale_price'                => __( 'Sale price with currency (when on sale)', 'product-sync-feeds' ),
			'current_price'             => __( 'Current price with currency', 'product-sync-feeds' ),
			'price_raw'                 => __( 'Regular price (number)', 'product-sync-feeds' ),
			'sale_price_raw'            => __( 'Sale price (number)', 'product-sync-feeds' ),
			'current_price_raw'         => __( 'Current price (number)', 'product-sync-feeds' ),
			'price_with_tax'            => __( 'Regular price incl. tax with currency', 'product-sync-feeds' ),
			'current_price_with_tax'    => __( 'Current price incl. tax with currency', 'product-sync-feeds' ),
			'sale_price_effective_date' => __( 'Sale period (ISO 8601 start/end)', 'product-sync-feeds' ),
			'currency'                  => __( 'Currency code', 'product-sync-feeds' ),
			'is_on_sale'                => __( 'On sale (yes/no)', 'product-sync-feeds' ),
			'weight'                    => __( 'Weight with unit', 'product-sync-feeds' ),
			'shipping_weight'           => __( 'Shipping weight with unit', 'product-sync-feeds' ),
			'length'                    => __( 'Length with unit', 'product-sync-feeds' ),
			'width'                     => __( 'Width with unit', 'product-sync-feeds' ),
			'height'                    => __( 'Height with unit', 'product-sync-feeds' ),
			'shipping_class'            => __( 'Shipping class slug', 'product-sync-feeds' ),
			'tax_class'                 => __( 'Tax class', 'product-sync-feeds' ),
			'variation_attributes'      => __( 'Variation attributes (Color: Red, Size: M)', 'product-sync-feeds' ),
			'rating_average'            => __( 'Average rating', 'product-sync-feeds' ),
			'rating_count'              => __( 'Rating count', 'product-sync-feeds' ),
			'total_sales'               => __( 'Total sales', 'product-sync-feeds' ),
			'date_created'              => __( 'Date created (ISO 8601)', 'product-sync-feeds' ),
			'date_modified'             => __( 'Date modified (ISO 8601)', 'product-sync-feeds' ),
			'visibility'                => __( 'Catalog visibility', 'product-sync-feeds' ),
			'meta:'                     => __( 'Custom meta: meta:{key}', 'product-sync-feeds' ),
			'attr:'                     => __( 'Attribute: attr:{name}, e.g. attr:pa_color', 'product-sync-feeds' ),
			'tax:'                      => __( 'Taxonomy terms: tax:{taxonomy}', 'product-sync-feeds' ),
			'static:'                   => __( 'Fixed text: static:{text}', 'product-sync-feeds' ),
		);
	}

	/**
	 * Resolve a source.
	 *
	 * @param string           $source Source key.
	 * @param \WC_Product      $p      Item (product or variation).
	 * @param \WC_Product|null $parent Parent of a variation.
	 * @param Feed             $feed   Feed.
	 * @return string|string[]
	 */
	public function resolve( string $source, \WC_Product $p, ?\WC_Product $parent, Feed $feed ) {
		$base = $parent ? $parent : $p; // Holder of shared data (terms, descriptions).

		if ( false !== strpos( $source, ':' ) ) {
			list( $kind, $arg ) = explode( ':', $source, 2 );
			switch ( $kind ) {
				case 'static':
					return $arg;
				case 'meta':
					$value = $p->get_meta( $arg, true );
					if ( ( '' === $value || null === $value ) && $parent ) {
						$value = $parent->get_meta( $arg, true );
					}
					return $this->scalarize( $value );
				case 'attr':
					$value = $p->get_attribute( $arg );
					if ( '' === $value && $parent ) {
						$value = $parent->get_attribute( $arg );
					}
					return (string) $value;
				case 'tax':
					return $this->term_names( $base->get_id(), sanitize_key( $arg ) );
			}
			return (string) apply_filters( 'psf_resolve_dynamic_source', '', $kind, $arg, $p, $parent, $feed );
		}

		switch ( $source ) {
			case 'id':
				return (string) $p->get_id();
			case 'parent_id':
				return (string) $p->get_parent_id();
			case 'item_group_id':
				return $parent ? (string) $parent->get_id() : '';
			case 'sku':
				return (string) $p->get_sku();
			case 'parent_sku':
				return $parent ? (string) $parent->get_sku() : (string) $p->get_sku();
			case 'title':
				return (string) $p->get_name();
			case 'parent_title':
				return (string) $base->get_name();
			case 'description':
				$text = (string) $p->get_description();
				if ( '' === trim( $text ) && $parent ) {
					$text = (string) $parent->get_description();
				}
				if ( '' === trim( $text ) ) {
					$text = (string) $base->get_short_description();
				}
				return strip_shortcodes( $text );
			case 'short_description':
				return strip_shortcodes( (string) $base->get_short_description() );
			case 'link':
				return (string) $p->get_permalink();
			case 'image_link':
				$image = $p->get_image_id() ? $p->get_image_id() : ( $parent ? $parent->get_image_id() : 0 );
				return $image ? (string) wp_get_attachment_url( $image ) : '';
			case 'additional_image_link':
				$urls = array();
				foreach ( array_slice( $base->get_gallery_image_ids(), 0, 10 ) as $image_id ) {
					$url = wp_get_attachment_url( $image_id );
					if ( $url ) {
						$urls[] = $url;
					}
				}
				return $urls;
			case 'type':
				return (string) $p->get_type();
			case 'product_type':
				return $this->category_path( $base->get_id() );
			case 'category':
				$names = $this->term_names( $base->get_id(), 'product_cat' );
				return $names ? $names[0] : '';
			case 'categories':
				return $this->term_names( $base->get_id(), 'product_cat' );
			case 'tags':
				return $this->term_names( $base->get_id(), 'product_tag' );
			case 'brand':
				return $this->brand( $p, $base );
			case 'gtin':
				return $this->gtin( $p, $parent );
			case 'condition':
				return (string) apply_filters( 'psf_product_condition', 'new', $p, $feed );
			case 'availability':
				$map = array(
					'instock'     => 'in_stock',
					'outofstock'  => 'out_of_stock',
					'onbackorder' => 'backorder',
				);
				return $map[ $p->get_stock_status() ] ?? 'out_of_stock';
			case 'availability_text':
				$map = array(
					'instock'     => 'in stock',
					'outofstock'  => 'out of stock',
					'onbackorder' => 'available for order',
				);
				return $map[ $p->get_stock_status() ] ?? 'out of stock';
			case 'stock_status':
				return (string) $p->get_stock_status();
			case 'stock_quantity':
				$qty = $p->get_stock_quantity();
				return null === $qty ? '' : (string) $qty;
			case 'price':
				return $this->money( $this->regular_price( $p ), $feed );
			case 'sale_price':
				return $p->is_on_sale() ? $this->money( $this->sale_price( $p ), $feed ) : '';
			case 'current_price':
				return $this->money( $p->get_price(), $feed );
			case 'price_raw':
				return $this->number( $this->regular_price( $p ) );
			case 'sale_price_raw':
				return $p->is_on_sale() ? $this->number( $this->sale_price( $p ) ) : '';
			case 'current_price_raw':
				return $this->number( $p->get_price() );
			case 'price_with_tax':
				$regular = $this->regular_price( $p );
				return '' === $regular ? '' : $this->money( wc_get_price_including_tax( $p, array( 'price' => $regular ) ), $feed );
			case 'current_price_with_tax':
				return '' === (string) $p->get_price() ? '' : $this->money( wc_get_price_including_tax( $p ), $feed );
			case 'sale_price_effective_date':
				$from = $p->get_date_on_sale_from();
				$to   = $p->get_date_on_sale_to();
				return ( $from && $to ) ? $from->format( 'c' ) . '/' . $to->format( 'c' ) : '';
			case 'currency':
				return $this->currency( $feed );
			case 'is_on_sale':
				return $p->is_on_sale() ? 'yes' : 'no';
			case 'weight':
			case 'shipping_weight':
				return $this->measure( $p->get_weight(), get_option( 'woocommerce_weight_unit', 'kg' ) );
			case 'length':
				return $this->measure( $p->get_length(), get_option( 'woocommerce_dimension_unit', 'cm' ) );
			case 'width':
				return $this->measure( $p->get_width(), get_option( 'woocommerce_dimension_unit', 'cm' ) );
			case 'height':
				return $this->measure( $p->get_height(), get_option( 'woocommerce_dimension_unit', 'cm' ) );
			case 'shipping_class':
				return (string) $p->get_shipping_class();
			case 'tax_class':
				return (string) $p->get_tax_class();
			case 'variation_attributes':
				return $p->is_type( 'variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $p, true, true, false ) ) : '';
			case 'rating_average':
				return (string) $base->get_average_rating();
			case 'rating_count':
				return (string) $base->get_rating_count();
			case 'total_sales':
				return (string) $base->get_total_sales();
			case 'date_created':
				return $p->get_date_created() ? $p->get_date_created()->format( 'c' ) : '';
			case 'date_modified':
				return $p->get_date_modified() ? $p->get_date_modified()->format( 'c' ) : '';
			case 'visibility':
				return (string) $base->get_catalog_visibility();
			case '':
				return '';
		}

		return $this->scalarize( apply_filters( 'psf_resolve_source', '', $source, $p, $parent, $feed ) );
	}

	/**
	 * Regular price; variable parents use their cheapest variation.
	 *
	 * @param \WC_Product $p Product.
	 * @return string
	 */
	private function regular_price( \WC_Product $p ): string {
		if ( $p instanceof \WC_Product_Variable ) {
			return (string) $p->get_variation_regular_price( 'min' );
		}
		$regular = (string) $p->get_regular_price();
		return '' !== $regular ? $regular : (string) $p->get_price();
	}

	/**
	 * Sale price; variable parents use their cheapest variation.
	 *
	 * @param \WC_Product $p Product.
	 * @return string
	 */
	private function sale_price( \WC_Product $p ): string {
		if ( $p instanceof \WC_Product_Variable ) {
			return (string) $p->get_variation_sale_price( 'min' );
		}
		return (string) $p->get_sale_price();
	}

	/**
	 * Feed currency.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	private function currency( Feed $feed ): string {
		$currency = (string) $feed->get( 'currency', '' );
		return '' !== $currency ? $currency : get_woocommerce_currency();
	}

	/**
	 * Number with store decimals and a dot separator, '' when empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function number( $value ): string {
		if ( '' === (string) $value || ! is_numeric( $value ) ) {
			return '';
		}
		return number_format( (float) $value, wc_get_price_decimals(), '.', '' );
	}

	/**
	 * "12.00 USD" style value.
	 *
	 * @param mixed $value Value.
	 * @param Feed  $feed  Feed.
	 * @return string
	 */
	private function money( $value, Feed $feed ): string {
		$number = $this->number( $value );
		return '' === $number ? '' : $number . ' ' . $this->currency( $feed );
	}

	/**
	 * "1.5 kg" style value.
	 *
	 * @param mixed  $value Value.
	 * @param string $unit  Unit.
	 * @return string
	 */
	private function measure( $value, string $unit ): string {
		return '' === (string) $value ? '' : $value . ' ' . $unit;
	}

	/**
	 * Term names of a post.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function term_names( int $post_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( static fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $terms ) );
	}

	/**
	 * Deepest category with its ancestors, "A > B > C".
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function category_path( int $post_id ): string {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$best  = null;
		$depth = -1;
		foreach ( $terms as $term ) {
			$d = count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			if ( $d > $depth ) {
				$best  = $term;
				$depth = $d;
			}
		}
		$chain = array_reverse( get_ancestors( $best->term_id, 'product_cat', 'taxonomy' ) );
		$names = array();
		foreach ( $chain as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$names[] = html_entity_decode( $ancestor->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		$names[] = html_entity_decode( $best->name, ENT_QUOTES, 'UTF-8' );
		return implode( ' > ', $names );
	}

	/**
	 * Brand from a brand taxonomy or a brand attribute.
	 *
	 * @param \WC_Product $p    Item.
	 * @param \WC_Product $base Parent or the item.
	 * @return string
	 */
	private function brand( \WC_Product $p, \WC_Product $base ): string {
		foreach ( self::BRAND_TAXONOMIES as $taxonomy ) {
			$names = $this->term_names( $base->get_id(), $taxonomy );
			if ( $names ) {
				return $names[0];
			}
		}
		foreach ( array( 'pa_brand', 'brand' ) as $attribute ) {
			$value = $p->get_attribute( $attribute );
			if ( '' === $value && $base !== $p ) {
				$value = $base->get_attribute( $attribute );
			}
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) apply_filters( 'psf_default_brand', '', $p );
	}

	/**
	 * GTIN from WooCommerce core (9.2+) or common plugin meta keys.
	 *
	 * @param \WC_Product      $p      Item.
	 * @param \WC_Product|null $parent Parent.
	 * @return string
	 */
	private function gtin( \WC_Product $p, ?\WC_Product $parent ): string {
		foreach ( array_filter( array( $p, $parent ) ) as $product ) {
			if ( method_exists( $product, 'get_global_unique_id' ) && '' !== (string) $product->get_global_unique_id() ) {
				return (string) $product->get_global_unique_id();
			}
			foreach ( self::GTIN_META as $key ) {
				$value = $product->get_meta( $key, true );
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					return (string) $value;
				}
			}
		}
		return '';
	}

	/**
	 * Turn meta values into strings or string lists.
	 *
	 * @param mixed $value Value.
	 * @return string|string[]
	 */
	private function scalarize( $value ) {
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$flat[] = (string) $item;
				}
			}
			return $flat;
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
