<?php
/**
 * Change log (psf_changes): records which products changed and when.
 *
 * Product edits, stock changes, meta and term updates, trashing and deletion
 * are buffered during the request and written in one query at shutdown, so
 * product saves and checkouts stay fast. Delta runs read this log together
 * with post_modified_gmt to find what to re-evaluate.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Sync;

use ProductSyncFeeds\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks product changes into the change log.
 */
class ChangeTracker {

	/** Meta keys that never affect feed output. */
	const IGNORED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wc_review_count_cache' );

	/** @var array<int,true> Ids changed in this request. */
	private $pending = array();

	/**
	 * Register hooks.
	 */
	public function register(): void {
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( $this, 'mark' ), 10, 1 );
		}
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_product' ), 10, 1 );
		}
		foreach ( array( 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, array( $this, 'mark' ), 10, 1 );
		}
		foreach ( array( 'wp_trash_post', 'untrashed_post', 'before_delete_post' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_post' ), 10, 1 );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_meta' ), 10, 3 );
		}
		add_action( 'set_object_terms', array( $this, 'mark_post' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'mark_term_products' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Record a product id.
	 *
	 * @param mixed $product_id Product id.
	 */
	public function mark( $product_id ): void {
		$product_id = absint( $product_id );
		if ( $product_id ) {
			$this->pending[ $product_id ] = true;
		}
	}

	/**
	 * Record from a product object.
	 *
	 * @param mixed $product Product.
	 */
	public function mark_product( $product ): void {
		if ( $product instanceof \WC_Product ) {
			$this->mark( $product->get_id() );
		}
	}

	/**
	 * Record a post id when it is a product or variation.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function mark_post( $post_id ): void {
		$post_id = absint( $post_id );
		if ( $post_id && in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			$this->mark( $post_id );
		}
	}

	/**
	 * Meta changes on products (catches plugins that write meta directly).
	 *
	 * @param mixed  $meta_id   Meta id(s).
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 */
	public function mark_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::IGNORED_META, true ) || 0 === strpos( (string) $meta_key, '_transient' ) ) {
			return;
		}
		$this->mark_post( $object_id );
	}

	/**
	 * A renamed category, tag or brand changes the output of all its products.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function mark_term_products( $term_id, $tt_id, $taxonomy ): void {
		if ( ! is_object_in_taxonomy( 'product', $taxonomy ) ) {
			return;
		}
		$ids = get_objects_in_term( (int) $term_id, $taxonomy );
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$this->mark( $id );
			}
		}
	}

	/**
	 * Write buffered ids.
	 */
	public function flush(): void {
		if ( ! $this->pending ) {
			return;
		}
		self::record( array_keys( $this->pending ), time() );
		$this->pending = array();
	}

	/**
	 * Insert or refresh change rows.
	 *
	 * @param int[] $ids  Product ids.
	 * @param int   $time Unix time.
	 */
	public static function record( array $ids, int $time ): void {
		global $wpdb;
		$table = Installer::table( 'psf_changes' );
		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$values = implode( ',', array_map( static fn( $id ) => '(' . $id . ',' . $time . ')', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers only.
			$wpdb->query( "INSERT INTO {$table} (product_id, changed_at) VALUES {$values} ON DUPLICATE KEY UPDATE changed_at = VALUES(changed_at)" );
		}
	}

	/**
	 * Drop change rows older than a cutoff.
	 *
	 * @param int $before Unix time.
	 */
	public static function prune( int $before ): void {
		global $wpdb;
		$table = Installer::table( 'psf_changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE changed_at < %d", $before ) );
	}
}
