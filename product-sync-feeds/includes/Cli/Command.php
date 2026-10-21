<?php
/**
 * WP-CLI commands: wp psf <list|run|schedule>.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Cli;

use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Sync\Generator;
use ProductSyncFeeds\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Product Sync Feeds.
 */
class Command {

	/** @var FeedRepository */
	private $feeds;
	/** @var RunRepository */
	private $runs;
	/** @var Scheduler */
	private $scheduler;
	/** @var Generator */
	private $generator;

	/**
	 * Constructor.
	 *
	 * @param FeedRepository $feeds     Feeds.
	 * @param RunRepository  $runs      Runs.
	 * @param Scheduler      $scheduler Scheduler.
	 * @param Generator      $generator Generator.
	 */
	public function __construct( FeedRepository $feeds, RunRepository $runs, Scheduler $scheduler, Generator $generator ) {
		$this->feeds     = $feeds;
		$this->runs      = $runs;
		$this->scheduler = $scheduler;
		$this->generator = $generator;
	}

	/**
	 * List feeds.
	 *
	 * ## EXAMPLES
	 *
	 *     wp psf list
	 *
	 * @subcommand list
	 */
	public function list_(): void {
		$rows = array();
		foreach ( $this->feeds->all() as $feed ) {
			$rows[] = array(
				'id'         => $feed->id,
				'name'       => $feed->name,
				'format'     => $feed->format(),
				'active'     => $feed->status ? 'yes' : 'no',
				'delta'      => $feed->delta_enabled() ? $feed->get( 'delta.strategy' ) : 'off',
				'last_full'  => $feed->last_full_at ?: '-',
				'last_delta' => $feed->last_delta_at ?: '-',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'format', 'active', 'delta', 'last_full', 'last_delta' ) );
	}

	/**
	 * Run a feed now, in this process.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Feed id.
	 *
	 * [--type=<type>]
	 * : full or delta.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - delta
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp psf run 3 --type=delta
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public function run( array $args, array $assoc_args ): void {
		$feed = $this->feeds->find( (int) $args[0] );
		if ( ! $feed ) {
			\WP_CLI::error( 'Feed not found.' );
		}
		$run_id = $this->generator->start( $feed, (string) ( $assoc_args['type'] ?? 'full' ), 'cli' );
		if ( is_wp_error( $run_id ) ) {
			\WP_CLI::error( $run_id->get_error_message() );
		}
		$run = $this->generator->run_to_completion( $run_id );
		if ( ! $run || 'completed' !== $run->status ) {
			\WP_CLI::error( $run ? (string) $run->message : 'Run disappeared.' );
		}
		\WP_CLI::success( sprintf( 'Run %d: %d rows, %d upserts, %d deletes. %s', $run->id, $run->written, $run->upserts, $run->deletes, implode( ' ', $run->files ) ) );
	}

	/**
	 * Re-create the recurring schedules of every feed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp psf schedule
	 */
	public function schedule(): void {
		foreach ( $this->feeds->all() as $feed ) {
			$this->scheduler->sync( $feed );
		}
		\WP_CLI::success( 'Schedules synced.' );
	}
}
