<?php
/**
 * FTP, FTPS (explicit TLS) and SFTP upload through PHP cURL.
 *
 * Files are uploaded under a temporary name and renamed on the server, so a
 * channel fetching the file never reads a partial upload. SFTP needs a cURL
 * build with libssh2/libssh (check_support() reports it).
 *
 * @package ProductSyncFeeds
 */

namespace ProductSyncFeeds\Transport;

use ProductSyncFeeds\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Remote uploader.
 */
class Uploader {

	/**
	 * Whether the server can use a protocol.
	 *
	 * @param string $protocol ftp|ftps|sftp.
	 * @return true|\WP_Error
	 */
	public function check_support( string $protocol ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'psf_no_curl', __( 'The PHP cURL extension is required for uploads.', 'product-sync-feeds' ) );
		}
		$info      = curl_version();
		$protocols = array_map( 'strtolower', (array) ( $info['protocols'] ?? array() ) );
		$need      = 'sftp' === $protocol ? 'sftp' : ( 'ftps' === $protocol ? 'ftps' : 'ftp' );
		if ( 'ftps' === $need && in_array( 'ftp', $protocols, true ) && ! empty( $info['ssl_version'] ) ) {
			return true; // Explicit TLS runs over ftp://.
		}
		if ( ! in_array( $need, $protocols, true ) ) {
			/* translators: %s: protocol name */
			return new \WP_Error( 'psf_protocol', sprintf( __( 'This server cURL build does not support %s.', 'product-sync-feeds' ), strtoupper( $protocol ) ) );
		}
		return true;
	}

	/**
	 * Upload a file.
	 *
	 * @param string $local       Local path.
	 * @param string $remote_name File name on the server.
	 * @param array  $cfg         Feed upload config.
	 * @return true|\WP_Error
	 */
	public function upload( string $local, string $remote_name, array $cfg ) {
		$support = $this->check_support( (string) $cfg['protocol'] );
		if ( is_wp_error( $support ) ) {
			return $support;
		}
		if ( ! is_readable( $local ) ) {
			return new \WP_Error( 'psf_missing_file', __( 'File to upload not found.', 'product-sync-feeds' ) );
		}

		$dir   = $this->remote_dir( $cfg );
		$tmp   = '.' . $remote_name . '.part';
		$final = $remote_name;

		if ( 'sftp' === $cfg['protocol'] ) {
			$post = array( 'rename "' . $dir . $tmp . '" "' . $dir . $final . '"' );
			// SFTP rename fails when the target exists on some servers: remove it first, ignoring errors.
			array_unshift( $post, '*rm "' . $dir . $final . '"' );
		} else {
			$post = array( 'RNFR ' . $tmp, 'RNTO ' . $final );
		}

		$fh = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return new \WP_Error( 'psf_read', __( 'Cannot read the file to upload.', 'product-sync-feeds' ) );
		}

		$ch = $this->handle( $cfg, $this->url( $cfg, $dir . rawurlencode( $tmp ) ) );
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $fh );
		curl_setopt( $ch, CURLOPT_INFILESIZE, filesize( $local ) );
		curl_setopt( $ch, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR_RETRY );
		curl_setopt( $ch, CURLOPT_POSTQUOTE, $post );

		$ok    = curl_exec( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $ok ) {
			return new \WP_Error( 'psf_upload', $error ? $error : __( 'Upload failed.', 'product-sync-feeds' ) );
		}
		return true;
	}

	/**
	 * Log in and list the remote directory.
	 *
	 * @param array $cfg Upload config.
	 * @return true|\WP_Error
	 */
	public function test( array $cfg ) {
		$support = $this->check_support( (string) $cfg['protocol'] );
		if ( is_wp_error( $support ) ) {
			return $support;
		}
		if ( '' === (string) $cfg['host'] ) {
			return new \WP_Error( 'psf_host', __( 'Enter a host.', 'product-sync-feeds' ) );
		}
		$result = $this->list_dir( $cfg, $this->remote_dir( $cfg ) );
		// cURL 9 = remote access denied (FTP CWD refused), 78 = remote file not found (SFTP).
		if ( true !== $result && '/' !== $this->remote_dir( $cfg ) && in_array( $result[0], array( 9, 78 ), true ) ) {
			// The directory may simply not exist yet; uploads create it. Check the login works.
			$root = $this->list_dir( $cfg, '/' );
			if ( true === $root ) {
				return true;
			}
		}
		return true === $result ? true : new \WP_Error( 'psf_connect', $result[1] );
	}

	/**
	 * List a directory.
	 *
	 * @param array  $cfg Upload config.
	 * @param string $dir Directory with trailing slash.
	 * @return true|array{0:int,1:string} True or [curl errno, message].
	 */
	private function list_dir( array $cfg, string $dir ) {
		$ch = $this->handle( $cfg, $this->url( $cfg, $dir ) );
		curl_setopt( $ch, CURLOPT_DIRLISTONLY, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$ok    = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		curl_close( $ch );
		return false === $ok ? array( $errno, $error ) : true;
	}

	/**
	 * Common cURL handle.
	 *
	 * @param array  $cfg Upload config.
	 * @param string $url URL.
	 * @return resource|\CurlHandle
	 */
	private function handle( array $cfg, string $url ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_USERNAME, (string) $cfg['username'] );
		curl_setopt( $ch, CURLOPT_PASSWORD, Crypto::decrypt( (string) $cfg['password'] ) );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $cfg['timeout'] );

		if ( 'sftp' === $cfg['protocol'] ) {
			curl_setopt( $ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD | CURLSSH_AUTH_KEYBOARD );
			if ( '' !== (string) $cfg['host_key_md5'] ) {
				curl_setopt( $ch, CURLOPT_SSH_HOST_PUBLIC_KEY_MD5, (string) $cfg['host_key_md5'] );
			}
		} else {
			if ( empty( $cfg['passive'] ) ) {
				curl_setopt( $ch, CURLOPT_FTPPORT, '-' );
			}
			if ( 'ftps' === $cfg['protocol'] ) {
				curl_setopt( $ch, CURLOPT_USE_SSL, CURLUSESSL_ALL );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, ! empty( $cfg['verify_ssl'] ) );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, ! empty( $cfg['verify_ssl'] ) ? 2 : 0 );
			}
		}
		return $ch;
	}

	/**
	 * Remote directory with leading and trailing slash.
	 *
	 * @param array $cfg Upload config.
	 * @return string
	 */
	private function remote_dir( array $cfg ): string {
		$path = trim( (string) $cfg['path'], '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}

	/**
	 * Build the URL. For FTP a single leading slash is relative to the login
	 * directory; SFTP paths are absolute.
	 *
	 * @param array  $cfg  Upload config.
	 * @param string $path Path starting with a slash.
	 * @return string
	 */
	private function url( array $cfg, string $path ): string {
		$scheme = 'sftp' === $cfg['protocol'] ? 'sftp' : 'ftp';
		$port   = (int) $cfg['port'] > 0 ? ':' . (int) $cfg['port'] : '';
		$parts  = array_map( static fn( $p ) => rawurlencode( rawurldecode( $p ) ), explode( '/', $path ) );
		return $scheme . '://' . $cfg['host'] . $port . implode( '/', $parts );
	}
}
