<?php
/**
 * CSV / TSV writer. List values are joined with commas.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Delimited text writer.
 */
class CsvWriter implements Writer {

	/** @var string */
	private $delimiter;

	/** @var string */
	private $enclosure;

	/** @var bool */
	private $bom;

	/** @var string[] */
	private $columns = array();

	/**
	 * Constructor.
	 *
	 * @param string   $delimiter Delimiter.
	 * @param string   $enclosure Enclosure.
	 * @param bool     $bom       Write a UTF-8 BOM.
	 * @param string[] $columns   Columns (needed by append in later requests).
	 */
	public function __construct( string $delimiter, string $enclosure, bool $bom, array $columns ) {
		$this->delimiter = $delimiter;
		$this->enclosure = $enclosure;
		$this->bom       = $bom;
		$this->columns   = $columns;
	}

	/** {@inheritDoc} */
	public function begin( string $path, array $columns ): void {
		$this->columns = $columns;
		$fh            = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot write ' . $path );
		}
		if ( $this->bom ) {
			fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fputcsv( $fh, $columns, $this->delimiter, $this->enclosure, '\\' );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** {@inheritDoc} */
	public function append( string $path, array $rows, int $written ): void {
		if ( ! $rows ) {
			return;
		}
		$fh = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			throw new \RuntimeException( 'Cannot append to ' . $path );
		}
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $this->columns as $column ) {
				$value  = $row[ $column ] ?? '';
				$value  = is_array( $value ) ? implode( ',', $value ) : (string) $value;
				$line[] = str_replace( array( "\r\n", "\r", "\n" ), ' ', $value );
			}
			fputcsv( $fh, $line, $this->delimiter, $this->enclosure, '\\' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** {@inheritDoc} */
	public function finish( string $path, int $written ): void {
		// Nothing to close in delimited files.
	}
}
