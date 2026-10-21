<?php
/**
 * Feed value object.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * A stored feed and its configuration.
 */
class Feed {

	/** @var int */
	public $id = 0;

	/** @var string */
	public $name = '';

	/** @var string */
	public $slug = '';

	/** @var int 1 = active (scheduled), 0 = paused. */
	public $status = 1;

	/** @var array Normalized configuration, see FeedConfig::defaults(). */
	public $config = array();

	/** @var string Key that lets external systems read the changes API. */
	public $api_key = '';

	/** @var int Unix time up to which changes have been synced. */
	public $delta_cursor = 0;

	/** @var string|null GMT datetime of the last completed full run. */
	public $last_full_at = null;

	/** @var string|null GMT datetime of the last completed delta run. */
	public $last_delta_at = null;

	/**
	 * Build from a database row.
	 *
	 * @param object $row Row from psf_feeds.
	 * @return Feed
	 */
	public static function from_row( $row ): Feed {
		$feed                = new self();
		$feed->id            = (int) $row->id;
		$feed->name          = (string) $row->name;
		$feed->slug          = (string) $row->slug;
		$feed->status        = (int) $row->status;
		$decoded             = json_decode( (string) $row->config, true );
		$feed->config        = FeedConfig::normalize( is_array( $decoded ) ? $decoded : array() );
		$feed->api_key       = (string) $row->api_key;
		$feed->delta_cursor  = (int) $row->delta_cursor;
		$feed->last_full_at  = $row->last_full_at ? (string) $row->last_full_at : null;
		$feed->last_delta_at = $row->last_delta_at ? (string) $row->last_delta_at : null;
		return $feed;
	}

	/**
	 * Read a config value by dot path, e.g. "delta.strategy".
	 *
	 * @param string $path     Dot path.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$value = $this->config;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $fallback;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * Output format.
	 *
	 * @return string
	 */
	public function format(): string {
		return (string) $this->get( 'format', 'xml' );
	}

	/**
	 * Whether delta output is enabled for this feed.
	 *
	 * @return bool
	 */
	public function delta_enabled(): bool {
		return (bool) $this->get( 'delta.enabled', false );
	}
}
