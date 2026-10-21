<?php
/**
 * Channel templates: default formats, field mappings and XML envelopes.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in channel profiles. Extend with the `psf_channels` filter.
 */
final class Channels {

	/**
	 * All channels.
	 *
	 * Keys per channel: label, formats, xml (rss|custom), xml_prefix, mapping
	 * (list of [target, source, transforms]).
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		$clean = array( 'strip_tags', 'collapse_spaces' );

		$channels = array(
			'google'    => array(
				'label'      => __( 'Google Merchant Center', 'product-sync-feeds' ),
				'formats'    => array( 'xml', 'csv', 'tsv' ),
				'xml'        => 'rss',
				'xml_prefix' => 'g:',
				'mapping'    => array(
					array( 'id', 'id' ),
					array( 'item_group_id', 'item_group_id' ),
					array( 'title', 'title', $clean ),
					array( 'description', 'description', $clean ),
					array( 'link', 'link' ),
					array( 'image_link', 'image_link' ),
					array( 'additional_image_link', 'additional_image_link' ),
					array( 'availability', 'availability' ),
					array( 'price', 'price' ),
					array( 'sale_price', 'sale_price' ),
					array( 'sale_price_effective_date', 'sale_price_effective_date' ),
					array( 'brand', 'brand' ),
					array( 'gtin', 'gtin' ),
					array( 'mpn', 'sku' ),
					array( 'condition', 'condition' ),
					array( 'product_type', 'product_type' ),
					array( 'shipping_weight', 'shipping_weight' ),
				),
			),
			'meta'      => array(
				'label'      => __( 'Meta (Facebook & Instagram) Catalog', 'product-sync-feeds' ),
				'formats'    => array( 'csv', 'xml', 'tsv' ),
				'xml'        => 'rss',
				'xml_prefix' => 'g:',
				'mapping'    => array(
					array( 'id', 'id' ),
					array( 'item_group_id', 'item_group_id' ),
					array( 'title', 'title', $clean ),
					array( 'description', 'description', $clean ),
					array( 'availability', 'availability_text' ),
					array( 'condition', 'condition' ),
					array( 'price', 'price' ),
					array( 'sale_price', 'sale_price' ),
					array( 'link', 'link' ),
					array( 'image_link', 'image_link' ),
					array( 'additional_image_link', 'additional_image_link' ),
					array( 'brand', 'brand' ),
					array( 'quantity_to_sell_on_facebook', 'stock_quantity' ),
				),
			),
			'pinterest' => array(
				'label'      => __( 'Pinterest Catalog', 'product-sync-feeds' ),
				'formats'    => array( 'csv', 'tsv', 'xml' ),
				'xml'        => 'rss',
				'xml_prefix' => 'g:',
				'mapping'    => array(
					array( 'id', 'id' ),
					array( 'item_group_id', 'item_group_id' ),
					array( 'title', 'title', $clean ),
					array( 'description', 'description', $clean ),
					array( 'link', 'link' ),
					array( 'image_link', 'image_link' ),
					array( 'additional_image_link', 'additional_image_link' ),
					array( 'price', 'price' ),
					array( 'sale_price', 'sale_price' ),
					array( 'availability', 'availability_text' ),
					array( 'product_type', 'product_type' ),
					array( 'brand', 'brand' ),
				),
			),
			'tiktok'    => array(
				'label'      => __( 'TikTok Catalog', 'product-sync-feeds' ),
				'formats'    => array( 'csv', 'xml' ),
				'xml'        => 'rss',
				'xml_prefix' => 'g:',
				'mapping'    => array(
					array( 'sku_id', 'id' ),
					array( 'item_group_id', 'item_group_id' ),
					array( 'title', 'title', $clean ),
					array( 'description', 'description', $clean ),
					array( 'availability', 'availability_text' ),
					array( 'condition', 'condition' ),
					array( 'price', 'price' ),
					array( 'sale_price', 'sale_price' ),
					array( 'link', 'link' ),
					array( 'image_link', 'image_link' ),
					array( 'brand', 'brand' ),
				),
			),
			'custom'    => array(
				'label'      => __( 'Custom (any channel or integration)', 'product-sync-feeds' ),
				'formats'    => array( 'xml', 'csv', 'tsv', 'json', 'jsonl' ),
				'xml'        => 'custom',
				'xml_prefix' => '',
				'mapping'    => array(
					array( 'id', 'id' ),
					array( 'parent_id', 'parent_id' ),
					array( 'sku', 'sku' ),
					array( 'title', 'title' ),
					array( 'price', 'price_raw' ),
					array( 'sale_price', 'sale_price_raw' ),
					array( 'currency', 'currency' ),
					array( 'stock_status', 'stock_status' ),
					array( 'stock_quantity', 'stock_quantity' ),
					array( 'link', 'link' ),
					array( 'image_link', 'image_link' ),
					array( 'categories', 'categories' ),
					array( 'date_modified', 'date_modified' ),
				),
			),
		);

		return (array) apply_filters( 'psf_channels', $channels );
	}

	/**
	 * Channel profile, falling back to custom.
	 *
	 * @param string $channel Channel id.
	 * @return array
	 */
	public static function get( string $channel ): array {
		$all = self::all();
		return $all[ $channel ] ?? $all['custom'];
	}

	/**
	 * Default mapping rows for a channel, in config shape.
	 *
	 * @param string $channel Channel id.
	 * @return array
	 */
	public static function default_mapping( string $channel ): array {
		$rows = array();
		foreach ( self::get( $channel )['mapping'] as $row ) {
			$rows[] = array(
				'target'     => $row[0],
				'source'     => $row[1],
				'prefix'     => '',
				'suffix'     => '',
				'default'    => 'condition' === $row[1] ? 'new' : '',
				'limit'      => 'title' === $row[0] ? 150 : ( 'description' === $row[0] ? 5000 : 0 ),
				'transforms' => $row[2] ?? array(),
			);
		}
		return $rows;
	}
}
