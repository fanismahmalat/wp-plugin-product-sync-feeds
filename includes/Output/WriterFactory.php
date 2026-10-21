<?php
/**
 * Builds the writer for a feed.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Output;

use ProductSyncFeeds\Feed\Channels;
use ProductSyncFeeds\Feed\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Writer factory.
 */
final class WriterFactory {

	/**
	 * Writer for a feed.
	 *
	 * @param Feed     $feed        Feed.
	 * @param string[] $columns     Columns in order.
	 * @param string[] $raw_columns Columns written without the channel XML prefix (the delta action field).
	 * @return Writer
	 */
	public static function make( Feed $feed, array $columns, array $raw_columns = array() ): Writer {
		switch ( $feed->format() ) {
			case 'csv':
			case 'tsv':
				$delimiter = 'tsv' === $feed->format() ? "\t" : (string) $feed->get( 'csv.delimiter', ',' );
				if ( 'tab' === $delimiter ) {
					$delimiter = "\t";
				}
				return new CsvWriter( $delimiter, (string) $feed->get( 'csv.enclosure', '"' ), (bool) $feed->get( 'csv.bom', false ), $columns );
			case 'json':
				return new JsonWriter( false );
			case 'jsonl':
				return new JsonWriter( true );
			default:
				$channel = Channels::get( (string) $feed->get( 'channel' ) );
				return new XmlWriter(
					array(
						'mode'        => $channel['xml'] ?? 'custom',
						'prefix'      => $channel['xml_prefix'] ?? '',
						'root'        => (string) $feed->get( 'xml.root', 'products' ),
						'item'        => (string) $feed->get( 'xml.item', 'product' ),
						'skip_empty'  => (bool) $feed->get( 'xml.skip_empty', true ),
						'title'       => get_bloginfo( 'name' ),
						'link'        => home_url( '/' ),
						'description' => $feed->name,
						'raw_columns' => $raw_columns,
					)
				);
		}
	}
}
