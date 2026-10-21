<?php
/**
 * Uninstall: drop tables, options and scheduled actions.
 *
 * Generated files in wp-content/uploads/product-sync-feeds/ are kept unless
 * PSF_DELETE_FILES_ON_UNINSTALL is defined as true, because channels may still
 * be fetching them.
 *
 * @package ProductSyncFeeds
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'product-sync-feeds' );
}

foreach ( array( 'psf_feeds', 'psf_runs', 'psf_items', 'psf_changes' ) as $psf_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$psf_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'psf\\_%'" );

if ( defined( 'PSF_DELETE_FILES_ON_UNINSTALL' ) && PSF_DELETE_FILES_ON_UNINSTALL ) {
	$psf_uploads = wp_upload_dir( null, false );
	$psf_dir     = trailingslashit( $psf_uploads['basedir'] ) . 'product-sync-feeds';
	if ( is_dir( $psf_dir ) ) {
		$psf_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $psf_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $psf_it as $psf_file ) {
			$psf_file->isDir() ? rmdir( $psf_file->getPathname() ) : unlink( $psf_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $psf_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
