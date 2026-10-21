<?php
/**
 * Action Scheduler integration: recurring full and delta schedules (down to
 * one minute), batch chaining and daily maintenance.
 *
 * Action Scheduler is triggered by WP-Cron, which only fires on site traffic.
 * For minute-level schedules point a real cron job at WP-CLI:
 *   * * * * * wp action-scheduler run --hooks=psf_scheduled_full,psf_scheduled_delta,psf_process_batch,psf_maintenance
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Sync;

use ProductSyncFeeds\Feed\Feed;
use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Plugin;
use ProductSyncFeeds\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling.
 */
class Scheduler {

	const GROUP        = 'product-sync-feeds';
	const FULL_HOOK    = 'psf_scheduled_full';
	const DELTA_HOOK   = 'psf_scheduled_delta';
	const BATCH_HOOK   = 'psf_process_batch';
	const MAINT_HOOK   = 'psf_maintenance';
	const SIGNATURE    = 'psf_schedule_';
	const HOOKS        = array( self::FULL_HOOK, self::DELTA_HOOK, self::BATCH_HOOK, self::MAINT_HOOK );

	/** @var FeedRepository */
	private $feeds;
	/** @var RunRepository */
	private $runs;
	/** @var StateStore */
	private $state;
	/** @var Generator */
	private $generator;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param FeedRepository $feeds     Feeds.
	 * @param RunRepository  $runs      Runs.
	 * @param StateStore     $state     State.
	 * @param Generator      $generator Generator.
	 * @param Logger         $logger    Logger.
	 */
	public function __construct( FeedRepository $feeds, RunRepository $runs, StateStore $state, Generator $generator, Logger $logger ) {
		$this->feeds     = $feeds;
		$this->runs      = $runs;
		$this->state     = $state;
		$this->generator = $generator;
		$this->logger    = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( self::FULL_HOOK, array( $this, 'handle_full' ), 10, 1 );
		add_action( self::DELTA_HOOK, array( $this, 'handle_delta' ), 10, 1 );
		add_action( self::BATCH_HOOK, array( $this, 'handle_batch' ), 10, 2 );
		add_action( self::MAINT_HOOK, array( $this, 'maintenance' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_maintenance' ) );
	}

	/**
	 * Whether Action Scheduler is loaded.
	 *
	 * @return bool
	 */
	private function available(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Start a run and queue its first batch.
	 *
	 * @param Feed   $feed    Feed.
	 * @param string $type    full|delta.
	 * @param string $trigger Trigger.
	 * @return int|\WP_Error Run id.
	 */
	public function run_now( Feed $feed, string $type, string $trigger = 'manual' ) {
		if ( ! $this->available() ) {
			return new \WP_Error( 'psf_no_scheduler', __( 'Action Scheduler is not available. Is WooCommerce active?', 'product-sync-feeds' ) );
		}
		$run_id = $this->generator->start( $feed, $type, $trigger );
		if ( ! is_wp_error( $run_id ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( $run_id, 0 ), self::GROUP );
		}
		return $run_id;
	}

	/**
	 * Recurring full run.
	 *
	 * @param int $feed_id Feed id.
	 */
	public function handle_full( $feed_id ): void {
		$this->scheduled( (int) $feed_id, 'full' );
	}

	/**
	 * Recurring delta run.
	 *
	 * @param int $feed_id Feed id.
	 */
	public function handle_delta( $feed_id ): void {
		$this->scheduled( (int) $feed_id, 'delta' );
	}

	/**
	 * Shared recurring handler. A busy feed simply waits for the next tick.
	 *
	 * @param int    $feed_id Feed id.
	 * @param string $type    full|delta.
	 */
	private function scheduled( int $feed_id, string $type ): void {
		$feed = $this->feeds->find( $feed_id );
		if ( ! $feed ) {
			$this->unschedule( $feed_id );
			return;
		}
		if ( 1 !== $feed->status ) {
			$this->sync( $feed );
			return;
		}
		$result = $this->run_now( $feed, $type, 'schedule' );
		if ( is_wp_error( $result ) && 'psf_busy' !== $result->get_error_code() ) {
			$this->logger->warning( sprintf( 'Feed %s: scheduled %s run not started: %s', $feed->slug, $type, $result->get_error_message() ) );
		}
	}

	/**
	 * Process one batch and chain the next.
	 *
	 * @param int $run_id Run id.
	 * @param int $seq    Batch sequence.
	 */
	public function handle_batch( $run_id, $seq = 0 ): void {
		if ( 'continue' === $this->generator->step( (int) $run_id, (int) $seq ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( (int) $run_id, (int) $seq + 1 ), self::GROUP );
		}
	}

	/**
	 * Make the recurring actions match the feed settings. Existing actions
	 * with the same interval are kept so saving a feed does not push runs back.
	 *
	 * @param Feed $feed Feed.
	 */
	public function sync( Feed $feed ): void {
		if ( ! $this->available() ) {
			return;
		}
		$want = array(
			'full'  => ( 1 === $feed->status && $feed->get( 'schedule.full_enabled' ) ) ? 60 * (int) $feed->get( 'schedule.full_interval' ) : 0,
			'delta' => ( 1 === $feed->status && $feed->delta_enabled() ) ? 60 * (int) $feed->get( 'schedule.delta_interval' ) : 0,
		);
		$have = (array) get_option( self::SIGNATURE . $feed->id, array() );

		foreach ( array( 'full' => self::FULL_HOOK, 'delta' => self::DELTA_HOOK ) as $type => $hook ) {
			$args    = array( $feed->id );
			$pending = as_next_scheduled_action( $hook, $args, self::GROUP );
			if ( $want[ $type ] > 0 && false !== $pending && (int) ( $have[ $type ] ?? 0 ) === $want[ $type ] ) {
				continue;
			}
			as_unschedule_all_actions( $hook, $args, self::GROUP );
			if ( $want[ $type ] > 0 ) {
				// Spread feeds that share an interval over up to a minute.
				$offset = absint( crc32( $feed->slug . $type ) ) % 60;
				as_schedule_recurring_action( time() + $want[ $type ] + $offset, $want[ $type ], $hook, $args, self::GROUP );
			}
		}
		update_option( self::SIGNATURE . $feed->id, $want, false );
	}

	/**
	 * Remove a feed's recurring actions.
	 *
	 * @param int $feed_id Feed id.
	 */
	public function unschedule( int $feed_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::FULL_HOOK, array( $feed_id ), self::GROUP );
			as_unschedule_all_actions( self::DELTA_HOOK, array( $feed_id ), self::GROUP );
		}
		delete_option( self::SIGNATURE . $feed_id );
	}

	/**
	 * Next scheduled time of a run type.
	 *
	 * @param int    $feed_id Feed id.
	 * @param string $type    full|delta.
	 * @return int|null Unix time.
	 */
	public function next_run( int $feed_id, string $type ): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}
		$next = as_next_scheduled_action( 'full' === $type ? self::FULL_HOOK : self::DELTA_HOOK, array( $feed_id ), self::GROUP );
		return is_int( $next ) ? $next : null;
	}

	/**
	 * Daily maintenance action.
	 */
	public function ensure_maintenance(): void {
		if ( $this->available() && false === as_next_scheduled_action( self::MAINT_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::MAINT_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Prune tombstones, run history, the change log and stale working files;
	 * re-create schedules that went missing.
	 */
	public function maintenance(): void {
		$oldest_cursor = time();
		foreach ( $this->feeds->all() as $feed ) {
			$days = (int) $feed->get( 'delta.tombstone_days', 30 );
			$this->state->purge_tombstones( $feed->id, time() - $days * DAY_IN_SECONDS );
			$this->runs->prune( $feed->id );
			$this->runs->active_for( $feed->id ); // Fails stalled runs.
			$this->sync( $feed );
			if ( $feed->delta_enabled() && 1 === $feed->status && $feed->delta_cursor > 0 ) {
				$oldest_cursor = min( $oldest_cursor, $feed->delta_cursor );
			}
		}
		// Keep the log until every delta feed has consumed it, at most 30 days.
		ChangeTracker::prune( max( $oldest_cursor - HOUR_IN_SECONDS, time() - 30 * DAY_IN_SECONDS ) );

		$tmp = glob( Plugin::instance()->files()->base_dir() . '/.tmp/*.*' );
		foreach ( is_array( $tmp ) ? $tmp : array() as $file ) {
			if ( 'index.php' !== basename( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
