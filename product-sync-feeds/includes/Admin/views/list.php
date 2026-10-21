<?php
/**
 * Feed list.
 *
 * @package ProductSyncFeeds
 *
 * @var \ProductSyncFeeds\Feed\Feed[]          $feeds
 * @var \ProductSyncFeeds\Feed\RunRepository   $runs
 * @var \ProductSyncFeeds\Support\Files        $files
 * @var \ProductSyncFeeds\Sync\Scheduler       $scheduler
 */

use ProductSyncFeeds\Admin\Admin;
use ProductSyncFeeds\Feed\Channels;
use ProductSyncFeeds\Feed\FeedConfig;
use ProductSyncFeeds\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

$psf_interval = static function ( int $minutes ): string {
	$presets = FeedConfig::interval_presets();
	if ( isset( $presets[ $minutes ] ) ) {
		return $presets[ $minutes ];
	}
	/* translators: %d: minutes */
	return sprintf( _n( 'Every %d minute', 'Every %d minutes', $minutes, 'product-sync-feeds' ), $minutes );
};
$psf_when     = static function ( ?string $gmt ): string {
	return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '&mdash;';
};
?>
<h1 class="wp-heading-inline"><?php esc_html_e( 'Product Sync Feeds', 'product-sync-feeds' ); ?></h1>
<a href="<?php echo esc_url( Admin::url( array( 'view' => 'edit' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add feed', 'product-sync-feeds' ); ?></a>
<hr class="wp-header-end">

<?php if ( ! $feeds ) : ?>
	<div class="psf-empty">
		<p><?php esc_html_e( 'No feeds yet. Create one to export your products to Google, Meta, Pinterest, TikTok or any custom integration.', 'product-sync-feeds' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( Admin::url( array( 'view' => 'edit' ) ) ); ?>"><?php esc_html_e( 'Create your first feed', 'product-sync-feeds' ); ?></a></p>
	</div>
	<?php
	return;
endif;
?>

<table class="wp-list-table widefat fixed striped psf-feeds" data-psf-poll="1">
	<thead>
		<tr>
			<th class="column-primary"><?php esc_html_e( 'Feed', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Channel / format', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Schedule', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Last runs', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Files', 'product-sync-feeds' ); ?></th>
			<th><?php esc_html_e( 'Run', 'product-sync-feeds' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	foreach ( $feeds as $feed ) :
		$channel = Channels::get( (string) $feed->get( 'channel' ) );
		$active  = $runs->active_for( $feed->id );
		$last    = $runs->last( $feed->id );
		$full    = $files->full_path( $feed );
		$latest  = $files->delta_latest_path( $feed );
		?>
		<tr data-feed="<?php echo esc_attr( (string) $feed->id ); ?>">
			<td class="column-primary">
				<strong><a href="<?php echo esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $feed->id ) ) ); ?>"><?php echo esc_html( $feed->name ); ?></a></strong>
				<?php if ( ! $feed->status ) : ?>
					<span class="psf-badge psf-badge-muted"><?php esc_html_e( 'Paused', 'product-sync-feeds' ); ?></span>
				<?php endif; ?>
				<div class="row-actions">
					<span><a href="<?php echo esc_url( Admin::url( array( 'view' => 'edit', 'feed' => $feed->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'product-sync-feeds' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( Admin::url( array( 'view' => 'runs', 'feed' => $feed->id ) ) ); ?>"><?php esc_html_e( 'History', 'product-sync-feeds' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( Admin::action_url( 'toggle', $feed->id ) ); ?>"><?php echo $feed->status ? esc_html__( 'Pause', 'product-sync-feeds' ) : esc_html__( 'Activate', 'product-sync-feeds' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( Admin::action_url( 'duplicate', $feed->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'product-sync-feeds' ); ?></a> | </span>
					<span class="trash"><a href="<?php echo esc_url( Admin::action_url( 'delete', $feed->id ) ); ?>" data-psf-confirm="<?php esc_attr_e( 'Delete this feed, its files and its sync state?', 'product-sync-feeds' ); ?>"><?php esc_html_e( 'Delete', 'product-sync-feeds' ); ?></a></span>
				</div>
			</td>
			<td><?php echo esc_html( $channel['label'] ); ?><br><code><?php echo esc_html( strtoupper( $feed->format() ) ); ?></code></td>
			<td>
				<?php if ( $feed->get( 'schedule.full_enabled' ) ) : ?>
					<div><?php esc_html_e( 'Full:', 'product-sync-feeds' ); ?> <?php echo esc_html( $psf_interval( (int) $feed->get( 'schedule.full_interval' ) ) ); ?></div>
				<?php else : ?>
					<div><?php esc_html_e( 'Full: manual', 'product-sync-feeds' ); ?></div>
				<?php endif; ?>
				<?php if ( $feed->delta_enabled() ) : ?>
					<div><?php esc_html_e( 'Delta:', 'product-sync-feeds' ); ?> <?php echo esc_html( $psf_interval( (int) $feed->get( 'schedule.delta_interval' ) ) ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<div><?php esc_html_e( 'Full:', 'product-sync-feeds' ); ?> <?php echo wp_kses_post( $psf_when( $feed->last_full_at ) ); ?></div>
				<?php if ( $feed->delta_enabled() ) : ?>
					<div><?php esc_html_e( 'Delta:', 'product-sync-feeds' ); ?> <?php echo wp_kses_post( $psf_when( $feed->last_delta_at ) ); ?></div>
				<?php endif; ?>
				<?php if ( $last && 'failed' === $last->status ) : ?>
					<div class="psf-error-text"><?php esc_html_e( 'Last run failed:', 'product-sync-feeds' ); ?> <?php echo esc_html( (string) $last->message ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( file_exists( $full ) ) : ?>
					<div><a href="<?php echo esc_url( $files->url( $full ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full feed', 'product-sync-feeds' ); ?></a></div>
				<?php endif; ?>
				<?php if ( file_exists( $latest ) ) : ?>
					<div><a href="<?php echo esc_url( $files->url( $latest ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Latest delta', 'product-sync-feeds' ); ?></a></div>
				<?php endif; ?>
			</td>
			<td class="psf-run-cell">
				<?php if ( $active ) : ?>
					<span class="psf-progress">
						<?php
						/* translators: 1: run type, 2: processed, 3: total */
						echo esc_html( sprintf( __( '%1$s run: %2$d / %3$d', 'product-sync-feeds' ), ucfirst( $active->type ), $active->processed, $active->total ) );
						?>
					</span>
					<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'cancel', $feed->id ) ); ?>"><?php esc_html_e( 'Cancel', 'product-sync-feeds' ); ?></a>
				<?php else : ?>
					<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'run_full', $feed->id ) ); ?>"><?php esc_html_e( 'Full', 'product-sync-feeds' ); ?></a>
					<?php if ( $feed->delta_enabled() ) : ?>
						<a class="button button-small" href="<?php echo esc_url( Admin::action_url( 'run_delta', $feed->id ) ); ?>"><?php esc_html_e( 'Delta', 'product-sync-feeds' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<p class="description psf-cron-hint">
	<?php esc_html_e( 'Schedules run through WooCommerce Action Scheduler, which WP-Cron triggers on site visits. For minute-level schedules add a server cron job:', 'product-sync-feeds' ); ?>
	<code>* * * * * wp action-scheduler run --hooks=<?php echo esc_html( implode( ',', Scheduler::HOOKS ) ); ?> --path=<?php echo esc_html( untrailingslashit( ABSPATH ) ); ?></code>
</p>
