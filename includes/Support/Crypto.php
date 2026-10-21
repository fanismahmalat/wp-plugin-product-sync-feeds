<?php
/**
 * Encrypts stored upload passwords with a key derived from the site salts.
 *
 * Changing the AUTH salts makes stored passwords unreadable; they then need
 * to be entered again.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption helper.
 */
final class Crypto {

	const CIPHER = 'aes-256-cbc';
	const MARKER = 'psf1:';

	/**
	 * Encryption key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'product-sync-feeds', true );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}
		return self::MARKER . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::MARKER ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$raw   = base64_decode( substr( $stored, strlen( self::MARKER ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $raw || strlen( $raw ) <= $ivlen ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, $ivlen ), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, $ivlen ) );
		return false === $plain ? '' : $plain;
	}
}
