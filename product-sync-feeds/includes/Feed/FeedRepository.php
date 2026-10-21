<?php
/**
 * Feed persistence (psf_feeds table).
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Feed;

use ProductSyncFeeds\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for feeds.
 */
class FeedRepository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Installer::table( 'psf_feeds' );
	}

	/**
	 * Find a feed.
	 *
	 * @param int $id Feed id.
	 * @return Feed|null
	 */
	public function find( int $id ): ?Feed {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		return $row ? Feed::from_row( $row ) : null;
	}

	/**
	 * All feeds ordered by name.
	 *
	 * @return Feed[]
	 */
	public function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY name ASC, id ASC" );
		return array_map( array( Feed::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Create a feed.
	 *
	 * @param string $name   Feed name.
	 * @param array  $config Sanitized config.
	 * @param int    $status 1 active, 0 paused.
	 * @return int New feed id, 0 on failure.
	 */
	public function create( string $name, array $config, int $status = 1 ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$this->table(),
			array(
				'name'       => $name,
				'slug'       => $this->unique_slug( $name ),
				'status'     => $status,
				'config'     => wp_json_encode( $config ),
				'api_key'    => wp_generate_password( 32, false, false ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Save name, status and config. The slug never changes so URLs stay stable.
	 *
	 * @param Feed $feed Feed.
	 * @return bool
	 */
	public function save( Feed $feed ): bool {
		return $this->update_fields(
			$feed->id,
			array(
				'name'   => $feed->name,
				'status' => $feed->status,
				'config' => wp_json_encode( $feed->config ),
			)
		);
	}

	/**
	 * Update raw columns.
	 *
	 * @param int   $id     Feed id.
	 * @param array $fields Column => value.
	 * @return bool
	 */
	public function update_fields( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Delete a feed row.
	 *
	 * @param int $id Feed id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $this->table(), array( 'id' => $id ) );
	}

	/**
	 * A slug not used by another feed.
	 *
	 * @param string $name Feed name.
	 * @return string
	 */
	private function unique_slug( string $name ): string {
		global $wpdb;
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'feed';
		}
		$slug = $base;
		$i    = 2;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE slug = %s", $slug ) ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}
}
