<?php
/**
 * Thin wrapper around the WooCommerce logger (WooCommerce > Status > Logs, source "product-sync-feeds").
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {

	const SOURCE = 'product-sync-feeds';

	/**
	 * Write a log line.
	 *
	 * @param string $level   info|warning|error|debug.
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( $context ) {
			$message .= ' ' . wp_json_encode( $context );
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Info line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Warning line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Error line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}
}
