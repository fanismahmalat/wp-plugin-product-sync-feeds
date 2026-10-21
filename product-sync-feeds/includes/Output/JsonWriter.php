<?php
/**
 * JSON array or JSON Lines writer.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Output;

defined( 'ABSPATH' ) || exit;

/**
 * JSON writer.
 */
class JsonWriter implements Writer {

	/** @var bool True for JSON Lines (one object per line). */
	private $lines;

	/**
	 * Constructor.
	 *
	 * @param bool $lines JSON Lines mode.
	 */
	public function __construct( bool $lines ) {
		$this->lines = $lines;
	}

	/** {@inheritDoc} */
	public function begin( string $path, array $columns ): void {
		if ( false === file_put_contents( $path, $this->lines ? '' : '[' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'Cannot write ' . $path );
		}
	}

	/** {@inheritDoc} */
	public function append( string $path, array $rows, int $written ): void {
		if ( ! $rows ) {
			return;
		}
		$chunks = array();
		foreach ( $rows as $row ) {
			$chunks[] = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( $this->lines ) {
			$out = implode( "\n", $chunks ) . "\n";
		} else {
			$out = ( $written > 0 ? ',' : '' ) . "\n" . implode( ",\n", $chunks );
		}
		if ( false === file_put_contents( $path, $out, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'Cannot append to ' . $path );
		}
	}

	/** {@inheritDoc} */
	public function finish( string $path, int $written ): void {
		if ( ! $this->lines ) {
			file_put_contents( $path, "\n]\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
