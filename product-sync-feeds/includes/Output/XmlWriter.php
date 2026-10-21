<?php
/**
 * XML writer for RSS 2.0 channel feeds (g: namespace) and custom XML.
 *
 * List values become repeated elements, e.g. several <g:additional_image_link>.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Output;

defined( 'ABSPATH' ) || exit;

/**
 * XML writer.
 */
class XmlWriter implements Writer {

	/** @var array{mode:string,prefix:string,root:string,item:string,skip_empty:bool,title:string,link:string,description:string,raw_columns:string[]} */
	private $opts;

	/**
	 * Constructor.
	 *
	 * @param array $opts mode (rss|custom), prefix, root, item, skip_empty, title, link, description, raw_columns (written without prefix).
	 */
	public function __construct( array $opts ) {
		$this->opts = array_merge(
			array(
				'mode'        => 'custom',
				'prefix'      => '',
				'root'        => 'products',
				'item'        => 'product',
				'skip_empty'  => true,
				'title'       => '',
				'link'        => '',
				'description' => '',
				'raw_columns' => array(),
			),
			$opts
		);
	}

	/** {@inheritDoc} */
	public function begin( string $path, array $columns ): void {
		$o = $this->opts;
		if ( 'rss' === $o['mode'] ) {
			$head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n"
				. '<title>' . $this->esc( $o['title'] ) . "</title>\n"
				. '<link>' . $this->esc( $o['link'] ) . "</link>\n"
				. '<description>' . $this->esc( $o['description'] ) . "</description>\n";
		} else {
			$head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<" . $o['root'] . ">\n";
		}
		if ( false === file_put_contents( $path, $head ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'Cannot write ' . $path );
		}
	}

	/** {@inheritDoc} */
	public function append( string $path, array $rows, int $written ): void {
		if ( ! $rows ) {
			return;
		}
		$item = 'rss' === $this->opts['mode'] ? 'item' : $this->opts['item'];
		$out  = '';
		foreach ( $rows as $row ) {
			$out .= '<' . $item . ">\n";
			foreach ( $row as $column => $value ) {
				$name = $this->element( (string) $column );
				foreach ( is_array( $value ) ? $value : array( $value ) as $v ) {
					$v = (string) $v;
					if ( '' === trim( $v ) && $this->opts['skip_empty'] ) {
						continue;
					}
					$out .= "\t<" . $name . '>' . $this->esc( $v ) . '</' . $name . ">\n";
				}
			}
			$out .= '</' . $item . ">\n";
		}
		if ( false === file_put_contents( $path, $out, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( 'Cannot append to ' . $path );
		}
	}

	/** {@inheritDoc} */
	public function finish( string $path, int $written ): void {
		$foot = 'rss' === $this->opts['mode'] ? "</channel>\n</rss>\n" : '</' . $this->opts['root'] . ">\n";
		file_put_contents( $path, $foot, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Element name for a column: channel prefix unless already namespaced or raw.
	 *
	 * @param string $column Column.
	 * @return string
	 */
	private function element( string $column ): string {
		if ( false !== strpos( $column, ':' ) || in_array( $column, $this->opts['raw_columns'], true ) ) {
			return $column;
		}
		return $this->opts['prefix'] . $column;
	}

	/**
	 * Escape text and drop characters XML 1.0 forbids.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function esc( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
