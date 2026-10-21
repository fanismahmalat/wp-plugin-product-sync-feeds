<?php
/**
 * End-to-end smoke test. Run on a disposable WooCommerce site:
 *
 *   wp eval-file wp-content/plugins/product-sync-feeds/tests/smoke-test.php
 *
 * Creates products and feeds, runs full and delta syncs and checks the files,
 * the sync state and the changes API. Everything it creates is deleted at the end.
 *
 * Optional upload checks: define PSF_TEST_FTP / PSF_TEST_SFTP as
 * "host|user|password|path" before running.
 *
 * @package ProductSyncFeeds
 */

use ProductSyncFeeds\Feed\Channels;
use ProductSyncFeeds\Feed\FeedConfig;
use ProductSyncFeeds\Plugin;
use ProductSyncFeeds\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

$psf      = Plugin::instance();
$failures = 0;
$created  = array();

$check = static function ( bool $ok, string $label ) use ( &$failures ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
};

$run = static function ( $feed_id, string $type ) use ( $psf ) {
	$psf->change_tracker()->flush();
	sleep( 2 ); // Cursors have one-second resolution.
	$feed   = $psf->feeds()->find( $feed_id );
	$run_id = $psf->generator()->start( $feed, $type, 'cli' );
	if ( is_wp_error( $run_id ) ) {
		echo '  run error: ' . $run_id->get_error_message() . "\n";
		return null;
	}
	return $psf->generator()->run_to_completion( $run_id );
};

$csv_rows = static function ( string $path ): array {
	$fh     = fopen( $path, 'rb' ); // phpcs:ignore
	$header = fgetcsv( $fh, 0, ',', '"', '\\' );
	$rows   = array();
	while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore
		$rows[] = array_combine( $header, $line );
	}
	fclose( $fh ); // phpcs:ignore
	return $rows;
};

echo "Product Sync Feeds smoke test\n";

// ---------------------------------------------------------------- catalog.
$cat_in  = wp_insert_term( 'PSF In ' . wp_generate_password( 4, false ), 'product_cat' );
$cat_out = wp_insert_term( 'PSF Out ' . wp_generate_password( 4, false ), 'product_cat' );

$simple = new WC_Product_Simple();
$simple->set_name( 'PSF Simple' );
$simple->set_regular_price( '10' );
$simple->set_sku( 'PSF-S-' . wp_generate_password( 4, false ) );
$simple->set_description( '<p>Simple &amp; <b>bold</b></p>' );
$simple->set_category_ids( array( $cat_in['term_id'] ) );
$simple->save();

$no_price = new WC_Product_Simple();
$no_price->set_name( 'PSF No Price' );
$no_price->set_category_ids( array( $cat_in['term_id'] ) );
$no_price->save();

$excluded = new WC_Product_Simple();
$excluded->set_name( 'PSF Excluded Category' );
$excluded->set_regular_price( '5' );
$excluded->set_category_ids( array( $cat_out['term_id'] ) );
$excluded->save();

$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );

$variable = new WC_Product_Variable();
$variable->set_name( 'PSF Variable' );
$variable->set_attributes( array( $attr ) );
$variable->set_category_ids( array( $cat_in['term_id'] ) );
$variable->save();

$variations = array();
foreach ( array( 'S' => '20', 'M' => '25' ) as $size => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $variable->get_id() );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( $price );
	$v->save();
	$variations[ $size ] = $v;
}
WC_Product_Variable::sync( $variable->get_id() );

$created = array( $simple->get_id(), $no_price->get_id(), $excluded->get_id(), $variable->get_id() );

// ------------------------------------------------------------------ feeds.
$csv_config = FeedConfig::sanitize(
	array(
		'channel'    => 'custom',
		'format'     => 'csv',
		'mapping'    => Channels::default_mapping( 'custom' ),
		'variations' => 'variations',
		'filters'    => array(
			'post_status'         => array( 'publish' ),
			'categories_mode'     => 'include',
			'categories'          => array( $cat_in['term_id'] ),
			'exclude_hidden'      => 1,
			'exclude_empty_price' => 1,
		),
		'schedule'   => array(
			'full_enabled'   => 1,
			'full_interval'  => 1440,
			'delta_interval' => 5,
		),
		'delta'      => array(
			'enabled'          => 1,
			'strategy'         => 'changes',
			'full_emits_delta' => 1,
			'keep_files'       => 10,
		),
	)
);
$csv_id = $psf->feeds()->create( 'PSF Smoke CSV', $csv_config );

$xml_config = FeedConfig::sanitize(
	array(
		'channel'    => 'google',
		'format'     => 'xml',
		'mapping'    => Channels::default_mapping( 'google' ),
		'variations' => 'variations',
		'filters'    => array(
			'post_status'         => array( 'publish' ),
			'categories'          => array( $cat_in['term_id'] ),
			'exclude_hidden'      => 1,
			'exclude_empty_price' => 1,
			'conditions_match'    => 'all',
			'conditions'          => array(
				array(
					'source'   => 'current_price_raw',
					'operator' => 'lt',
					'value'    => '15',
				),
			),
		),
		'delta'      => array(
			'enabled'  => 1,
			'strategy' => 'compare',
		),
		'xml'        => array( 'skip_empty' => 1 ),
	)
);
$xml_id = $psf->feeds()->create( 'PSF Smoke XML', $xml_config );

echo "Schedules\n";
$psf->scheduler()->sync( $psf->feeds()->find( $csv_id ) );
$check( false !== as_next_scheduled_action( Scheduler::FULL_HOOK, array( $csv_id ), Scheduler::GROUP ), 'full schedule registered' );
$next_delta = as_next_scheduled_action( Scheduler::DELTA_HOOK, array( $csv_id ), Scheduler::GROUP );
$check( is_int( $next_delta ) && $next_delta - time() <= 5 * 60 + 60, 'delta schedule every 5 minutes' );

// ------------------------------------------------------------ full (CSV).
echo "Full run (CSV)\n";
$r    = $run( $csv_id, 'full' );
$feed = $psf->feeds()->find( $csv_id );
$path = $psf->files()->full_path( $feed );
$check( $r && 'completed' === $r->status, 'full run completed' );
$rows = file_exists( $path ) ? $csv_rows( $path ) : array();
$ids  = array_column( $rows, 'id' );
sort( $ids );
$want = array( (string) $simple->get_id(), (string) $variations['S']->get_id(), (string) $variations['M']->get_id() );
sort( $want );
$check( $ids === $want, 'rows = simple + 2 variations (no-price and excluded category filtered out)' );
$check( ! isset( $r->files['delta'] ), 'first full run is a baseline (no delta file)' );
$by_id = array_column( $rows, null, 'id' );
$check( ( $by_id[ $variations['S']->get_id() ]['parent_id'] ?? '' ) === (string) $variable->get_id(), 'variation carries parent id' );

// ------------------------------------------------------------ deltas.
echo "Delta run after edits\n";
$simple = wc_get_product( $simple->get_id() );
$simple->set_regular_price( '12' );
$simple->save();
$deleted_m = $variations['M']->get_id(); // delete() resets the id to 0.
$variations['M']->delete( true );
$no_price = wc_get_product( $no_price->get_id() );
$no_price->set_regular_price( '7' );
$no_price->save();

$r     = $run( $csv_id, 'delta' );
$check( $r && 'completed' === $r->status, 'delta run completed' );
$check( $r && 2 === $r->upserts && 1 === $r->deletes, 'delta counted 2 upserts and 1 delete (got ' . ( $r ? $r->upserts . '/' . $r->deletes : '-' ) . ')' );
$feed  = $psf->feeds()->find( $csv_id );
$delta = $psf->files()->delta_latest_path( $feed );
$drows = file_exists( $delta ) ? $csv_rows( $delta ) : array();
$acts  = array();
foreach ( $drows as $row ) {
	$acts[ $row['id'] ] = $row['sync_action'];
}
$check( ( $acts[ $simple->get_id() ] ?? '' ) === 'upsert', 'changed price is an upsert' );
$check( ( $acts[ $no_price->get_id() ] ?? '' ) === 'upsert', 'product that now has a price is added' );
$check( ( $acts[ $deleted_m ] ?? '' ) === 'delete', 'deleted variation is a delete' );
$check( ! isset( $acts[ $variations['S']->get_id() ] ), 'unchanged variation is not in the delta' );
$check( isset( $r->files['delta'] ) && file_exists( $r->files['delta'] ), 'timestamped delta history file written' );

echo "Delta run with nothing changed\n";
$r = $run( $csv_id, 'delta' );
$check( $r && 0 === $r->upserts && 0 === $r->deletes, 'no changes, no rows' );
$check( $r && ! isset( $r->files['delta'] ), 'no history file for an empty delta' );
$check( 0 === count( $csv_rows( $delta ) ), 'latest delta rewritten empty (header only)' );

echo "Trash and filter change\n";
wp_trash_post( $simple->get_id() );
$excluded = wc_get_product( $excluded->get_id() );
$excluded->set_category_ids( array( $cat_in['term_id'] ) );
$excluded->save();
$r     = $run( $csv_id, 'delta' );
$drows = $csv_rows( $delta );
$acts  = array_column( $drows, 'sync_action', 'id' );
$check( ( $acts[ $simple->get_id() ] ?? '' ) === 'delete', 'trashed product is a delete' );
$check( ( $acts[ $excluded->get_id() ] ?? '' ) === 'upsert', 'product moved into the category is added' );

echo "Full run after deltas\n";
$r = $run( $csv_id, 'full' );
$check( $r && 0 === $r->upserts && 0 === $r->deletes, 'full run finds nothing the deltas missed' );
$rows = $csv_rows( $psf->files()->full_path( $psf->feeds()->find( $csv_id ) ) );
$check( 3 === count( $rows ), 'full feed has 3 rows (S variation, no-price product, moved product)' );

// ------------------------------------------------------------ changes API.
echo "Changes API\n";
$feed    = $psf->feeds()->find( $csv_id );
$request = new WP_REST_Request( 'GET', '/psf/v1/feeds/' . $csv_id . '/changes' );
$request->set_header( 'X-PSF-Key', $feed->api_key );
$request->set_param( 'since', '0' );
wp_set_current_user( 0 );
$response = rest_do_request( $request );
$data     = $response->get_data();
$check( 200 === $response->get_status(), 'API key grants access' );
$actions = array_count_values( array_column( (array) ( $data['items'] ?? array() ), 'action' ) );
$check( 3 === ( $actions['upsert'] ?? 0 ) && 2 === ( $actions['delete'] ?? 0 ), 'changes since 0: 3 upserts, 2 deletes (got ' . wp_json_encode( $actions ) . ')' );
$upsert = current( array_filter( (array) $data['items'], static fn( $i ) => 'upsert' === $i['action'] ) );
$check( is_array( $upsert['data'] ?? null ) && isset( $upsert['data']['price'] ), 'upserts include the mapped row' );
$request2 = new WP_REST_Request( 'GET', '/psf/v1/feeds/' . $csv_id . '/changes' );
$request2->set_header( 'X-PSF-Key', 'wrong' );
$check( 401 === rest_do_request( $request2 )->get_status(), 'wrong key is rejected' );
$request3 = new WP_REST_Request( 'GET', '/psf/v1/feeds/' . $csv_id . '/changes' );
$request3->set_header( 'X-PSF-Key', $feed->api_key );
$request3->set_param( 'since', (string) $data['next']['since'] );
$check( 0 === count( rest_do_request( $request3 )->get_data()['items'] ), 'polling again from next.since returns nothing new' );

// ------------------------------------------------------------ XML + compare.
echo "Google XML feed with compare strategy\n";
$r   = $run( $xml_id, 'full' );
$xml = simplexml_load_file( $psf->files()->full_path( $psf->feeds()->find( $xml_id ) ) );
$check( false !== $xml, 'XML is well formed' );
$items = $xml ? $xml->channel->item : array();
$check( $xml && 2 === count( $items ), 'condition price < 15 keeps 2 items (got ' . ( $xml ? count( $items ) : 0 ) . ')' );
$g = $xml ? $items[0]->children( 'http://base.google.com/ns/1.0' ) : null;
$check( $g && '' !== (string) $g->price && false !== strpos( (string) $g->price, get_woocommerce_currency() ), 'g:price has currency' );

global $wpdb;
// A change no hook reports and post_modified does not show: rename straight in the database.
$wpdb->update( $wpdb->posts, array( 'post_title' => 'PSF Renamed In DB' ), array( 'ID' => $excluded->get_id() ) );
wp_cache_flush();
$r = $run( $xml_id, 'delta' );
$check( $r && 1 === $r->upserts, 'compare strategy catches a direct database edit (got ' . ( $r ? $r->upserts : '-' ) . ')' );
$dx = simplexml_load_file( $psf->files()->delta_latest_path( $psf->feeds()->find( $xml_id ) ) );
$check( $dx && 'upsert' === (string) $dx->channel->item[0]->sync_action, 'XML delta item has an unprefixed <sync_action>' );

// ------------------------------------------------------------ uploads.
foreach ( array( 'ftp' => 'PSF_TEST_FTP', 'sftp' => 'PSF_TEST_SFTP' ) as $protocol => $const ) {
	if ( ! defined( $const ) ) {
		continue;
	}
	echo strtoupper( $protocol ) . " upload\n";
	list( $host, $user, $pass, $dir ) = explode( '|', constant( $const ) );
	$cfg = FeedConfig::sanitize(
		array(
			'upload' => array(
				'enabled'  => 1,
				'protocol' => $protocol,
				'host'     => $host,
				'username' => $user,
				'password' => $pass,
				'path'     => $dir,
				'passive'  => 1,
				'files'    => 'both',
			),
		)
	)['upload'];
	$test = $psf->uploader()->test( $cfg );
	$check( true === $test, 'connection test' . ( is_wp_error( $test ) ? ': ' . $test->get_error_message() : '' ) );
	$feed                   = $psf->feeds()->find( $csv_id );
	$feed->config['upload'] = $cfg;
	$psf->feeds()->save( $feed );
	$r = $run( $csv_id, 'full' );
	$check( $r && ! empty( $r->upload['uploaded'] ) && '' === $r->upload['error'], 'uploaded ' . ( $r ? implode( ', ', (array) $r->upload['uploaded'] ) . ' ' . $r->upload['error'] : '' ) );
	$feed->config['upload']['enabled'] = false;
	$psf->feeds()->save( $feed );
}

// ------------------------------------------------------------ cleanup.
foreach ( array( $csv_id, $xml_id ) as $id ) {
	$feed = $psf->feeds()->find( $id );
	$psf->scheduler()->unschedule( $id );
	$psf->state()->reset( $id );
	$psf->runs()->delete_for( $id );
	$psf->files()->delete_feed_files( $feed );
	$psf->feeds()->delete( $id );
}
foreach ( $created as $id ) {
	$product = wc_get_product( $id );
	if ( $product ) {
		$product->delete( true );
	}
}
wp_delete_term( $cat_in['term_id'], 'product_cat' );
wp_delete_term( $cat_out['term_id'], 'product_cat' );

echo $failures ? "\n{$failures} check(s) failed.\n" : "\nAll checks passed.\n";
