<?php
/**
 * Run history and live progress (psf_runs table).
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Feed;

use ProductSyncFeeds\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for generation runs.
 */
class RunRepository {

	/** A running run not touched for this long is considered dead. */
	const STALL_SECONDS = 1800;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Installer::table( 'psf_runs' );
	}

	/**
	 * Decode JSON columns on a row.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'state', 'files', 'upload' ) as $col ) {
			$decoded    = $row->$col ? json_decode( $row->$col, true ) : array();
			$row->$col  = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'feed_id', 'seq', 'total', 'processed', 'written', 'upserts', 'deletes', 'cursor_from', 'cursor_to' ) as $col ) {
			$row->$col = (int) $row->$col;
		}
		return $row;
	}

	/**
	 * Create a run.
	 *
	 * @param array $fields Column values.
	 * @return int Run id.
	 */
	public function create( array $fields ): int {
		global $wpdb;
		$now                  = current_time( 'mysql', true );
		$fields['started_at'] = $now;
		$fields['updated_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $this->table(), $this->encode( $fields ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a run.
	 *
	 * @param int $id Run id.
	 * @return object|null
	 */
	public function find( int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ) );
	}

	/**
	 * Update a run.
	 *
	 * @param int   $id     Run id.
	 * @param array $fields Column values (arrays are JSON encoded).
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $this->table(), $this->encode( $fields ), array( 'id' => $id ) );
	}

	/**
	 * The running run of a feed, if any. Stalled runs are failed on the way.
	 *
	 * @param int $feed_id Feed id.
	 * @return object|null
	 */
	public function active_for( int $feed_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $this->hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE feed_id = %d AND status = 'running' ORDER BY id DESC LIMIT 1", $feed_id ) ) );
		if ( ! $row ) {
			return null;
		}
		if ( time() - strtotime( $row->updated_at . ' UTC' ) > self::STALL_SECONDS ) {
			$this->update(
				$row->id,
				array(
					'status'      => 'failed',
					'message'     => __( 'Run stalled: no progress for 30 minutes.', 'product-sync-feeds' ),
					'finished_at' => current_time( 'mysql', true ),
				)
			);
			return null;
		}
		return $row;
	}

	/**
	 * Latest runs of a feed.
	 *
	 * @param int $feed_id Feed id.
	 * @param int $limit   Max rows.
	 * @return object[]
	 */
	public function latest( int $feed_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE feed_id = %d ORDER BY id DESC LIMIT %d", $feed_id, $limit ) );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Last run of a type with a given status.
	 *
	 * @param int    $feed_id Feed id.
	 * @param string $type    full|delta|'' for any.
	 * @return object|null
	 */
	public function last( int $feed_id, string $type = '' ) {
		global $wpdb;
		$sql = "SELECT * FROM {$this->table()} WHERE feed_id = %d";
		$arg = array( $feed_id );
		if ( '' !== $type ) {
			$sql  .= ' AND type = %s';
			$arg[] = $type;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( $sql . ' ORDER BY id DESC LIMIT 1', $arg ) ) );
	}

	/**
	 * Keep the newest $keep runs per feed.
	 *
	 * @param int $feed_id Feed id.
	 * @param int $keep    Rows to keep.
	 */
	public function prune( int $feed_id, int $keep = 200 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE feed_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d", $feed_id, $keep ) );
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE feed_id = %d AND id <= %d AND status <> 'running'", $feed_id, $cutoff ) );
		}
	}

	/**
	 * Delete all runs of a feed.
	 *
	 * @param int $feed_id Feed id.
	 */
	public function delete_for( int $feed_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->table(), array( 'feed_id' => $feed_id ) );
	}

	/**
	 * JSON-encode array columns.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function encode( array $fields ): array {
		foreach ( array( 'state', 'files', 'upload' ) as $col ) {
			if ( isset( $fields[ $col ] ) && is_array( $fields[ $col ] ) ) {
				$fields[ $col ] = wp_json_encode( $fields[ $col ] );
			}
		}
		return $fields;
	}
}
