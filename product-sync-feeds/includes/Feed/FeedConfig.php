<?php
/**
 * Feed configuration schema: defaults, normalization and form sanitization.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Feed;

use ProductSyncFeeds\Product\ConditionEvaluator;
use ProductSyncFeeds\Product\RowBuilder;
use ProductSyncFeeds\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration schema.
 */
final class FeedConfig {

	const FORMATS    = array( 'xml', 'csv', 'tsv', 'json', 'jsonl' );
	const VARIATIONS = array( 'both', 'parents', 'variations' );
	const STRATEGIES = array( 'changes', 'compare' );
	const PROTOCOLS  = array( 'ftp', 'ftps', 'sftp' );
	const UPLOADS    = array( 'full', 'delta', 'both' );

	/** Minimum minutes between scheduled runs. */
	const MIN_FULL_INTERVAL  = 5;
	const MIN_DELTA_INTERVAL = 1;

	/**
	 * Interval presets shown in the admin, in minutes.
	 *
	 * @return array<int,string>
	 */
	public static function interval_presets(): array {
		return array(
			1     => __( 'Every minute', 'product-sync-feeds' ),
			5     => __( 'Every 5 minutes', 'product-sync-feeds' ),
			10    => __( 'Every 10 minutes', 'product-sync-feeds' ),
			15    => __( 'Every 15 minutes', 'product-sync-feeds' ),
			30    => __( 'Every 30 minutes', 'product-sync-feeds' ),
			45    => __( 'Every 45 minutes', 'product-sync-feeds' ),
			60    => __( 'Every hour', 'product-sync-feeds' ),
			120   => __( 'Every 2 hours', 'product-sync-feeds' ),
			360   => __( 'Every 6 hours', 'product-sync-feeds' ),
			720   => __( 'Every 12 hours', 'product-sync-feeds' ),
			1440  => __( 'Every 24 hours', 'product-sync-feeds' ),
			10080 => __( 'Every week', 'product-sync-feeds' ),
		);
	}

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'channel'    => 'google',
			'format'     => 'xml',
			'country'    => '',
			'currency'   => '',
			'xml'        => array(
				'root'       => 'products',
				'item'       => 'product',
				'skip_empty' => true,
			),
			'csv'        => array(
				'delimiter' => ',',
				'enclosure' => '"',
				'bom'       => false,
			),
			'mapping'    => Channels::default_mapping( 'google' ),
			'variations' => 'both',
			'filters'    => array(
				'post_status'               => array( 'publish' ),
				'product_types'             => array(),
				'stock_status'              => array(),
				'exclude_hidden'            => true,
				'categories_mode'           => 'include',
				'categories'                => array(),
				'tags_mode'                 => 'include',
				'tags'                      => array(),
				'ids_mode'                  => 'include',
				'ids'                       => array(),
				'exclude_empty_price'       => true,
				'exclude_empty_image'       => false,
				'exclude_empty_description' => false,
				'price_min'                 => '',
				'price_max'                 => '',
				'conditions_match'          => 'all',
				'conditions'                => array(),
			),
			'schedule'   => array(
				'full_enabled'   => true,
				'full_interval'  => 1440,
				'delta_interval' => 15,
			),
			'delta'      => array(
				'enabled'          => false,
				'strategy'         => 'changes',
				'action_field'     => 'sync_action',
				'full_emits_delta' => true,
				'keep_files'       => 50,
				'tombstone_days'   => 30,
			),
			'upload'     => array(
				'enabled'      => false,
				'protocol'     => 'ftp',
				'host'         => '',
				'port'         => 0,
				'username'     => '',
				'password'     => '',
				'path'         => '/',
				'passive'      => true,
				'verify_ssl'   => true,
				'host_key_md5' => '',
				'files'        => 'full',
				'timeout'      => 120,
			),
			'batch_size' => 200,
		);
	}

	/**
	 * Fill missing keys with defaults and coerce types (used on load).
	 *
	 * @param array $config Raw config.
	 * @return array
	 */
	public static function normalize( array $config ): array {
		$defaults = self::defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $config ) ) {
				$out[ $key ] = $default;
			} elseif ( is_array( $default ) && in_array( $key, array( 'xml', 'csv', 'filters', 'schedule', 'delta', 'upload' ), true ) ) {
				$out[ $key ] = is_array( $config[ $key ] ) ? array_merge( $default, $config[ $key ] ) : $default;
			} else {
				$out[ $key ] = $config[ $key ];
			}
		}

		$out['mapping']                 = is_array( $out['mapping'] ) ? array_values( $out['mapping'] ) : array();
		$out['filters']['conditions']   = is_array( $out['filters']['conditions'] ) ? array_values( $out['filters']['conditions'] ) : array();
		$out['schedule']['full_interval']  = max( self::MIN_FULL_INTERVAL, (int) $out['schedule']['full_interval'] );
		$out['schedule']['delta_interval'] = max( self::MIN_DELTA_INTERVAL, (int) $out['schedule']['delta_interval'] );
		$out['batch_size']              = max( 10, min( 2000, (int) $out['batch_size'] ) );

		return $out;
	}

	/**
	 * Sanitize the posted admin form into a config array.
	 *
	 * @param array $input    Raw $_POST['config'] (already unslashed).
	 * @param array $previous Previous config, used to keep the saved password.
	 * @return array
	 */
	public static function sanitize( array $input, array $previous = array() ): array {
		$d = self::defaults();
		$c = array();

		$channel      = sanitize_key( $input['channel'] ?? $d['channel'] );
		$c['channel'] = array_key_exists( $channel, Channels::all() ) ? $channel : 'custom';
		$c['format']  = self::choice( $input['format'] ?? '', self::FORMATS, $d['format'] );
		$c['country'] = strtoupper( substr( sanitize_text_field( $input['country'] ?? '' ), 0, 2 ) );
		$c['currency'] = strtoupper( substr( sanitize_text_field( $input['currency'] ?? '' ), 0, 3 ) );

		$xml      = (array) ( $input['xml'] ?? array() );
		$c['xml'] = array(
			'root'       => self::xml_name( $xml['root'] ?? '', 'products' ),
			'item'       => self::xml_name( $xml['item'] ?? '', 'product' ),
			'skip_empty' => ! empty( $xml['skip_empty'] ),
		);

		$csv      = (array) ( $input['csv'] ?? array() );
		$delim    = (string) ( $csv['delimiter'] ?? ',' );
		$c['csv'] = array(
			'delimiter' => in_array( $delim, array( ',', ';', '|', 'tab' ), true ) ? $delim : ',',
			'enclosure' => self::choice( $csv['enclosure'] ?? '', array( '"', "'" ), '"' ),
			'bom'       => ! empty( $csv['bom'] ),
		);

		$c['mapping'] = array();
		foreach ( (array) ( $input['mapping'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$target = self::field_name( $row['target'] ?? '' );
			if ( '' === $target ) {
				continue;
			}
			$transforms = array_values( array_intersect( (array) ( $row['transforms'] ?? array() ), array_keys( RowBuilder::transforms() ) ) );
			$c['mapping'][] = array(
				'target'     => $target,
				'source'     => sanitize_text_field( $row['source'] ?? '' ),
				'prefix'     => sanitize_text_field( $row['prefix'] ?? '' ),
				'suffix'     => sanitize_text_field( $row['suffix'] ?? '' ),
				'default'    => sanitize_text_field( $row['default'] ?? '' ),
				'limit'      => max( 0, (int) ( $row['limit'] ?? 0 ) ),
				'transforms' => $transforms,
			);
		}

		$c['variations'] = self::choice( $input['variations'] ?? '', self::VARIATIONS, $d['variations'] );

		$f            = (array) ( $input['filters'] ?? array() );
		$c['filters'] = array(
			'post_status'               => self::list_of( $f['post_status'] ?? array( 'publish' ), array( 'publish', 'private', 'draft', 'pending' ) ),
			'product_types'             => self::list_of( $f['product_types'] ?? array(), array_keys( wc_get_product_types() ) ),
			'stock_status'              => self::list_of( $f['stock_status'] ?? array(), array_keys( wc_get_product_stock_status_options() ) ),
			'exclude_hidden'            => ! empty( $f['exclude_hidden'] ),
			'categories_mode'           => self::choice( $f['categories_mode'] ?? '', array( 'include', 'exclude' ), 'include' ),
			'categories'                => self::id_list( $f['categories'] ?? array() ),
			'tags_mode'                 => self::choice( $f['tags_mode'] ?? '', array( 'include', 'exclude' ), 'include' ),
			'tags'                      => self::id_list( $f['tags'] ?? array() ),
			'ids_mode'                  => self::choice( $f['ids_mode'] ?? '', array( 'include', 'exclude' ), 'include' ),
			'ids'                       => self::id_list( is_string( $f['ids'] ?? null ) ? preg_split( '/[\s,]+/', $f['ids'] ) : ( $f['ids'] ?? array() ) ),
			'exclude_empty_price'       => ! empty( $f['exclude_empty_price'] ),
			'exclude_empty_image'       => ! empty( $f['exclude_empty_image'] ),
			'exclude_empty_description' => ! empty( $f['exclude_empty_description'] ),
			'price_min'                 => self::decimal( $f['price_min'] ?? '' ),
			'price_max'                 => self::decimal( $f['price_max'] ?? '' ),
			'conditions_match'          => self::choice( $f['conditions_match'] ?? '', array( 'all', 'any' ), 'all' ),
			'conditions'                => array(),
		);
		foreach ( (array) ( $f['conditions'] ?? array() ) as $cond ) {
			if ( ! is_array( $cond ) || '' === trim( (string) ( $cond['source'] ?? '' ) ) ) {
				continue;
			}
			$c['filters']['conditions'][] = array(
				'source'   => sanitize_text_field( $cond['source'] ),
				'operator' => self::choice( $cond['operator'] ?? '', array_keys( ConditionEvaluator::operators() ), 'equals' ),
				'value'    => sanitize_text_field( $cond['value'] ?? '' ),
			);
		}

		$s             = (array) ( $input['schedule'] ?? array() );
		$c['schedule'] = array(
			'full_enabled'   => ! empty( $s['full_enabled'] ),
			'full_interval'  => max( self::MIN_FULL_INTERVAL, (int) ( $s['full_interval'] ?? $d['schedule']['full_interval'] ) ),
			'delta_interval' => max( self::MIN_DELTA_INTERVAL, (int) ( $s['delta_interval'] ?? $d['schedule']['delta_interval'] ) ),
		);

		$dl         = (array) ( $input['delta'] ?? array() );
		$c['delta'] = array(
			'enabled'          => ! empty( $dl['enabled'] ),
			'strategy'         => self::choice( $dl['strategy'] ?? '', self::STRATEGIES, 'changes' ),
			'action_field'     => self::field_name( $dl['action_field'] ?? '' ) ?: 'sync_action',
			'full_emits_delta' => ! empty( $dl['full_emits_delta'] ),
			'keep_files'       => max( 1, min( 1000, (int) ( $dl['keep_files'] ?? 50 ) ) ),
			'tombstone_days'   => max( 1, min( 365, (int) ( $dl['tombstone_days'] ?? 30 ) ) ),
		);

		$u        = (array) ( $input['upload'] ?? array() );
		$password = (string) ( $u['password'] ?? '' );
		if ( '' === $password && empty( $u['clear_password'] ) ) {
			$password = (string) ( $previous['upload']['password'] ?? '' ); // Already encrypted.
		} elseif ( '' !== $password ) {
			$password = Crypto::encrypt( $password );
		}
		$c['upload'] = array(
			'enabled'      => ! empty( $u['enabled'] ),
			'protocol'     => self::choice( $u['protocol'] ?? '', self::PROTOCOLS, 'ftp' ),
			'host'         => preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) ( $u['host'] ?? '' ) ),
			'port'         => max( 0, min( 65535, (int) ( $u['port'] ?? 0 ) ) ),
			'username'     => sanitize_text_field( $u['username'] ?? '' ),
			'password'     => $password,
			'path'         => '/' . trim( str_replace( '\\', '/', sanitize_text_field( $u['path'] ?? '/' ) ), '/' ),
			'passive'      => ! empty( $u['passive'] ),
			'verify_ssl'   => ! empty( $u['verify_ssl'] ),
			'host_key_md5' => strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) ( $u['host_key_md5'] ?? '' ) ) ),
			'files'        => self::choice( $u['files'] ?? '', self::UPLOADS, 'full' ),
			'timeout'      => max( 10, min( 1800, (int) ( $u['timeout'] ?? 120 ) ) ),
		);

		$c['batch_size'] = max( 10, min( 2000, (int) ( $input['batch_size'] ?? 200 ) ) );

		return self::normalize( $c );
	}

	/**
	 * Pick a value from an allowed list.
	 *
	 * @param mixed  $value    Input.
	 * @param array  $allowed  Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function choice( $value, array $allowed, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Filter a list down to allowed values.
	 *
	 * @param mixed $values  Input.
	 * @param array $allowed Allowed values.
	 * @return string[]
	 */
	private static function list_of( $values, array $allowed ): array {
		return array_values( array_intersect( array_map( 'strval', (array) $values ), $allowed ) );
	}

	/**
	 * Positive integer list.
	 *
	 * @param mixed $values Input.
	 * @return int[]
	 */
	private static function id_list( $values ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $values ) ) ) );
	}

	/**
	 * Decimal string or ''.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	private static function decimal( $value ): string {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? $value : '';
	}

	/**
	 * Output field name: letters, digits, _ - . and one optional namespace colon.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function field_name( $value ): string {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9_\-.:]/', '_', $value );
		return substr( (string) $value, 0, 100 );
	}

	/**
	 * XML element name.
	 *
	 * @param mixed  $value    Input.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function xml_name( $value, string $fallback ): string {
		$value = self::field_name( $value );
		return preg_match( '/^[A-Za-z_][A-Za-z0-9_\-.:]*$/', $value ) ? $value : $fallback;
	}
}
