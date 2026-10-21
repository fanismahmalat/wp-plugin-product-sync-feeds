<?php
/**
 * Plugin Name:       Product Sync Feeds
 * Description:       WooCommerce product feeds for Google, Meta, Pinterest, TikTok and custom channels, with full and delta (partial) syncs, minute-level schedules, FTP/FTPS/SFTP upload and product filters.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Fanis Mahmalat
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-sync-feeds
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      10.2
 *
 * @package ProductSyncFeeds
 */

defined( 'ABSPATH' ) || exit;

define( 'PSF_VERSION', '1.0.0' );
define( 'PSF_DB_VERSION', '1' );
define( 'PSF_FILE', __FILE__ );
define( 'PSF_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSF_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ProductSyncFeeds\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = PSF_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ProductSyncFeeds\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ProductSyncFeeds\\Installer', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Product Sync Feeds requires WooCommerce to be active.', 'product-sync-feeds' ) . '</p></div>';
				}
			);
			return;
		}
		ProductSyncFeeds\Plugin::instance()->boot();
	},
	20
);
