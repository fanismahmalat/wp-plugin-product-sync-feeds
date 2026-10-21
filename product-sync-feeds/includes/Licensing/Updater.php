<?php
/**
 * Plugin updates from the license server instead of wordpress.org.
 *
 * The "Update URI" header makes WordPress skip wordpress.org for this plugin
 * and ask the update_plugins_{hostname} filter instead. The server always
 * reports the latest version; the download link is only included for a site
 * with an active license, otherwise WordPress shows the update with "Automatic
 * update is unavailable" and our prompt to enter a key.
 *
 * Download links are signed and short-lived, while WordPress caches update
 * data for hours, so the link is refreshed right before downloading.
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Licensing;

use ProductSyncFeeds\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Update integration.
 */
class Updater {

	const CACHE      = 'psf_update_check';
	const CACHE_TTL  = HOUR_IN_SECONDS;
	const ERROR_TTL  = 30 * MINUTE_IN_SECONDS;
	const SLUG       = 'product-sync-feeds';

	/** @var License */
	private $license;

	/**
	 * Constructor.
	 *
	 * @param License $license License.
	 */
	public function __construct( License $license ) {
		$this->license = $license;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$host = wp_parse_url( sanitize_url( License::update_uri() ), PHP_URL_HOST );
		if ( $host ) {
			add_filter( 'update_plugins_' . $host, array( $this, 'filter_update' ), 10, 3 );
		}
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'refresh_download' ), 10, 4 );
		add_action( 'in_plugin_update_message-' . plugin_basename( PSF_FILE ), array( $this, 'update_message' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
		// Whoever resets WordPress's update data (core, WP-CLI, other tools) also wants a fresh answer from us.
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
		add_action(
			'load-update-core.php',
			static function () {
				if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own "Check again" link.
					delete_transient( self::CACHE );
				}
			}
		);
	}

	/**
	 * Ask the server about the latest release (cached).
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null Server response {license, update}, null when unavailable.
	 */
	public function check( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return empty( $cached['error'] ) ? $cached : null;
			}
		}
		$response = $this->license->request( '/v1/check', array( 'license_key' => $this->license->key() ) );
		if ( is_wp_error( $response ) ) {
			set_transient( self::CACHE, array( 'error' => $response->get_error_message() ), self::ERROR_TTL );
			return null;
		}
		if ( ! empty( $response['license'] ) && '' !== $this->license->key() ) {
			$this->license->store( (array) $response['license'] );
		}
		set_transient( self::CACHE, $response, self::CACHE_TTL );
		return $response;
	}

	/**
	 * Clear the cached check.
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * update_plugins_{hostname}: tell WordPress about the latest version.
	 *
	 * @param array|false $update      Update data from other filters.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function filter_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( PSF_FILE ) !== $plugin_file ) {
			return $update;
		}
		$response = $this->check();
		$latest   = is_array( $response['update'] ?? null ) ? $response['update'] : null;
		if ( ! $latest || empty( $latest['version'] ) ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => (string) $latest['version'],
			'url'          => (string) ( $latest['homepage'] ?? '' ),
			'package'      => (string) ( $latest['package'] ?? '' ),
			'tested'       => (string) ( $latest['tested'] ?? '' ),
			'requires'     => (string) ( $latest['requires'] ?? '' ),
			'requires_php' => (string) ( $latest['requires_php'] ?? '' ),
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * "View details" popup.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$response = $this->check();
		$latest   = is_array( $response['update'] ?? null ) ? $response['update'] : null;
		if ( ! $latest ) {
			return $result;
		}
		$headers = get_plugin_data( PSF_FILE, false, false );
		return (object) array(
			'name'          => $headers['Name'],
			'slug'          => self::SLUG,
			'version'       => (string) $latest['version'],
			'author'        => $headers['Author'],
			'homepage'      => (string) ( $latest['homepage'] ?? '' ),
			'requires'      => (string) ( $latest['requires'] ?? '' ),
			'requires_php'  => (string) ( $latest['requires_php'] ?? '' ),
			'tested'        => (string) ( $latest['tested'] ?? '' ),
			'last_updated'  => (string) ( $latest['released_at'] ?? '' ),
			'download_link' => (string) ( $latest['package'] ?? '' ),
			'sections'      => array(
				'description' => wpautop( esc_html( $headers['Description'] ) ),
				'changelog'   => $this->changelog_html( (string) ( $latest['changelog'] ?? '' ) ),
			),
		);
	}

	/**
	 * Before WordPress downloads our package, swap in a fresh signed link
	 * (the cached one may have expired) and stop with the license message
	 * when the license is no longer active.
	 *
	 * @param bool|string|\WP_Error $reply      Short-circuit value.
	 * @param string                $package    Package URL.
	 * @param object                $upgrader   Upgrader.
	 * @param array                 $hook_extra Context.
	 * @return bool|string|\WP_Error
	 */
	public function refresh_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, License::server() . '/v1/download' ) ) {
			return $reply;
		}
		$response = $this->check( true );
		$fresh    = (string) ( $response['update']['package'] ?? '' );
		if ( '' === $fresh ) {
			$message = (string) ( $response['license']['message'] ?? __( 'The update could not be downloaded. Check your license.', 'product-sync-feeds' ) );
			return new \WP_Error( 'psf_no_package', $message );
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', esc_html( $fresh ) );
		}
		return download_url( $fresh, 300 ); // Temp file path or WP_Error, as core expects.
	}

	/**
	 * Extra text under the update notice on the Plugins screen when there is no package.
	 *
	 * @param array  $plugin_data Plugin data.
	 * @param object $response    Update data.
	 */
	public function update_message( $plugin_data, $response ): void {
		if ( ! empty( $response->package ) ) {
			return;
		}
		$data    = $this->license->data();
		$message = '' !== $data['message'] ? $data['message'] : __( 'Enter your license key to enable updates.', 'product-sync-feeds' );
		printf(
			' <strong>%1$s</strong> <a href="%2$s">%3$s</a>',
			esc_html( $message ),
			esc_url( Admin::url( array( 'view' => 'license' ) ) ),
			esc_html__( 'Manage license', 'product-sync-feeds' )
		);
	}

	/**
	 * Small Markdown subset (bullets, headings, bold, code) to HTML.
	 *
	 * @param string $markdown Changelog.
	 * @return string
	 */
	private function changelog_html( string $markdown ): string {
		if ( '' === trim( $markdown ) ) {
			return '<p>' . esc_html__( 'No changelog provided.', 'product-sync-feeds' ) . '</p>';
		}
		$html    = '';
		$in_list = false;
		foreach ( preg_split( '/\r?\n/', $markdown ) as $line ) {
			$line = trim( $line );
			$text = esc_html( (string) preg_replace( '/^([*-]|#+|=+)\s*|\s*=+$/', '', $line ) );
			$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
			$text = (string) preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
			$item = (bool) preg_match( '/^[*-]\s+/', $line );
			if ( $item && ! $in_list ) {
				$html   .= '<ul>';
				$in_list = true;
			} elseif ( ! $item && $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( '' === $line ) {
				continue;
			}
			if ( $item ) {
				$html .= '<li>' . $text . '</li>';
			} elseif ( preg_match( '/^(#+|=)/', $line ) ) {
				$html .= '<h4>' . $text . '</h4>';
			} else {
				$html .= '<p>' . $text . '</p>';
			}
		}
		return $html . ( $in_list ? '</ul>' : '' );
	}
}
