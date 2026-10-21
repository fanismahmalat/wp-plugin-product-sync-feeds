<?php
/**
 * Database schema, activation and deactivation.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds;

use ProductSyncFeeds\Support\Files;
use ProductSyncFeeds\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables.
 */
final class Installer {

	/**
	 * Table names without the WordPress prefix.
	 */
	const TABLES = array( 'psf_feeds', 'psf_runs', 'psf_items', 'psf_changes' );

	/**
	 * Full table name.
	 *
	 * @param string $name Table name without prefix.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
		( new Files() )->ensure_base_dir();
	}

	/**
	 * Deactivation hook: stop every scheduled action, keep the data.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		}
	}

	/**
	 * Run the schema upgrade when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'psf_db_version' ) !== PSF_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$feeds   = self::table( 'psf_feeds' );
		$runs    = self::table( 'psf_runs' );
		$items   = self::table( 'psf_items' );
		$changes = self::table( 'psf_changes' );

		// dbDelta is whitespace sensitive: one field per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$feeds} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  slug varchar(190) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  config longtext NOT NULL,
  api_key varchar(64) NOT NULL DEFAULT '',
  delta_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
  last_full_at datetime NULL DEFAULT NULL,
  last_delta_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  feed_id bigint(20) unsigned NOT NULL,
  type varchar(10) NOT NULL DEFAULT 'full',
  run_trigger varchar(20) NOT NULL DEFAULT 'manual',
  status varchar(20) NOT NULL DEFAULT 'running',
  seq int(10) unsigned NOT NULL DEFAULT 0,
  total int(10) unsigned NOT NULL DEFAULT 0,
  processed int(10) unsigned NOT NULL DEFAULT 0,
  written int(10) unsigned NOT NULL DEFAULT 0,
  upserts int(10) unsigned NOT NULL DEFAULT 0,
  deletes int(10) unsigned NOT NULL DEFAULT 0,
  cursor_from bigint(20) unsigned NOT NULL DEFAULT 0,
  cursor_to bigint(20) unsigned NOT NULL DEFAULT 0,
  state longtext NULL,
  files longtext NULL,
  upload longtext NULL,
  message text NULL,
  started_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY feed_status (feed_id,status)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
  feed_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_key varchar(191) NOT NULL DEFAULT '',
  hash char(32) NOT NULL DEFAULT '',
  state varchar(10) NOT NULL DEFAULT 'active',
  data longtext NULL,
  seen_run bigint(20) unsigned NOT NULL DEFAULT 0,
  changed_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (feed_id,item_id),
  KEY feed_parent (feed_id,parent_id),
  KEY feed_changed (feed_id,changed_at),
  KEY feed_seen (feed_id,state,seen_run)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$changes} (
  product_id bigint(20) unsigned NOT NULL,
  changed_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (product_id),
  KEY changed_at (changed_at)
) {$charset};"
		);

		update_option( 'psf_db_version', PSF_DB_VERSION, false );
	}
}
