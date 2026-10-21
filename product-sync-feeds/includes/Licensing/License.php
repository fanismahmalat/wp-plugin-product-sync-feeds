<?php
/**
 * License key storage and activation against the license server.
 *
 * The server URL is the plugin's "Update URI" header, or the PSF_LICENSE_SERVER
 * constant when defined (wp-config.php or an mu-plugin, e.g. for staging).
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Licensing;

use ProductSyncFeeds\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * The site's license.
 */
class License {

	const OPTION = 'psf_license';

	/**
	 * License server base URL, without trailing slash.
	 *
	 * @return string
	 */
	public static function server(): string {
		if ( defined( 'PSF_LICENSE_SERVER' ) && PSF_LICENSE_SERVER ) {
			return untrailingslashit( (string) PSF_LICENSE_SERVER );
		}
		return untrailingslashit( self::update_uri() );
	}

	/**
	 * The plugin's Update URI header.
	 *
	 * @return string
	 */
	public static function update_uri(): string {
		$data = get_file_data( PSF_FILE, array( 'uri' => 'Update URI' ) );
		return (string) $data['uri'];
	}

	/**
	 * Stored state.
	 *
	 * @return array{key:string,status:string,message:string,expires_at:?string,max_sites:int,sites_used:int,checked_at:int}
	 */
	public function data(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'key'        => '',
				'status'     => 'missing',
				'message'    => '',
				'expires_at' => null,
				'max_sites'  => 0,
				'sites_used' => 0,
				'checked_at' => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Plain license key ('' when none).
	 *
	 * @return string
	 */
	public function key(): string {
		return Crypto::decrypt( (string) $this->data()['key'] );
	}

	/**
	 * Key with the middle hidden, for display.
	 *
	 * @return string
	 */
	public function masked_key(): string {
		$key = $this->key();
		return strlen( $key ) > 8 ? substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 ) : $key;
	}

	/**
	 * Whether updates are enabled for this site.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->data()['status'] && '' !== $this->key();
	}

	/**
	 * This site's URL as the server identifies it.
	 *
	 * @return string
	 */
	public static function site_url(): string {
		return home_url( '/' );
	}

	/**
	 * Activate a key on this site.
	 *
	 * @param string $key License key.
	 * @return array|\WP_Error Server license summary.
	 */
	public function activate( string $key ) {
		$key = strtoupper( preg_replace( '/\s+/', '', $key ) );
		if ( '' === $key ) {
			return new \WP_Error( 'psf_license_empty', __( 'Enter a license key.', 'product-sync-feeds' ) );
		}
		$response = $this->request( '/v1/activate', array( 'license_key' => $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$license = (array) ( $response['license'] ?? array() );
		if ( empty( $response['success'] ) ) {
			return new \WP_Error( 'psf_license_' . ( $license['status'] ?? 'invalid' ), (string) ( $license['message'] ?? __( 'The license could not be activated.', 'product-sync-feeds' ) ) );
		}
		$this->store( $license, Crypto::encrypt( $key ) );
		self::flush_update_caches();
		return $license;
	}

	/**
	 * Release this site's slot and forget the key. The key is removed locally
	 * even when the server cannot be reached.
	 *
	 * @return true|\WP_Error
	 */
	public function deactivate() {
		$key    = $this->key();
		$result = true;
		if ( '' !== $key ) {
			$response = $this->request( '/v1/deactivate', array( 'license_key' => $key ) );
			if ( is_wp_error( $response ) ) {
				$result = $response;
			}
		}
		delete_option( self::OPTION );
		self::flush_update_caches();
		return $result;
	}

	/**
	 * Save the license summary the server returned (from activation or an update check).
	 *
	 * @param array       $license Server license summary.
	 * @param string|null $key     Encrypted key to store, null to keep the current one.
	 */
	public function store( array $license, ?string $key = null ): void {
		$data               = $this->data();
		$data['key']        = null === $key ? $data['key'] : $key;
		$data['status']     = sanitize_key( $license['status'] ?? 'invalid' );
		$data['message']    = sanitize_text_field( (string) ( $license['message'] ?? '' ) );
		$data['expires_at'] = isset( $license['expires_at'] ) ? sanitize_text_field( (string) $license['expires_at'] ) : null;
		$data['max_sites']  = (int) ( $license['max_sites'] ?? 0 );
		$data['sites_used'] = (int) ( $license['sites_used'] ?? 0 );
		$data['checked_at'] = time();
		update_option( self::OPTION, $data, false );
	}

	/**
	 * POST JSON to the license server.
	 *
	 * @param string $path Endpoint path.
	 * @param array  $body Body (site and versions are added).
	 * @return array|\WP_Error Decoded response.
	 */
	public function request( string $path, array $body ) {
		$server = self::server();
		if ( ! in_array( wp_parse_url( $server, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || ! wp_parse_url( $server, PHP_URL_HOST ) ) {
			return new \WP_Error( 'psf_license_server', __( 'No license server is configured.', 'product-sync-feeds' ) );
		}
		$body += array(
			'site_url'       => self::site_url(),
			'plugin_version' => PSF_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
		$response = wp_remote_post(
			$server . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'psf_license_http', sprintf( __( 'Could not reach the license server: %s', 'product-sync-feeds' ), $response->get_error_message() ) );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $decoded ) ) {
			$message = is_array( $decoded ) && ! empty( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'HTTP %d', $code );
			/* translators: %s: error message */
			return new \WP_Error( 'psf_license_http', sprintf( __( 'License server error: %s', 'product-sync-feeds' ), $message ) );
		}
		return $decoded;
	}

	/**
	 * Make WordPress ask for updates again on the next admin page load.
	 */
	public static function flush_update_caches(): void {
		delete_transient( Updater::CACHE );
		delete_site_transient( 'update_plugins' );
	}
}
