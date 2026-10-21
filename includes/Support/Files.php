<?php
/**
 * Feed file locations.
 *
 * Layout under wp-content/uploads/product-sync-feeds/:
 *   {slug}/{slug}.{ext}                         full feed
 *   {slug}/delta/{slug}-delta-latest.{ext}      most recent delta (always rewritten)
 *   {slug}/delta/{slug}-delta-{stamp}-{run}.{ext} delta history (only runs with changes)
 *   .tmp/                                       working files, not web readable
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Support;

use ProductSyncFeeds\Feed\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Path and URL helpers for generated files.
 */
class Files {

	const DIR_NAME = 'product-sync-feeds';

	/**
	 * Base directory (no trailing slash).
	 *
	 * @return string
	 */
	public function base_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return (string) apply_filters( 'psf_base_dir', trailingslashit( $uploads['basedir'] ) . self::DIR_NAME );
	}

	/**
	 * Base URL (no trailing slash).
	 *
	 * @return string
	 */
	public function base_url(): string {
		$uploads = wp_upload_dir( null, false );
		return (string) apply_filters( 'psf_base_url', trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME );
	}

	/**
	 * Create the base and tmp directories with directory-listing protection.
	 */
	public function ensure_base_dir(): void {
		$base = $this->base_dir();
		$tmp  = $base . '/.tmp';
		wp_mkdir_p( $tmp );

		foreach ( array( $base, $tmp ) as $dir ) {
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		if ( ! file_exists( $tmp . '/.htaccess' ) ) {
			file_put_contents( $tmp . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * File extension for a format.
	 *
	 * @param string $format Feed format.
	 * @return string
	 */
	public static function extension( string $format ): string {
		$map = array(
			'xml'   => 'xml',
			'csv'   => 'csv',
			'tsv'   => 'tsv',
			'json'  => 'json',
			'jsonl' => 'jsonl',
		);
		return $map[ $format ] ?? 'xml';
	}

	/**
	 * Feed directory.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	public function feed_dir( Feed $feed ): string {
		return $this->base_dir() . '/' . $feed->slug;
	}

	/**
	 * Full feed path.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	public function full_path( Feed $feed ): string {
		return $this->feed_dir( $feed ) . '/' . $feed->slug . '.' . self::extension( $feed->format() );
	}

	/**
	 * Delta directory.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	public function delta_dir( Feed $feed ): string {
		return $this->feed_dir( $feed ) . '/delta';
	}

	/**
	 * Latest delta path.
	 *
	 * @param Feed $feed Feed.
	 * @return string
	 */
	public function delta_latest_path( Feed $feed ): string {
		return $this->delta_dir( $feed ) . '/' . $feed->slug . '-delta-latest.' . self::extension( $feed->format() );
	}

	/**
	 * History delta path for a run.
	 *
	 * @param Feed $feed   Feed.
	 * @param int  $run_id Run id.
	 * @param int  $time   Unix time of the run cursor.
	 * @return string
	 */
	public function delta_history_path( Feed $feed, int $run_id, int $time ): string {
		return $this->delta_dir( $feed ) . '/' . $feed->slug . '-delta-' . gmdate( 'Ymd-His', $time ) . '-' . $run_id . '.' . self::extension( $feed->format() );
	}

	/**
	 * Working file for a run.
	 *
	 * @param Feed   $feed   Feed.
	 * @param int    $run_id Run id.
	 * @param string $kind   full|delta.
	 * @return string
	 */
	public function tmp_path( Feed $feed, int $run_id, string $kind ): string {
		$this->ensure_base_dir();
		return $this->base_dir() . '/.tmp/' . $feed->slug . '-' . $run_id . '-' . $kind . '.' . self::extension( $feed->format() );
	}

	/**
	 * Public URL for a path inside the base dir.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public function url( string $path ): string {
		$base = $this->base_dir();
		if ( 0 !== strpos( $path, $base ) ) {
			return '';
		}
		return $this->base_url() . substr( $path, strlen( $base ) );
	}

	/**
	 * Move a working file into place atomically (same filesystem).
	 *
	 * @param string $from Working path.
	 * @param string $to   Final path.
	 * @return bool
	 */
	public function publish( string $from, string $to ): bool {
		wp_mkdir_p( dirname( $to ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic swap so readers never see half-written feeds.
		if ( @rename( $from, $to ) ) {
			return true;
		}
		// Different filesystems (filtered base dir): fall back to copy.
		$ok = @copy( $from, $to );
		wp_delete_file( $from );
		return $ok;
	}

	/**
	 * Delete delta history files beyond the newest $keep.
	 *
	 * @param Feed $feed Feed.
	 * @param int  $keep Files to keep.
	 * @return int Deleted file count.
	 */
	public function prune_delta_history( Feed $feed, int $keep ): int {
		$files = glob( $this->delta_dir( $feed ) . '/' . $feed->slug . '-delta-2*' );
		if ( ! is_array( $files ) || count( $files ) <= $keep ) {
			return 0;
		}
		sort( $files ); // Timestamped names sort chronologically.
		$remove = array_slice( $files, 0, count( $files ) - $keep );
		foreach ( $remove as $file ) {
			wp_delete_file( $file );
		}
		return count( $remove );
	}

	/**
	 * Delta history files, newest first.
	 *
	 * @param Feed $feed Feed.
	 * @return string[]
	 */
	public function delta_history( Feed $feed ): array {
		$files = glob( $this->delta_dir( $feed ) . '/' . $feed->slug . '-delta-2*' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		rsort( $files );
		return $files;
	}

	/**
	 * Remove everything a feed generated.
	 *
	 * @param Feed $feed Feed.
	 */
	public function delete_feed_files( Feed $feed ): void {
		$dir = $this->feed_dir( $feed );
		if ( '' === $feed->slug || ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
