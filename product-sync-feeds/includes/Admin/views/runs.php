<?php
/**
 * Run history of a feed.
 *
 * @package ProductSyncFeeds
 *
 * @var \ProductSyncFeeds\Feed\Feed     $feed
 * @var object[]                        $runs
 * @var \ProductSyncFeeds\Support\Files $files
 */

use ProductSyncFeeds\Admin\Admin;

defined( 'ABSPATH' ) || exit;

$psf_labels = array(
	'full'         => __( 'Full feed', 'product-sync-feeds' ),
	'delta'        => __( 'Delta', 'product-sync-feeds' ),
	'delta_latest' => __( 'Latest delta', 'product-sync-feeds' ),
);
?>
<h1>
	<?php
	/* translators: %s: feed name */
	echo esc_html( sprintf( __( 'Run history: %s', 'product-sync-feeds' ), $feed->name ) );
	?>
</h1>
<p>
	<a href="<?php echo esc_url( Admin::url() ); ?>">&larr; <?php esc_html_e( 'All feeds', 'product-sync-feeds' ); ?></a>
	&nbsp;|&nbsp; <a href="<?php echo esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $feed->id ) ) ); ?>"><?php esc_html_e( 'Edit feed', 'product-sync-feeds' ); ?></a>
	&nbsp;|&nbsp; <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=product-sync-feeds' ) ); ?>"><?php esc_html_e( 'Logs', 'product-sync-feeds' ); ?></a>
</p>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th class="psf-col-id">#</th>
			<th><?php esc_html_e( 'Type', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Status', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Started', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Duration', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Products', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Rows', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Upserts / deletes', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Files', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Notes', 'product-sync-feeds' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $runs ) : ?>
		<tr><td colspan="10"><?php esc_html_e( 'No runs yet.', 'product-sync-feeds' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $runs as $run ) : ?>
		<?php
		$start    = strtotime( $run->started_at . ' UTC' );
		$end      = $run->finished_at ? strtotime( $run->finished_at . ' UTC' ) : time();
		$duration = human_time_diff( $start, max( $start + 1, $end ) );
		?>
		<tr>
			<td><?php echo esc_html( (string) $run->id ); ?></td>
			<td><?php echo esc_html( ucfirst( $run->type ) ); ?><br><small><?php echo esc_html( $run->run_trigger ); ?></small></td>
			<td><span class="psf-status psf-status-<?php echo esc_attr( $run->status ); ?>"><?php echo esc_html( ucfirst( $run->status ) ); ?></span></td>
			<td><?php echo esc_html( get_date_from_gmt( $run->started_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
			<td><?php echo esc_html( $duration ); ?></td>
			<td><?php echo esc_html( $run->processed . ' / ' . $run->total ); ?></td>
			<td><?php echo esc_html( (string) $run->written ); ?></td>
			<td><?php echo esc_html( $run->upserts . ' / ' . $run->deletes ); ?></td>
			<td>
				<?php foreach ( $run->files as $kind => $path ) : ?>
					<?php if ( file_exists( $path ) ) : ?>
						<div><a href="<?php echo esc_url( $files->url( $path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $psf_labels[ $kind ] ?? $kind ); ?></a></div>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ! empty( $run->upload['uploaded'] ) ) : ?>
					<div><small><?php esc_html_e( 'Uploaded:', 'product-sync-feeds' ); ?> <?php echo esc_html( implode( ', ', $run->upload['uploaded'] ) ); ?></small></div>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) $run->message ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
