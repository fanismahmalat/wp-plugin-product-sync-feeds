<?php
/**
 * Candidate product ids for runs.
 *
 * Full and compare runs walk parent products with keyset pagination
 * (ID > last id), which stays correct while the catalog changes mid-run.
 * Change runs collect ids from the change log and post_modified_gmt.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Product;

use ProductSyncFeeds\Feed\Feed;
use ProductSyncFeeds\Installer;
use ProductSyncFeeds\Sync\StateStore;

defined( 'ABSPATH' ) || exit;

/**
 * Product id queries.
 */
class ProductQuery {

	/** @var StateStore */
	private $state;

	/** @var bool */
	private static $where_hooked = false;

	/**
	 * Constructor.
	 *
	 * @param StateStore $state State store (to find parents of deleted variations).
	 */
	public function __construct( StateStore $state ) {
		$this->state = $state;
		if ( ! self::$where_hooked ) {
			add_filter( 'posts_where', array( __CLASS__, 'after_id_where' ), 10, 2 );
			self::$where_hooked = true;
		}
	}

	/**
	 * Adds "ID > n" for queries that carry the psf_after_id var.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public static function after_id_where( $where, $query ) {
		$after = $query->get( 'psf_after_id' );
		if ( '' !== $after && null !== $after ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $after );
		}
		return $where;
	}

	/**
	 * Base query args from the feed filters that can be expressed in SQL.
	 *
	 * @param Feed $feed Feed.
	 * @return array
	 */
	private function base_args( Feed $feed ): array {
		$f    = $feed->get( 'filters' );
		$args = array(
			'post_type'              => 'product',
			'post_status'            => $f['post_status'] ? $f['post_status'] : array( 'publish' ),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'suppress_filters'       => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lang'                   => '', // Polylang: all languages.
			'tax_query'              => array( 'relation' => 'AND' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		);

		if ( $f['product_types'] ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $f['product_types'],
			);
		}
		foreach ( array( 'product_cat' => 'categories', 'product_tag' => 'tags' ) as $taxonomy => $key ) {
			if ( $f[ $key ] ) {
				$args['tax_query'][] = array(
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => $f[ $key ],
					'include_children' => true,
					'operator'         => 'include' === $f[ $key . '_mode' ] ? 'IN' : 'NOT IN',
				);
			}
		}
		if ( $f['ids'] ) {
			$args[ 'include' === $f['ids_mode'] ? 'post__in' : 'post__not_in' ] = $f['ids'];
		}

		return (array) apply_filters( 'psf_query_args', $args, $feed );
	}

	/**
	 * Number of parent products the feed scans.
	 *
	 * @param Feed $feed Feed.
	 * @return int
	 */
	public function count_parents( Feed $feed ): int {
		$query = new \WP_Query( array_merge( $this->base_args( $feed ), array( 'posts_per_page' => 1 ) ) );
		return (int) $query->found_posts;
	}

	/**
	 * Next page of parent ids after $after_id.
	 *
	 * @param Feed $feed     Feed.
	 * @param int  $after_id Last processed id.
	 * @param int  $limit    Page size.
	 * @return int[]
	 */
	public function next_parent_ids( Feed $feed, int $after_id, int $limit ): array {
		$args = array_merge(
			$this->base_args( $feed ),
			array(
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'psf_after_id'   => $after_id,
			)
		);
		return array_map( 'intval', ( new \WP_Query( $args ) )->posts );
	}

	/**
	 * Parent ids touched in (from, to]: change log plus post_modified_gmt.
	 *
	 * Variations map to their parent, deleted posts to the parent recorded in
	 * the feed state, so the family is re-evaluated as a whole.
	 *
	 * @param Feed $feed Feed.
	 * @param int  $from Exclusive start (unix).
	 * @param int  $to   Inclusive end (unix).
	 * @return int[]
	 */
	public function changed_parent_ids( Feed $feed, int $from, int $to ): array {
		global $wpdb;
		$changes = Installer::table( 'psf_changes' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$logged   = $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$changes} WHERE changed_at > %d AND changed_at <= %d", $from, $to ) );
		$modified = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_modified_gmt > %s AND post_modified_gmt <= %s",
				gmdate( 'Y-m-d H:i:s', $from ),
				gmdate( 'Y-m-d H:i:s', $to )
			)
		);
		// phpcs:enable

		$ids = array_values( array_unique( array_map( 'intval', array_merge( (array) $logged, (array) $modified ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$parents = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer list.
			$rows  = $wpdb->get_results( "SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN ({$in})", OBJECT_K );
			$known = $this->state->parents_of( $feed->id, $chunk );
			foreach ( $chunk as $id ) {
				if ( isset( $rows[ $id ] ) ) {
					$row       = $rows[ $id ];
					$parents[] = ( 'product_variation' === $row->post_type && $row->post_parent ) ? (int) $row->post_parent : $id;
				} else {
					$parents[] = $known[ $id ] ?? $id; // Deleted: use the parent we synced it under.
				}
			}
		}

		$parents = array_values( array_unique( $parents ) );
		sort( $parents );
		return $parents;
	}
}
