<?php
/**
 * Per-feed sync state (psf_items): what each channel was last sent.
 *
 * One row per feed item with the hash of its last written row. Comparing a
 * freshly built row against the stored hash is what turns a run into a delta:
 * new or different hash = upsert, item gone or filtered out = delete. Deleted
 * items stay as tombstones (state = deleted) for the changes API until they
 * age out.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Sync;

use ProductSyncFeeds\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Item state persistence and diffing.
 */
class StateStore {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Installer::table( 'psf_items' );
	}

	/**
	 * Hash of a row.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function hash( array $row ): string {
		return md5( (string) wp_json_encode( $row ) );
	}

	/**
	 * Parent ids the feed recorded for items.
	 *
	 * @param int   $feed_id  Feed id.
	 * @param int[] $item_ids Item ids.
	 * @return array<int,int>
	 */
	public function parents_of( int $feed_id, array $item_ids ): array {
		global $wpdb;
		if ( ! $item_ids ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', $item_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, parent_id FROM {$this->table()} WHERE feed_id = %d AND item_id IN ({$in})", $feed_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->item_id ] = (int) $row->parent_id ? (int) $row->parent_id : (int) $row->item_id;
		}
		return $out;
	}

	/**
	 * Diff a batch of product families against the stored state and persist the result.
	 *
	 * @param int   $feed_id  Feed id.
	 * @param array $families parent_id => RowBuilder::family() result (may be empty).
	 * @param int   $run_id   Run id.
	 * @param int   $now      Unix time stamped on changed items.
	 * @return array{upserts:array<int,array{key:string,row:array}>,deletes:array<int,string>}
	 *         Deletes are item keys; a key change yields a delete of the old key plus an upsert.
	 */
	public function reconcile( int $feed_id, array $families, int $run_id, int $now ): array {
		global $wpdb;

		$result = array(
			'upserts' => array(),
			'deletes' => array(),
		);
		if ( ! $families ) {
			return $result;
		}

		$parent_ids = array_map( 'intval', array_keys( $families ) );
		$in         = implode( ',', $parent_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer list.
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, parent_id, item_key, hash, state FROM {$this->table()} WHERE feed_id = %d AND ( parent_id IN ({$in}) OR item_id IN ({$in}) )", $feed_id ), OBJECT_K );

		$upsert_rows = array();
		$unchanged   = array();
		$tombstone   = array();
		$produced    = array();

		foreach ( $families as $rows ) {
			foreach ( $rows as $item_id => $entry ) {
				$item_id              = (int) $item_id;
				$produced[ $item_id ] = true;
				$hash                 = self::hash( $entry['row'] );
				$old                  = $existing[ $item_id ] ?? null;
				$active               = $old && 'active' === $old->state;

				if ( $active && $old->hash === $hash && $old->item_key === $entry['key'] ) {
					$unchanged[] = $item_id;
					continue;
				}
				if ( $active && $old->item_key !== $entry['key'] && '' !== $old->item_key ) {
					$result['deletes'][] = $old->item_key;
				}
				$result['upserts'][ $item_id ] = $entry;
				$upsert_rows[]                 = $wpdb->prepare(
					'(%d,%d,%d,%s,%s,%s,%s,%d,%d)',
					$feed_id,
					$item_id,
					(int) $entry['parent'],
					$entry['key'],
					$hash,
					'active',
					wp_json_encode( $entry['row'] ),
					$run_id,
					$now
				);
			}
		}

		foreach ( $existing as $item_id => $old ) {
			if ( 'active' === $old->state && ! isset( $produced[ (int) $item_id ] ) ) {
				$tombstone[]         = (int) $item_id;
				$result['deletes'][] = (string) $old->item_key;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above / integer lists.
		foreach ( array_chunk( $upsert_rows, 200 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$this->table()} (feed_id,item_id,parent_id,item_key,hash,state,data,seen_run,changed_at) VALUES " . implode( ',', $chunk )
				. ' ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), item_key = VALUES(item_key), hash = VALUES(hash), state = VALUES(state), data = VALUES(data), seen_run = VALUES(seen_run), changed_at = VALUES(changed_at)'
			);
		}
		foreach ( array_chunk( $unchanged, 500 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET seen_run = %d WHERE feed_id = %d AND item_id IN (" . implode( ',', $chunk ) . ')', $run_id, $feed_id ) );
		}
		$this->tombstone( $feed_id, $tombstone, $run_id, $now );
		// phpcs:enable

		return $result;
	}

	/**
	 * Tombstone active items not seen by a scanning run. Call repeatedly until it returns nothing.
	 *
	 * @param int $feed_id Feed id.
	 * @param int $run_id  Run id.
	 * @param int $now     Unix time.
	 * @param int $limit   Max items per call.
	 * @return string[] Deleted item keys.
	 */
	public function sweep( int $feed_id, int $run_id, int $now, int $limit = 500 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_key FROM {$this->table()} WHERE feed_id = %d AND state = 'active' AND seen_run <> %d LIMIT %d", $feed_id, $run_id, $limit ) );
		if ( ! $rows ) {
			return array();
		}
		$this->tombstone( $feed_id, array_map( static fn( $r ) => (int) $r->item_id, $rows ), $run_id, $now );
		return array_map( static fn( $r ) => (string) $r->item_key, $rows );
	}

	/**
	 * Mark items deleted.
	 *
	 * @param int   $feed_id  Feed id.
	 * @param int[] $item_ids Item ids.
	 * @param int   $run_id   Run id.
	 * @param int   $now      Unix time.
	 */
	private function tombstone( int $feed_id, array $item_ids, int $run_id, int $now ): void {
		global $wpdb;
		foreach ( array_chunk( $item_ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Integer list.
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET state = 'deleted', hash = '', data = NULL, seen_run = %d, changed_at = %d WHERE feed_id = %d AND item_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')', $run_id, $now, $feed_id ) );
		}
	}

	/**
	 * Undo the diff of a failed run: items it changed get an empty hash (and
	 * its tombstones become active again), so the next run emits them again.
	 * The feed cursor did not move, so the same candidates are re-evaluated.
	 *
	 * @param int $feed_id Feed id.
	 * @param int $run_id  Run id.
	 * @param int $started Unix start of the run (unchanged items are not stamped).
	 */
	public function rollback_run( int $feed_id, int $run_id, int $started ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET hash = '', state = 'active' WHERE feed_id = %d AND seen_run = %d AND changed_at >= %d", $feed_id, $run_id, $started ) );
	}

	/**
	 * Active item count.
	 *
	 * @param int $feed_id Feed id.
	 * @return int
	 */
	public function active_count( int $feed_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE feed_id = %d AND state = 'active'", $feed_id ) );
	}

	/**
	 * Items changed after a point in time, oldest first (for the changes API).
	 *
	 * @param int $feed_id Feed id.
	 * @param int $since   Exclusive unix time.
	 * @param int $limit   Page size.
	 * @param int $after   Keyset: last item_id of the previous page at the same changed_at.
	 * @param int $until   Inclusive upper bound (0 = none).
	 * @return object[]
	 */
	public function changes_since( int $feed_id, int $since, int $limit, int $after = 0, int $until = 0 ): array {
		global $wpdb;
		if ( $after > 0 ) {
			$sql  = "SELECT item_id, parent_id, item_key, state, data, changed_at FROM {$this->table()} WHERE feed_id = %d AND ( changed_at > %d OR ( changed_at = %d AND item_id > %d ) )";
			$args = array( $feed_id, $since, $since, $after );
		} else {
			$sql  = "SELECT item_id, parent_id, item_key, state, data, changed_at FROM {$this->table()} WHERE feed_id = %d AND changed_at > %d";
			$args = array( $feed_id, $since );
		}
		if ( $until > 0 ) {
			$sql   .= ' AND changed_at <= %d';
			$args[] = $until;
		}
		$sql   .= ' ORDER BY changed_at ASC, item_id ASC LIMIT %d';
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Remove tombstones older than a cutoff.
	 *
	 * @param int $feed_id Feed id.
	 * @param int $before  Unix time.
	 */
	public function purge_tombstones( int $feed_id, int $before ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE feed_id = %d AND state = 'deleted' AND changed_at < %d", $feed_id, $before ) );
	}

	/**
	 * Forget everything a feed has synced (next run becomes a fresh baseline).
	 *
	 * @param int $feed_id Feed id.
	 */
	public function reset( int $feed_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $this->table(), array( 'feed_id' => $feed_id ) );
	}
}
