<?php
/**
 * Run engine: full builds and delta (partial) syncs, processed in batches.
 *
 * Run types
 *   full   Scans every product, writes the full feed file and, unless this is
 *          the first sync, a delta file with what changed since the last run.
 *   delta  Writes only a delta file. Strategy "changes" re-evaluates just the
 *          products in the change log / modified since the cursor (fast, runs
 *          every few minutes). Strategy "compare" scans every product and
 *          diffs it (slower, also catches changes no hook reports, such as
 *          prices from dynamic-pricing plugins).
 *
 * Every run diffs its rows against StateStore, so unchanged products never
 * appear in a delta. Deletes come from products that were removed, trashed,
 * unpublished or no longer match the filters.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Sync;

use ProductSyncFeeds\Feed\Feed;
use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Output\WriterFactory;
use ProductSyncFeeds\Product\ProductQuery;
use ProductSyncFeeds\Product\RowBuilder;
use ProductSyncFeeds\Support\Files;
use ProductSyncFeeds\Support\Logger;
use ProductSyncFeeds\Transport\Uploader;

defined( 'ABSPATH' ) || exit;

/**
 * Starts, advances and finalizes runs.
 */
class Generator {

	/** Seconds of work per batch before handing over to the next request. */
	const TIME_BUDGET = 20;

	/** @var FeedRepository */
	private $feeds;
	/** @var RunRepository */
	private $runs;
	/** @var StateStore */
	private $state;
	/** @var ProductQuery */
	private $query;
	/** @var RowBuilder */
	private $builder;
	/** @var Files */
	private $files;
	/** @var Uploader */
	private $uploader;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param FeedRepository $feeds    Feeds.
	 * @param RunRepository  $runs     Runs.
	 * @param StateStore     $state    State.
	 * @param ProductQuery   $query    Query.
	 * @param RowBuilder     $builder  Row builder.
	 * @param Files          $files    Files.
	 * @param Uploader       $uploader Uploader.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( FeedRepository $feeds, RunRepository $runs, StateStore $state, ProductQuery $query, RowBuilder $builder, Files $files, Uploader $uploader, Logger $logger ) {
		$this->feeds    = $feeds;
		$this->runs     = $runs;
		$this->state    = $state;
		$this->query    = $query;
		$this->builder  = $builder;
		$this->files    = $files;
		$this->uploader = $uploader;
		$this->logger   = $logger;
	}

	/**
	 * Start a run.
	 *
	 * @param Feed   $feed    Feed.
	 * @param string $type    full|delta.
	 * @param string $trigger manual|schedule|cli|rest.
	 * @return int|\WP_Error Run id.
	 */
	public function start( Feed $feed, string $type, string $trigger ) {
		if ( 'delta' === $type && ! $feed->delta_enabled() ) {
			return new \WP_Error( 'psf_delta_disabled', __( 'Delta sync is not enabled for this feed.', 'product-sync-feeds' ) );
		}
		if ( ! $feed->get( 'mapping' ) ) {
			return new \WP_Error( 'psf_no_mapping', __( 'The feed has no field mapping.', 'product-sync-feeds' ) );
		}

		// Short mutex so two triggers in the same second cannot both start.
		$lock = 'psf_start_lock_' . $feed->id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 60 ) {
				return new \WP_Error( 'psf_busy', __( 'A run of this feed is starting.', 'product-sync-feeds' ) );
			}
			update_option( $lock, time(), false );
		}

		try {
			if ( $this->runs->active_for( $feed->id ) ) {
				return new \WP_Error( 'psf_busy', __( 'This feed is already running.', 'product-sync-feeds' ) );
			}

			// The window ends one second ago: a change stamped in the current
			// second may still be written after the candidate query, so it
			// belongs to the next run (whose window starts after this one).
			$now      = time() - 1;
			$baseline = 0 === $this->state->active_count( $feed->id ) && 0 === $feed->delta_cursor;
			$strategy = (string) $feed->get( 'delta.strategy', 'changes' );
			$scan     = 'full' === $type || 'compare' === $strategy || 0 === $feed->delta_cursor;

			$state = array(
				'scan'          => $scan,
				'write_full'    => 'full' === $type,
				'write_delta'   => 'delta' === $type || ( $feed->delta_enabled() && $feed->get( 'delta.full_emits_delta' ) && ! $baseline ),
				'baseline'      => $baseline,
				'last_id'       => 0,
				'candidates'    => array(),
				'position'      => 0,
				'full_written'  => 0,
				'delta_written' => 0,
			);

			$columns       = array_map( static fn( $m ) => (string) $m['target'], (array) $feed->get( 'mapping' ) );
			$action_field  = (string) $feed->get( 'delta.action_field', 'sync_action' );
			$delta_columns = array_merge( array( $action_field ), $columns );

			$run_id = $this->runs->create(
				array(
					'feed_id'     => $feed->id,
					'type'        => $type,
					'run_trigger' => $trigger,
					'status'      => 'running',
					'cursor_from' => $feed->delta_cursor,
					'cursor_to'   => $now,
				)
			);
			if ( ! $run_id ) {
				return new \WP_Error( 'psf_db', __( 'Could not create the run record.', 'product-sync-feeds' ) );
			}

			if ( $scan ) {
				$total = $this->query->count_parents( $feed );
			} else {
				$state['candidates'] = $this->query->changed_parent_ids( $feed, $feed->delta_cursor, $now );
				$total               = count( $state['candidates'] );
			}

			if ( $state['write_full'] ) {
				$state['full_tmp'] = $this->files->tmp_path( $feed, $run_id, 'full' );
				WriterFactory::make( $feed, $columns )->begin( $state['full_tmp'], $columns );
			}
			if ( $state['write_delta'] ) {
				$state['delta_tmp'] = $this->files->tmp_path( $feed, $run_id, 'delta' );
				WriterFactory::make( $feed, $delta_columns, array( $action_field ) )->begin( $state['delta_tmp'], $delta_columns );
			}

			$this->runs->update(
				$run_id,
				array(
					'total' => $total,
					'state' => $state,
				)
			);

			$this->logger->info( sprintf( 'Feed %s: %s run %d started (%s, %d %s).', $feed->slug, $type, $run_id, $trigger, $total, $scan ? 'products to scan' : 'changed products' ) );
			do_action( 'psf_run_started', $run_id, $feed, $type );

			return $run_id;
		} catch ( \Throwable $e ) {
			if ( isset( $run_id ) && $run_id ) {
				$this->fail( $run_id, $e->getMessage() );
			}
			return new \WP_Error( 'psf_start_failed', $e->getMessage() );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Process one batch. Finalizes the run when nothing is left.
	 *
	 * @param int $run_id Run id.
	 * @param int $seq    Expected batch sequence (stale duplicate actions are ignored).
	 * @return string continue|done|skip
	 */
	public function step( int $run_id, int $seq ): string {
		$run = $this->runs->find( $run_id );
		if ( ! $run || 'running' !== $run->status || $run->seq !== $seq ) {
			return 'skip';
		}
		$feed = $this->feeds->find( $run->feed_id );
		if ( ! $feed ) {
			$this->fail( $run_id, __( 'The feed was deleted.', 'product-sync-feeds' ) );
			return 'done';
		}

		try {
			$state    = $run->state;
			$limit    = (int) $feed->get( 'batch_size', 200 );
			$deadline = microtime( true ) + self::TIME_BUDGET;

			$ids = $state['scan']
				? $this->query->next_parent_ids( $feed, (int) $state['last_id'], $limit )
				: array_slice( (array) $state['candidates'], (int) $state['position'], $limit );

			if ( ! $ids ) {
				$this->finalize( $feed, $run );
				return 'done';
			}

			$families = array();
			foreach ( $ids as $parent_id ) {
				$families[ (int) $parent_id ] = $this->builder->family( $feed, (int) $parent_id );
				if ( microtime( true ) > $deadline ) {
					break;
				}
			}

			$diff = $this->state->reconcile( $feed->id, $families, $run_id, time() );

			if ( $state['write_full'] ) {
				$rows = array();
				foreach ( $families as $family ) {
					foreach ( $family as $entry ) {
						$rows[] = $entry['row'];
					}
				}
				$columns = array_map( static fn( $m ) => (string) $m['target'], (array) $feed->get( 'mapping' ) );
				WriterFactory::make( $feed, $columns )->append( $state['full_tmp'], $rows, (int) $state['full_written'] );
				$state['full_written'] += count( $rows );
			}

			if ( $state['write_delta'] ) {
				$state['delta_written'] += $this->write_delta( $feed, $state, $diff['deletes'], $diff['upserts'] );
			}

			$processed = array_keys( $families );
			if ( $state['scan'] ) {
				$state['last_id'] = max( $processed );
			} else {
				$state['position'] += count( $processed );
			}

			$this->runs->update(
				$run_id,
				array(
					'seq'       => $seq + 1,
					'state'     => $state,
					'processed' => $run->processed + count( $processed ),
					'written'   => $state['write_full'] ? (int) $state['full_written'] : $run->written + array_sum( array_map( 'count', $families ) ),
					'upserts'   => $run->upserts + count( $diff['upserts'] ),
					'deletes'   => $run->deletes + count( $diff['deletes'] ),
				)
			);
			return 'continue';
		} catch ( \Throwable $e ) {
			$this->fail( $run_id, $e->getMessage() );
			return 'done';
		}
	}

	/**
	 * Drive a run to the end in this request (WP-CLI).
	 *
	 * @param int $run_id Run id.
	 * @return object|null Final run.
	 */
	public function run_to_completion( int $run_id ) {
		do {
			$run = $this->runs->find( $run_id );
			if ( ! $run ) {
				return null;
			}
			$result = $this->step( $run_id, $run->seq );
		} while ( 'continue' === $result );
		return $this->runs->find( $run_id );
	}

	/**
	 * Cancel a running run.
	 *
	 * @param int $run_id Run id.
	 */
	public function cancel( int $run_id ): void {
		$run = $this->runs->find( $run_id );
		if ( ! $run || 'running' !== $run->status ) {
			return;
		}
		$this->discard_tmp( $run->state );
		if ( $run->processed > 0 ) {
			$this->state->rollback_run( $run->feed_id, $run_id, (int) strtotime( $run->started_at . ' UTC' ) );
		}
		$this->runs->update(
			$run_id,
			array(
				'status'      => 'cancelled',
				'finished_at' => current_time( 'mysql', true ),
				'message'     => __( 'Cancelled.', 'product-sync-feeds' ),
			)
		);
	}

	/**
	 * Append delta rows: deletes first, then upserts.
	 *
	 * @param Feed     $feed    Feed.
	 * @param array    $state   Run state.
	 * @param string[] $deletes Deleted item keys.
	 * @param array    $upserts item_id => entry.
	 * @return int Rows written.
	 */
	private function write_delta( Feed $feed, array $state, array $deletes, array $upserts ): int {
		$action  = (string) $feed->get( 'delta.action_field', 'sync_action' );
		$key_col = RowBuilder::key_column( $feed );
		$columns = array_merge( array( $action ), array_map( static fn( $m ) => (string) $m['target'], (array) $feed->get( 'mapping' ) ) );
		$rows    = array();

		foreach ( $deletes as $key ) {
			$rows[] = array(
				$action  => 'delete',
				$key_col => $key,
			);
		}
		foreach ( $upserts as $entry ) {
			$rows[] = array( $action => 'upsert' ) + $entry['row'];
		}
		if ( $rows ) {
			WriterFactory::make( $feed, $columns, array( $action ) )->append( $state['delta_tmp'], $rows, (int) $state['delta_written'] );
		}
		return count( $rows );
	}

	/**
	 * Finish a run: sweep, close and publish files, upload, move the cursor.
	 *
	 * @param Feed   $feed Feed.
	 * @param object $run  Run.
	 */
	private function finalize( Feed $feed, $run ): void {
		$state   = $run->state;
		$deletes = $run->deletes;
		$now     = time();

		if ( $state['scan'] ) {
			if ( $state['write_full'] && 0 === (int) $state['full_written'] && $this->state->active_count( $feed->id ) > 0 && ! apply_filters( 'psf_allow_empty_feed', false, $feed ) ) {
				$this->fail( $run->id, __( 'No products matched, so the previous feed file was kept. Check the filters, or use the psf_allow_empty_feed filter to publish empty feeds.', 'product-sync-feeds' ) );
				return;
			}
			// Anything the scan did not see is gone from the feed.
			do {
				$gone = $this->state->sweep( $feed->id, $run->id, $now );
				if ( $gone && $state['write_delta'] ) {
					$state['delta_written'] += $this->write_delta( $feed, $state, $gone, array() );
				}
				$deletes += count( $gone );
			} while ( $gone );
		}

		$columns = array_map( static fn( $m ) => (string) $m['target'], (array) $feed->get( 'mapping' ) );
		$action  = (string) $feed->get( 'delta.action_field', 'sync_action' );
		$files   = array();

		if ( $state['write_full'] ) {
			WriterFactory::make( $feed, $columns )->finish( $state['full_tmp'], (int) $state['full_written'] );
			$path = $this->files->full_path( $feed );
			if ( ! $this->files->publish( $state['full_tmp'], $path ) ) {
				$this->fail( $run->id, __( 'Could not move the feed file into place.', 'product-sync-feeds' ) );
				return;
			}
			$files['full'] = $path;
		}

		if ( $state['write_delta'] ) {
			$delta_columns = array_merge( array( $action ), $columns );
			WriterFactory::make( $feed, $delta_columns, array( $action ) )->finish( $state['delta_tmp'], (int) $state['delta_written'] );
			if ( (int) $state['delta_written'] > 0 ) {
				$history = $this->files->delta_history_path( $feed, $run->id, (int) $run->cursor_to );
				wp_mkdir_p( dirname( $history ) );
				copy( $state['delta_tmp'], $history );
				$files['delta'] = $history;
			}
			// The latest file always reflects the latest run, empty when nothing changed.
			$latest = $this->files->delta_latest_path( $feed );
			$this->files->publish( $state['delta_tmp'], $latest );
			$files['delta_latest'] = $latest;
			$this->files->prune_delta_history( $feed, (int) $feed->get( 'delta.keep_files', 50 ) );
		}

		$upload = $this->upload( $feed, $files );

		$feed_fields = array( 'delta_cursor' => max( $feed->delta_cursor, (int) $run->cursor_to ) );
		$feed_fields[ 'full' === $run->type ? 'last_full_at' : 'last_delta_at' ] = current_time( 'mysql', true );
		$this->feeds->update_fields( $feed->id, $feed_fields );

		$message = '';
		if ( ! empty( $state['baseline'] ) ) {
			$message = __( 'First sync: this run is the baseline for later deltas.', 'product-sync-feeds' );
		}
		if ( ! empty( $upload['error'] ) ) {
			/* translators: %s: upload error */
			$message = trim( $message . ' ' . sprintf( __( 'Upload failed: %s', 'product-sync-feeds' ), $upload['error'] ) );
		}

		$this->runs->update(
			$run->id,
			array(
				'status'      => 'completed',
				'state'       => $state,
				'deletes'     => $deletes,
				'files'       => $files,
				'upload'      => $upload,
				'message'     => $message,
				'finished_at' => current_time( 'mysql', true ),
			)
		);
		$this->runs->prune( $feed->id );

		$this->logger->info( sprintf( 'Feed %s: %s run %d completed (%d rows, %d upserts, %d deletes).', $feed->slug, $run->type, $run->id, (int) $state['full_written'], $run->upserts, $deletes ) );
		do_action( 'psf_run_completed', $run->id, $feed, $files );
	}

	/**
	 * Upload produced files according to the feed settings.
	 *
	 * @param Feed  $feed  Feed.
	 * @param array $files Produced files.
	 * @return array{uploaded:string[],error:string}
	 */
	private function upload( Feed $feed, array $files ): array {
		$result = array(
			'uploaded' => array(),
			'error'    => '',
		);
		if ( ! $feed->get( 'upload.enabled' ) ) {
			return $result;
		}
		$which = (string) $feed->get( 'upload.files', 'full' );
		$queue = array();
		if ( in_array( $which, array( 'full', 'both' ), true ) && isset( $files['full'] ) ) {
			$queue[] = $files['full'];
		}
		if ( in_array( $which, array( 'delta', 'both' ), true ) ) {
			if ( isset( $files['delta'] ) ) {
				$queue[] = $files['delta'];
			}
			if ( isset( $files['delta_latest'] ) ) {
				$queue[] = $files['delta_latest'];
			}
		}
		foreach ( $queue as $path ) {
			$ok = $this->uploader->upload( $path, basename( $path ), (array) $feed->get( 'upload' ) );
			if ( is_wp_error( $ok ) ) {
				$result['error'] = $ok->get_error_message();
				$this->logger->error( sprintf( 'Feed %s: upload of %s failed: %s', $feed->slug, basename( $path ), $ok->get_error_message() ) );
				do_action( 'psf_upload_failed', $feed, $path, $ok );
				break;
			}
			$result['uploaded'][] = basename( $path );
		}
		return $result;
	}

	/**
	 * Mark a run failed and remove its working files.
	 *
	 * @param int    $run_id  Run id.
	 * @param string $message Reason.
	 */
	private function fail( int $run_id, string $message ): void {
		$run = $this->runs->find( $run_id );
		if ( $run ) {
			$this->discard_tmp( $run->state );
			if ( $run->processed > 0 ) {
				$this->state->rollback_run( $run->feed_id, $run_id, (int) strtotime( $run->started_at . ' UTC' ) );
			}
		}
		$this->runs->update(
			$run_id,
			array(
				'status'      => 'failed',
				'message'     => $message,
				'finished_at' => current_time( 'mysql', true ),
			)
		);
		$this->logger->error( sprintf( 'Run %d failed: %s', $run_id, $message ) );
		do_action( 'psf_run_failed', $run_id, $message );
	}

	/**
	 * Delete a run's working files.
	 *
	 * @param array $state Run state.
	 */
	private function discard_tmp( array $state ): void {
		foreach ( array( 'full_tmp', 'delta_tmp' ) as $key ) {
			if ( ! empty( $state[ $key ] ) && file_exists( $state[ $key ] ) ) {
				wp_delete_file( $state[ $key ] );
			}
		}
	}
}
