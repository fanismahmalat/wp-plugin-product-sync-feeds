<?php
/**
 * REST API (namespace psf/v1).
 *
 *   GET  /feeds                     list feeds
 *   GET  /feeds/{id}                one feed with file URLs and schedule
 *   POST /feeds/{id}/run            start a run: {"type":"full"|"delta"}
 *   GET  /feeds/{id}/runs           run history
 *   GET  /feeds/{id}/changes        pull deltas: ?since=<unix|ISO 8601>&limit=500
 *
 * Every route accepts a user with manage_woocommerce (cookie + nonce or an
 * application password). /changes also accepts the feed API key in the
 * X-PSF-Key header, so an external system can pull deltas without a WordPress
 * account.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Rest;

use ProductSyncFeeds\Feed\Feed;
use ProductSyncFeeds\Feed\FeedRepository;
use ProductSyncFeeds\Feed\RunRepository;
use ProductSyncFeeds\Plugin;
use ProductSyncFeeds\Sync\Scheduler;
use ProductSyncFeeds\Sync\StateStore;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class RestController {

	const NAMESPACE = 'psf/v1';

	/** @var FeedRepository */
	private $feeds;
	/** @var RunRepository */
	private $runs;
	/** @var StateStore */
	private $state;
	/** @var Scheduler */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param FeedRepository $feeds     Feeds.
	 * @param RunRepository  $runs      Runs.
	 * @param StateStore     $state     State.
	 * @param Scheduler      $scheduler Scheduler.
	 */
	public function __construct( FeedRepository $feeds, RunRepository $runs, StateStore $state, Scheduler $scheduler ) {
		$this->feeds     = $feeds;
		$this->runs      = $runs;
		$this->state     = $state;
		$this->scheduler = $scheduler;
	}

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function routes(): void {
		$admin = array( $this, 'can_manage' );
		$id    = '/feeds/(?P<id>\d+)';

		register_rest_route(
			self::NAMESPACE,
			'/feeds',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_feeds' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			$id,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $admin,
				'args'                => array(
					'type' => array(
						'type'    => 'string',
						'enum'    => array( 'full', 'delta' ),
						'default' => 'full',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_runs' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			$id . '/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'changes' ),
				'permission_callback' => array( $this, 'can_read_changes' ),
				'args'                => array(
					'since'    => array(
						'type'    => 'string',
						'default' => '0',
					),
					'after_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'limit'    => array(
						'type'    => 'integer',
						'default' => 500,
						'minimum' => 1,
						'maximum' => 2000,
					),
				),
			)
		);
	}

	/**
	 * Admin permission.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Admin or matching feed API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_read_changes( \WP_REST_Request $request ): bool {
		if ( $this->can_manage() ) {
			return true;
		}
		$key  = (string) $request->get_header( 'x_psf_key' );
		$feed = $this->feeds->find( (int) $request['id'] );
		return $feed && '' !== $key && '' !== $feed->api_key && hash_equals( $feed->api_key, $key );
	}

	/**
	 * Feed or 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Feed|\WP_Error
	 */
	private function feed( \WP_REST_Request $request ) {
		$feed = $this->feeds->find( (int) $request['id'] );
		return $feed ? $feed : new \WP_Error( 'psf_not_found', __( 'Feed not found.', 'product-sync-feeds' ), array( 'status' => 404 ) );
	}

	/**
	 * Public shape of a feed.
	 *
	 * @param Feed $feed Feed.
	 * @return array
	 */
	private function present( Feed $feed ): array {
		$files  = Plugin::instance()->files();
		$full   = $files->full_path( $feed );
		$latest = $files->delta_latest_path( $feed );
		$active = $this->runs->active_for( $feed->id );
		return array(
			'id'            => $feed->id,
			'name'          => $feed->name,
			'slug'          => $feed->slug,
			'active'        => 1 === $feed->status,
			'channel'       => $feed->get( 'channel' ),
			'format'        => $feed->format(),
			'full_url'      => file_exists( $full ) ? $files->url( $full ) : null,
			'delta_url'     => file_exists( $latest ) ? $files->url( $latest ) : null,
			'delta_enabled' => $feed->delta_enabled(),
			'delta_cursor'  => $feed->delta_cursor,
			'last_full_at'  => $feed->last_full_at ? mysql_to_rfc3339( $feed->last_full_at ) : null,
			'last_delta_at' => $feed->last_delta_at ? mysql_to_rfc3339( $feed->last_delta_at ) : null,
			'next_full'     => $this->scheduler->next_run( $feed->id, 'full' ),
			'next_delta'    => $this->scheduler->next_run( $feed->id, 'delta' ),
			'items'         => $this->state->active_count( $feed->id ),
			'running'       => $active ? $this->present_run( $active ) : null,
		);
	}

	/**
	 * Public shape of a run.
	 *
	 * @param object $run Run.
	 * @return array
	 */
	private function present_run( $run ): array {
		$files = Plugin::instance()->files();
		return array(
			'id'          => $run->id,
			'type'        => $run->type,
			'trigger'     => $run->run_trigger,
			'status'      => $run->status,
			'total'       => $run->total,
			'processed'   => $run->processed,
			'written'     => $run->written,
			'upserts'     => $run->upserts,
			'deletes'     => $run->deletes,
			'cursor_from' => $run->cursor_from,
			'cursor_to'   => $run->cursor_to,
			'files'       => array_map( array( $files, 'url' ), $run->files ),
			'upload'      => $run->upload,
			'message'     => (string) $run->message,
			'started_at'  => mysql_to_rfc3339( $run->started_at ),
			'finished_at' => $run->finished_at ? mysql_to_rfc3339( $run->finished_at ) : null,
		);
	}

	/**
	 * GET /feeds.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_feeds(): \WP_REST_Response {
		return rest_ensure_response( array_map( array( $this, 'present' ), $this->feeds->all() ) );
	}

	/**
	 * GET /feeds/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_feed( \WP_REST_Request $request ) {
		$feed = $this->feed( $request );
		return is_wp_error( $feed ) ? $feed : rest_ensure_response( $this->present( $feed ) );
	}

	/**
	 * POST /feeds/{id}/run.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$feed = $this->feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$run_id = $this->scheduler->run_now( $feed, (string) $request['type'], 'rest' );
		if ( is_wp_error( $run_id ) ) {
			$run_id->add_data( array( 'status' => 'psf_busy' === $run_id->get_error_code() ? 409 : 400 ) );
			return $run_id;
		}
		return new \WP_REST_Response( $this->present_run( $this->runs->find( $run_id ) ), 202 );
	}

	/**
	 * GET /feeds/{id}/runs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_runs( \WP_REST_Request $request ) {
		$feed = $this->feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		return rest_ensure_response( array_map( array( $this, 'present_run' ), $this->runs->latest( $feed->id, 50 ) ) );
	}

	/**
	 * GET /feeds/{id}/changes — items changed after `since`, oldest first.
	 *
	 * Upserts carry the full mapped row in `data`; deletes carry only the key.
	 * Keep calling with the returned `next` values until `has_more` is false,
	 * then store `next.since` for the following poll. The window ends one
	 * second before "now", so items stamped in the current second are never
	 * skipped between polls. Deletes older than the feed tombstone retention
	 * are no longer reported: a client that was offline longer should resync
	 * from the full feed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function changes( \WP_REST_Request $request ) {
		$feed = $this->feed( $request );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$since_raw = (string) $request['since'];
		$since     = ctype_digit( $since_raw ) ? (int) $since_raw : (int) strtotime( $since_raw );
		$limit     = (int) $request['limit'];
		$until     = time() - 1;
		$rows      = $this->state->changes_since( $feed->id, $since, $limit + 1, (int) $request['after_id'], $until );
		$has_more  = count( $rows ) > $limit;
		$rows      = array_slice( $rows, 0, $limit );

		$items = array();
		foreach ( $rows as $row ) {
			$data    = $row->data ? json_decode( $row->data, true ) : null;
			$items[] = array(
				'action'     => 'active' === $row->state ? 'upsert' : 'delete',
				'item_id'    => (int) $row->item_id,
				'parent_id'  => (int) $row->parent_id,
				'key'        => (string) $row->item_key,
				'changed_at' => (int) $row->changed_at,
				'data'       => 'active' === $row->state ? $data : null,
			);
		}

		$last = end( $rows );
		$next = $has_more && $last
			? array(
				'since'    => (int) $last->changed_at,
				'after_id' => (int) $last->item_id,
			)
			: array(
				'since'    => max( $since, $until ),
				'after_id' => 0,
			);

		return rest_ensure_response(
			array(
				'feed_id'                => $feed->id,
				'since'                  => $since,
				'until'                  => $until,
				'tombstone_retention_at' => time() - (int) $feed->get( 'delta.tombstone_days', 30 ) * DAY_IN_SECONDS,
				'items'                  => $items,
				'has_more'               => $has_more,
				'next'                   => $next,
			)
		);
	}
}
