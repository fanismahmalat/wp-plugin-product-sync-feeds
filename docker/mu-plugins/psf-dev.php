<?php
/**
 * Plugin Name: Product Sync Feeds dev settings
 * Description: Local Docker only. Points the plugin at a local license server and protects a plugin folder mounted from the repo.
 *
 * @package ProductSyncFeeds
 */

defined( 'ABSPATH' ) || exit;

// License server running on the host (license-server/compose.yml publishes port 8090).
define( 'PSF_LICENSE_SERVER', getenv( 'PSF_LICENSE_SERVER' ) ? getenv( 'PSF_LICENSE_SERVER' ) : 'http://host.docker.internal:8090' );

$psf_dev_host = (string) wp_parse_url( PSF_LICENSE_SERVER, PHP_URL_HOST );
$psf_dev_port = (int) wp_parse_url( PSF_LICENSE_SERVER, PHP_URL_PORT );

// WordPress only downloads updates from public hosts on ports 80/443/8080; allow the local server.
add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) use ( $psf_dev_host ) {
		return $host === $psf_dev_host ? true : $external;
	},
	10,
	2
);
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) use ( $psf_dev_port ) {
		if ( $psf_dev_port ) {
			$ports[] = $psf_dev_port;
		}
		return $ports;
	}
);

/**
 * Whether the plugin folder is mounted from the host (the repo), not a normal install.
 *
 * @return bool
 */
function psf_dev_plugin_is_mounted(): bool {
	if ( getenv( 'PSF_DEV_MOUNTED' ) ) {
		return true;
	}
	$plugin_dir = WP_PLUGIN_DIR . '/product-sync-feeds';
	$inside     = @stat( $plugin_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$outside    = @stat( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	// A bind mount lives on a different device than the plugins directory around it.
	return $inside && $outside && $inside['dev'] !== $outside['dev'];
}

// An update deletes the plugin folder before installing the new one. When that
// folder is the repo, refuse; sites with a normal install update fine.
add_filter(
	'upgrader_pre_install',
	static function ( $result, $extra ) {
		if ( 'product-sync-feeds/product-sync-feeds.php' === ( $extra['plugin'] ?? '' ) && psf_dev_plugin_is_mounted() ) {
			return new WP_Error( 'psf_dev', 'This dev site runs the plugin from the repo, so updates are blocked here. The download and license check worked.' );
		}
		return $result;
	},
	10,
	2
);
