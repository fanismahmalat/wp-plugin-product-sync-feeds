<?php
/**
 * Streaming writer contract. A run calls begin() once, append() once per batch
 * (possibly in different PHP requests) and finish() once.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Feed file writer.
 */
interface Writer {

	/**
	 * Create the file and write the header.
	 *
	 * @param string   $path    File path (truncated).
	 * @param string[] $columns Column names in order.
	 */
	public function begin( string $path, array $columns ): void;

	/**
	 * Append rows.
	 *
	 * @param string $path    File path.
	 * @param array  $rows    Rows (column => string|string[]).
	 * @param int    $written Rows already in the file before this call.
	 */
	public function append( string $path, array $rows, int $written ): void;

	/**
	 * Write the footer.
	 *
	 * @param string $path    File path.
	 * @param int    $written Total rows in the file.
	 */
	public function finish( string $path, int $written ): void;
}
