<?php
/**
 * License screen.
 *
 * @package ProductSyncFeeds
 *
 * @var \ProductSyncFeeds\Licensing\License $license
 * @var array                               $data
 * @var string                              $latest
 */

use ProductSyncFeeds\Admin\Admin;
use ProductSyncFeeds\Licensing\License;

defined( 'ABSPATH' ) || exit;

$psf_has_key = '' !== $license->key();
$psf_labels  = array(
	'active'        => __( 'Active', 'product-sync-feeds' ),
	'expired'       => __( 'Expired', 'product-sync-feeds' ),
	'revoked'       => __( 'Revoked', 'product-sync-feeds' ),
	'disabled'      => __( 'Disabled', 'product-sync-feeds' ),
	'invalid'       => __( 'Invalid', 'product-sync-feeds' ),
	'site_inactive' => __( 'Not activated on this site', 'product-sync-feeds' ),
	'limit'         => __( 'Site limit reached', 'product-sync-feeds' ),
	'missing'       => __( 'No license', 'product-sync-feeds' ),
);
$psf_status  = $psf_has_key ? $data['status'] : 'missing';
?>
<h1><?php esc_html_e( 'Product Sync Feeds license', 'product-sync-feeds' ); ?></h1>
<p><a href="<?php echo esc_url( Admin::url() ); ?>">&larr; <?php esc_html_e( 'All feeds', 'product-sync-feeds' ); ?></a></p>
<p class="description"><?php esc_html_e( 'A license key enables one-click updates from the WordPress dashboard. The plugin keeps working without one.', 'product-sync-feeds' ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="psf-license">
	<input type="hidden" name="action" value="psf_license">
	<?php wp_nonce_field( 'psf_license' ); ?>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Status', 'product-sync-feeds' ); ?></th>
			<td>
				<span class="psf-status psf-status-<?php echo 'active' === $psf_status ? 'completed' : 'failed'; ?>"><?php echo esc_html( $psf_labels[ $psf_status ] ?? ucfirst( $psf_status ) ); ?></span>
				<?php if ( $psf_has_key && '' !== $data['message'] && 'active' !== $psf_status ) : ?>
					<p class="description"><?php echo esc_html( $data['message'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php if ( $psf_has_key ) : ?>
			<tr>
				<th><?php esc_html_e( 'License key', 'product-sync-feeds' ); ?></th>
				<td><code><?php echo esc_html( $license->masked_key() ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Sites', 'product-sync-feeds' ); ?></th>
				<td>
					<?php
					echo esc_html(
						$data['max_sites'] > 0
							/* translators: 1: used sites, 2: allowed sites */
							? sprintf( __( '%1$d of %2$d used', 'product-sync-feeds' ), $data['sites_used'], $data['max_sites'] )
							/* translators: %d: used sites */
							: sprintf( __( '%d used (unlimited)', 'product-sync-feeds' ), $data['sites_used'] )
					);
					?>
					<p class="description"><?php esc_html_e( 'Local and staging sites (localhost, *.local, *.test, staging.*) do not count.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Expires', 'product-sync-feeds' ); ?></th>
				<td><?php echo esc_html( $data['expires_at'] ? mysql2date( get_option( 'date_format' ), $data['expires_at'] ) : __( 'Never', 'product-sync-feeds' ) ); ?></td>
			</tr>
		<?php else : ?>
			<tr>
				<th><label for="psf-license-key"><?php esc_html_e( 'License key', 'product-sync-feeds' ); ?></label></th>
				<td><input type="text" id="psf-license-key" name="license_key" class="regular-text code" placeholder="PSF-XXXX-XXXX-XXXX-XXXX" autocomplete="off" required></td>
			</tr>
		<?php endif; ?>
		<tr>
			<th><?php esc_html_e( 'This site', 'product-sync-feeds' ); ?></th>
			<td><code><?php echo esc_html( License::site_url() ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Version', 'product-sync-feeds' ); ?></th>
			<td>
				<?php
				/* translators: %s: version */
				echo esc_html( sprintf( __( 'Installed: %s', 'product-sync-feeds' ), PSF_VERSION ) );
				if ( '' !== $latest ) {
					/* translators: %s: version */
					echo ' &nbsp;&middot;&nbsp; ' . esc_html( sprintf( __( 'Latest: %s', 'product-sync-feeds' ), $latest ) );
				}
				?>
			</td>
		</tr>
	</table>
	<p class="submit">
		<?php if ( $psf_has_key ) : ?>
			<button type="submit" name="do" value="check" class="button button-primary"><?php esc_html_e( 'Check for updates', 'product-sync-feeds' ); ?></button>
			<button type="submit" name="do" value="deactivate" class="button" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Deactivate the license on this site? Updates stop until a key is entered again.', 'product-sync-feeds' ) ); ?>')"><?php esc_html_e( 'Deactivate license', 'product-sync-feeds' ); ?></button>
		<?php else : ?>
			<button type="submit" name="do" value="activate" class="button button-primary"><?php esc_html_e( 'Activate license', 'product-sync-feeds' ); ?></button>
		<?php endif; ?>
	</p>
	<p class="description">
		<?php
		/* translators: %s: server URL */
		echo esc_html( sprintf( __( 'License server: %s', 'product-sync-feeds' ), License::server() ) );
		?>
	</p>
</form>
