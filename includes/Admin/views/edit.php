<?php
/**
 * Feed editor.
 *
 * @package ProductSyncFeeds
 *
 * @var \ProductSyncFeeds\Feed\Feed     $feed
 * @var bool                            $is_new
 * @var array                           $config
 * @var array                           $channels
 * @var \WP_Term[]                      $categories
 * @var \WP_Term[]                      $tags
 * @var \ProductSyncFeeds\Support\Files $files
 */

use ProductSyncFeeds\Admin\Admin;
use ProductSyncFeeds\Feed\FeedConfig;
use ProductSyncFeeds\Product\AttributeResolver;
use ProductSyncFeeds\Product\ConditionEvaluator;
use ProductSyncFeeds\Product\RowBuilder;
use ProductSyncFeeds\Rest\RestController;

defined( 'ABSPATH' ) || exit;

$f          = $config['filters'];
$sched      = $config['schedule'];
$delta      = $config['delta'];
$up         = $config['upload'];
$transforms = RowBuilder::transforms();
$operators  = ConditionEvaluator::operators();
$presets    = FeedConfig::interval_presets();

$psf_mapping_row = static function ( $i, array $m ) use ( $transforms ) {
	$n = 'config[mapping][' . $i . ']';
	?>
	<tr class="psf-row">
		<td class="psf-drag" title="<?php esc_attr_e( 'Move', 'product-sync-feeds' ); ?>"><button type="button" class="button-link psf-up" aria-label="<?php esc_attr_e( 'Move up', 'product-sync-feeds' ); ?>">&#9650;</button><button type="button" class="button-link psf-down" aria-label="<?php esc_attr_e( 'Move down', 'product-sync-feeds' ); ?>">&#9660;</button></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[target]" value="<?php echo esc_attr( $m['target'] ); ?>" class="widefat" required></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[source]" value="<?php echo esc_attr( $m['source'] ); ?>" class="widefat" list="psf-sources" placeholder="id, title, meta:_key, attr:pa_color..."></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[prefix]" value="<?php echo esc_attr( $m['prefix'] ); ?>" class="widefat"></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[suffix]" value="<?php echo esc_attr( $m['suffix'] ); ?>" class="widefat"></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[default]" value="<?php echo esc_attr( $m['default'] ); ?>" class="widefat"></td>
		<td><input type="number" min="0" name="<?php echo esc_attr( $n ); ?>[limit]" value="<?php echo esc_attr( (string) $m['limit'] ); ?>" class="small-text"></td>
		<td>
			<select name="<?php echo esc_attr( $n ); ?>[transforms][]" multiple size="2" class="psf-transforms">
				<?php foreach ( $transforms as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( $key, (array) $m['transforms'], true ) ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><button type="button" class="button-link psf-remove" aria-label="<?php esc_attr_e( 'Remove', 'product-sync-feeds' ); ?>">&times;</button></td>
	</tr>
	<?php
};

$psf_condition_row = static function ( $i, array $c ) use ( $operators ) {
	$n = 'config[filters][conditions][' . $i . ']';
	?>
	<tr class="psf-row">
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[source]" value="<?php echo esc_attr( $c['source'] ); ?>" class="widefat" list="psf-sources" required></td>
		<td>
			<select name="<?php echo esc_attr( $n ); ?>[operator]">
				<?php foreach ( $operators as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $c['operator'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( $c['value'] ); ?>" class="widefat"></td>
		<td><button type="button" class="button-link psf-remove" aria-label="<?php esc_attr_e( 'Remove', 'product-sync-feeds' ); ?>">&times;</button></td>
	</tr>
	<?php
};

$psf_interval_select = static function ( string $name, int $value, int $min ) use ( $presets ) {
	$custom = ! isset( $presets[ $value ] );
	?>
	<span class="psf-interval">
		<select class="psf-interval-preset">
			<?php foreach ( $presets as $minutes => $label ) : ?>
				<?php if ( $minutes >= $min ) : ?>
					<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( ! $custom && $value === $minutes ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
			<option value="custom" <?php selected( $custom ); ?>><?php esc_html_e( 'Custom...', 'product-sync-feeds' ); ?></option>
		</select>
		<span class="psf-interval-custom"><input type="number" min="<?php echo esc_attr( (string) $min ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="small-text psf-interval-minutes"> <?php esc_html_e( 'minutes', 'product-sync-feeds' ); ?></span>
	</span>
	<?php
};

$full_path   = $files->full_path( $feed );
$latest_path = $files->delta_latest_path( $feed );
$changes_url = $feed->id ? rest_url( RestController::NAMESPACE . '/feeds/' . $feed->id . '/changes' ) : '';
?>
<h1><?php echo $is_new ? esc_html__( 'Add feed', 'product-sync-feeds' ) : esc_html( $feed->name ); ?></h1>
<p><a href="<?php echo esc_url( Admin::url() ); ?>">&larr; <?php esc_html_e( 'All feeds', 'product-sync-feeds' ); ?></a>
<?php if ( ! $is_new ) : ?>
	&nbsp;|&nbsp; <a href="<?php echo esc_url( Admin::url( array( 'view' => 'runs', 'feed' => $feed->id ) ) ); ?>"><?php esc_html_e( 'Run history', 'product-sync-feeds' ); ?></a>
	<?php if ( file_exists( $full_path ) ) : ?>
		&nbsp;|&nbsp; <a href="<?php echo esc_url( $files->url( $full_path ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View feed file', 'product-sync-feeds' ); ?></a>
	<?php endif; ?>
<?php endif; ?>
</p>

<datalist id="psf-sources">
	<?php foreach ( AttributeResolver::sources() as $key => $label ) : ?>
		<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
	<?php endforeach; ?>
</datalist>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psf-feed-form">
	<input type="hidden" name="action" value="psf_save_feed">
	<input type="hidden" name="feed_id" value="<?php echo esc_attr( (string) $feed->id ); ?>">
	<input type="hidden" name="psf_tab" value="" id="psf-tab-field">
	<?php wp_nonce_field( 'psf_save_feed' ); ?>

	<nav class="nav-tab-wrapper psf-tabs">
		<a href="#general" class="nav-tab"><?php esc_html_e( 'General', 'product-sync-feeds' ); ?></a>
		<a href="#mapping" class="nav-tab"><?php esc_html_e( 'Field mapping', 'product-sync-feeds' ); ?></a>
		<a href="#filters" class="nav-tab"><?php esc_html_e( 'Filters', 'product-sync-feeds' ); ?></a>
		<a href="#schedule" class="nav-tab"><?php esc_html_e( 'Schedule & delta', 'product-sync-feeds' ); ?></a>
		<a href="#upload" class="nav-tab"><?php esc_html_e( 'Upload', 'product-sync-feeds' ); ?></a>
		<a href="#advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'product-sync-feeds' ); ?></a>
	</nav>

	<section class="psf-panel" id="psf-panel-general">
		<table class="form-table">
			<tr>
				<th><label for="psf-name"><?php esc_html_e( 'Feed name', 'product-sync-feeds' ); ?></label></th>
				<td>
					<input type="text" id="psf-name" name="name" value="<?php echo esc_attr( $feed->name ); ?>" class="regular-text" required>
					<?php if ( ! $is_new ) : ?>
						<p class="description"><?php esc_html_e( 'File name:', 'product-sync-feeds' ); ?> <code><?php echo esc_html( basename( $full_path ) ); ?></code> <?php esc_html_e( '(fixed when the feed was created, so the URL never changes)', 'product-sync-feeds' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'product-sync-feeds' ); ?></th>
				<td><label><input type="checkbox" name="status" value="1" <?php checked( $is_new || 1 === $feed->status ); ?>> <?php esc_html_e( 'Active (scheduled runs enabled)', 'product-sync-feeds' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="psf-channel"><?php esc_html_e( 'Channel', 'product-sync-feeds' ); ?></label></th>
				<td>
					<select id="psf-channel" name="config[channel]">
						<?php foreach ( $channels as $key => $channel ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $config['channel'], $key ); ?>><?php echo esc_html( $channel['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="psf-load-defaults"><?php esc_html_e( 'Load channel field mapping', 'product-sync-feeds' ); ?></button>
				</td>
			</tr>
			<tr>
				<th><label for="psf-format"><?php esc_html_e( 'File format', 'product-sync-feeds' ); ?></label></th>
				<td>
					<select id="psf-format" name="config[format]">
						<?php foreach ( FeedConfig::FORMATS as $format ) : ?>
							<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $config['format'], $format ); ?>><?php echo esc_html( 'jsonl' === $format ? 'JSON Lines' : strtoupper( $format ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="psf-show-xml psf-show-custom">
				<th><?php esc_html_e( 'XML elements', 'product-sync-feeds' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Root', 'product-sync-feeds' ); ?> <input type="text" name="config[xml][root]" value="<?php echo esc_attr( $config['xml']['root'] ); ?>" class="regular-text"></label><br>
					<label><?php esc_html_e( 'Item', 'product-sync-feeds' ); ?> <input type="text" name="config[xml][item]" value="<?php echo esc_attr( $config['xml']['item'] ); ?>" class="regular-text"></label>
					<p class="description"><?php esc_html_e( 'Used by the Custom channel. Google, Meta, Pinterest and TikTok use the RSS 2.0 layout with the g: namespace.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr class="psf-show-xml">
				<th><?php esc_html_e( 'Empty values', 'product-sync-feeds' ); ?></th>
				<td><label><input type="checkbox" name="config[xml][skip_empty]" value="1" <?php checked( $config['xml']['skip_empty'] ); ?>> <?php esc_html_e( 'Leave out empty XML elements', 'product-sync-feeds' ); ?></label></td>
			</tr>
			<tr class="psf-show-csv">
				<th><?php esc_html_e( 'CSV options', 'product-sync-feeds' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Delimiter', 'product-sync-feeds' ); ?>
						<select name="config[csv][delimiter]">
							<?php foreach ( array( ',' => ', (comma)', ';' => '; (semicolon)', '|' => '| (pipe)', 'tab' => 'Tab' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $config['csv']['delimiter'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Enclosure', 'product-sync-feeds' ); ?>
						<select name="config[csv][enclosure]">
							<option value="&quot;" <?php selected( $config['csv']['enclosure'], '"' ); ?>>" (double quote)</option>
							<option value="&#39;" <?php selected( $config['csv']['enclosure'], "'" ); ?>>&#39; (single quote)</option>
						</select>
					</label>
					<label><input type="checkbox" name="config[csv][bom]" value="1" <?php checked( $config['csv']['bom'] ); ?>> <?php esc_html_e( 'UTF-8 BOM (for Excel)', 'product-sync-feeds' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="psf-country"><?php esc_html_e( 'Country / currency', 'product-sync-feeds' ); ?></label></th>
				<td>
					<select id="psf-country" name="config[country]">
						<option value=""><?php esc_html_e( 'Store base country', 'product-sync-feeds' ); ?></option>
						<?php foreach ( WC()->countries->get_countries() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $config['country'], $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="config[currency]">
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: currency code */ __( 'Store currency (%s)', 'product-sync-feeds' ), get_woocommerce_currency() ) ); ?></option>
						<?php foreach ( get_woocommerce_currencies() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $config['currency'], $code ); ?>><?php echo esc_html( $code . ' - ' . $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The currency code is added to price fields. Prices are not converted.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Variable products', 'product-sync-feeds' ); ?></th>
				<td>
					<select name="config[variations]">
						<option value="both" <?php selected( $config['variations'], 'both' ); ?>><?php esc_html_e( 'Parent and each variation', 'product-sync-feeds' ); ?></option>
						<option value="variations" <?php selected( $config['variations'], 'variations' ); ?>><?php esc_html_e( 'Variations only (recommended for Google and Meta)', 'product-sync-feeds' ); ?></option>
						<option value="parents" <?php selected( $config['variations'], 'parents' ); ?>><?php esc_html_e( 'Parent only (cheapest variation price)', 'product-sync-feeds' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
	</section>

	<section class="psf-panel" id="psf-panel-mapping">
		<p class="description"><?php esc_html_e( 'Each row is one output field. Source is a built-in value (pick from the list) or meta:{key}, attr:{attribute}, tax:{taxonomy}, static:{text}. Default is used when the source is empty; limit cuts the value to that many characters.', 'product-sync-feeds' ); ?></p>
		<table class="widefat psf-table" id="psf-mapping">
			<thead>
				<tr>
					<th class="psf-col-move"></th>
					<th><?php esc_html_e( 'Output field', 'product-sync-feeds' ); ?></th>
					<th><?php esc_html_e( 'Source', 'product-sync-feeds' ); ?></th>
					<th><?php esc_html_e( 'Prefix', 'product-sync-feeds' ); ?></th>
					<th><?php esc_html_e( 'Suffix', 'product-sync-feeds' ); ?></th>
					<th><?php esc_html_e( 'Default', 'product-sync-feeds' ); ?></th>
					<th class="psf-col-small"><?php esc_html_e( 'Limit', 'product-sync-feeds' ); ?></th>
					<th><?php esc_html_e( 'Transforms', 'product-sync-feeds' ); ?></th>
					<th class="psf-col-move"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $config['mapping'] as $i => $m ) {
					$psf_mapping_row( $i, $m );
				}
				?>
			</tbody>
		</table>
		<template id="psf-mapping-template">
			<?php
			$psf_mapping_row(
				'__i__',
				array(
					'target'     => '',
					'source'     => '',
					'prefix'     => '',
					'suffix'     => '',
					'default'    => '',
					'limit'      => 0,
					'transforms' => array(),
				)
			);
			?>
		</template>
		<p><button type="button" class="button psf-add" data-table="psf-mapping" data-template="psf-mapping-template"><?php esc_html_e( 'Add field', 'product-sync-feeds' ); ?></button></p>
	</section>

	<section class="psf-panel" id="psf-panel-filters">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Product status', 'product-sync-feeds' ); ?></th>
				<td>
					<?php foreach ( array( 'publish' => __( 'Published', 'product-sync-feeds' ), 'private' => __( 'Private', 'product-sync-feeds' ), 'draft' => __( 'Draft', 'product-sync-feeds' ), 'pending' => __( 'Pending', 'product-sync-feeds' ) ) as $value => $label ) : ?>
						<label class="psf-inline"><input type="checkbox" name="config[filters][post_status][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $f['post_status'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Product types', 'product-sync-feeds' ); ?></th>
				<td>
					<?php foreach ( wc_get_product_types() as $value => $label ) : ?>
						<label class="psf-inline"><input type="checkbox" name="config[filters][product_types][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $f['product_types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'None checked = all types.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Stock status', 'product-sync-feeds' ); ?></th>
				<td>
					<?php foreach ( wc_get_product_stock_status_options() as $value => $label ) : ?>
						<label class="psf-inline"><input type="checkbox" name="config[filters][stock_status][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $f['stock_status'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'None checked = any stock status. Checked per variation.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Categories', 'product-sync-feeds' ); ?></th>
				<td>
					<select name="config[filters][categories_mode]">
						<option value="include" <?php selected( $f['categories_mode'], 'include' ); ?>><?php esc_html_e( 'Only these', 'product-sync-feeds' ); ?></option>
						<option value="exclude" <?php selected( $f['categories_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these', 'product-sync-feeds' ); ?></option>
					</select><br>
					<select name="config[filters][categories][]" multiple size="8" class="psf-multi">
						<?php foreach ( $categories as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $f['categories'], true ) ); ?>><?php echo esc_html( str_repeat( '— ', (int) ( $term->psf_depth ?? 0 ) ) . $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Subcategories are included. Nothing selected = all categories.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tags', 'product-sync-feeds' ); ?></th>
				<td>
					<select name="config[filters][tags_mode]">
						<option value="include" <?php selected( $f['tags_mode'], 'include' ); ?>><?php esc_html_e( 'Only these', 'product-sync-feeds' ); ?></option>
						<option value="exclude" <?php selected( $f['tags_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these', 'product-sync-feeds' ); ?></option>
					</select><br>
					<select name="config[filters][tags][]" multiple size="6" class="psf-multi">
						<?php foreach ( $tags as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $f['tags'], true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="psf-ids"><?php esc_html_e( 'Product IDs', 'product-sync-feeds' ); ?></label></th>
				<td>
					<select name="config[filters][ids_mode]">
						<option value="include" <?php selected( $f['ids_mode'], 'include' ); ?>><?php esc_html_e( 'Only these', 'product-sync-feeds' ); ?></option>
						<option value="exclude" <?php selected( $f['ids_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except these', 'product-sync-feeds' ); ?></option>
					</select><br>
					<textarea id="psf-ids" name="config[filters][ids]" rows="2" class="large-text" placeholder="12, 34, 56"><?php echo esc_textarea( implode( ', ', $f['ids'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Parent product IDs, separated by commas or spaces.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Exclude', 'product-sync-feeds' ); ?></th>
				<td>
					<label><input type="checkbox" name="config[filters][exclude_hidden]" value="1" <?php checked( $f['exclude_hidden'] ); ?>> <?php esc_html_e( 'Products hidden from the catalog', 'product-sync-feeds' ); ?></label><br>
					<label><input type="checkbox" name="config[filters][exclude_empty_price]" value="1" <?php checked( $f['exclude_empty_price'] ); ?>> <?php esc_html_e( 'Products without a price', 'product-sync-feeds' ); ?></label><br>
					<label><input type="checkbox" name="config[filters][exclude_empty_image]" value="1" <?php checked( $f['exclude_empty_image'] ); ?>> <?php esc_html_e( 'Products without an image', 'product-sync-feeds' ); ?></label><br>
					<label><input type="checkbox" name="config[filters][exclude_empty_description]" value="1" <?php checked( $f['exclude_empty_description'] ); ?>> <?php esc_html_e( 'Products without a description', 'product-sync-feeds' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Price range', 'product-sync-feeds' ); ?></th>
				<td>
					<input type="text" name="config[filters][price_min]" value="<?php echo esc_attr( $f['price_min'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'min', 'product-sync-feeds' ); ?>">
					&ndash;
					<input type="text" name="config[filters][price_max]" value="<?php echo esc_attr( $f['price_max'] ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'max', 'product-sync-feeds' ); ?>">
					<p class="description"><?php esc_html_e( 'Compared with the current price of each product or variation.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Advanced conditions', 'product-sync-feeds' ); ?></th>
				<td>
					<p>
						<?php esc_html_e( 'Include a product when', 'product-sync-feeds' ); ?>
						<select name="config[filters][conditions_match]">
							<option value="all" <?php selected( $f['conditions_match'], 'all' ); ?>><?php esc_html_e( 'all conditions match', 'product-sync-feeds' ); ?></option>
							<option value="any" <?php selected( $f['conditions_match'], 'any' ); ?>><?php esc_html_e( 'any condition matches', 'product-sync-feeds' ); ?></option>
						</select>
					</p>
					<table class="widefat psf-table" id="psf-conditions">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'product-sync-feeds' ); ?></th>
								<th><?php esc_html_e( 'Operator', 'product-sync-feeds' ); ?></th>
								<th><?php esc_html_e( 'Value', 'product-sync-feeds' ); ?></th>
								<th class="psf-col-move"></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $f['conditions'] as $i => $c ) {
								$psf_condition_row( $i, $c );
							}
							?>
						</tbody>
					</table>
					<template id="psf-condition-template">
						<?php
						$psf_condition_row(
							'__i__',
							array(
								'source'   => '',
								'operator' => 'equals',
								'value'    => '',
							)
						);
						?>
					</template>
					<p><button type="button" class="button psf-add" data-table="psf-conditions" data-template="psf-condition-template"><?php esc_html_e( 'Add condition', 'product-sync-feeds' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Example: brand | is not empty, or meta:_exclude_from_feed | does not equal | yes. Number operators read the leading number, so price works too.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
		</table>
	</section>

	<section class="psf-panel" id="psf-panel-schedule">
		<h2><?php esc_html_e( 'Full feed', 'product-sync-feeds' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Rebuild automatically', 'product-sync-feeds' ); ?></th>
				<td>
					<label><input type="checkbox" name="config[schedule][full_enabled]" value="1" <?php checked( $sched['full_enabled'] ); ?>> <?php esc_html_e( 'Regenerate the full file', 'product-sync-feeds' ); ?></label><br>
					<?php $psf_interval_select( 'config[schedule][full_interval]', (int) $sched['full_interval'], FeedConfig::MIN_FULL_INTERVAL ); ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Delta (partial) sync', 'product-sync-feeds' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A delta contains only the products that were added, changed or removed since the previous run, each marked with an action field (upsert or delete). Every product row is compared with what was last sent, so unchanged products never appear.', 'product-sync-feeds' ); ?></p>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Delta sync', 'product-sync-feeds' ); ?></th>
				<td>
					<label><input type="checkbox" name="config[delta][enabled]" value="1" <?php checked( $delta['enabled'] ); ?>> <?php esc_html_e( 'Enable delta runs and delta files', 'product-sync-feeds' ); ?></label><br>
					<?php $psf_interval_select( 'config[schedule][delta_interval]', (int) $sched['delta_interval'], FeedConfig::MIN_DELTA_INTERVAL ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'How changes are found', 'product-sync-feeds' ); ?></th>
				<td>
					<label><input type="radio" name="config[delta][strategy]" value="changes" <?php checked( $delta['strategy'], 'changes' ); ?>> <strong><?php esc_html_e( 'Changed products only', 'product-sync-feeds' ); ?></strong> &mdash; <?php esc_html_e( 're-checks products that were edited, restocked, sold, trashed or deleted since the last run. Fast enough to run every minute.', 'product-sync-feeds' ); ?></label><br>
					<label><input type="radio" name="config[delta][strategy]" value="compare" <?php checked( $delta['strategy'], 'compare' ); ?>> <strong><?php esc_html_e( 'Compare every product', 'product-sync-feeds' ); ?></strong> &mdash; <?php esc_html_e( 'scans the whole catalog and keeps only differences. Slower, but also catches changes no hook reports (prices from dynamic-pricing plugins, scheduled sales, direct database edits).', 'product-sync-feeds' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Full runs', 'product-sync-feeds' ); ?></th>
				<td><label><input type="checkbox" name="config[delta][full_emits_delta]" value="1" <?php checked( $delta['full_emits_delta'] ); ?>> <?php esc_html_e( 'Also write a delta file for the changes a full run finds', 'product-sync-feeds' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="psf-action-field"><?php esc_html_e( 'Action field name', 'product-sync-feeds' ); ?></label></th>
				<td><input type="text" id="psf-action-field" name="config[delta][action_field]" value="<?php echo esc_attr( $delta['action_field'] ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Retention', 'product-sync-feeds' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Keep', 'product-sync-feeds' ); ?> <input type="number" min="1" max="1000" name="config[delta][keep_files]" value="<?php echo esc_attr( (string) $delta['keep_files'] ); ?>" class="small-text"> <?php esc_html_e( 'delta files', 'product-sync-feeds' ); ?></label><br>
					<label><?php esc_html_e( 'Report deletions through the changes API for', 'product-sync-feeds' ); ?> <input type="number" min="1" max="365" name="config[delta][tombstone_days]" value="<?php echo esc_attr( (string) $delta['tombstone_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'product-sync-feeds' ); ?></label>
				</td>
			</tr>
			<?php if ( ! $is_new ) : ?>
			<tr>
				<th><?php esc_html_e( 'Delta outputs', 'product-sync-feeds' ); ?></th>
				<td>
					<p><?php esc_html_e( 'Latest delta file (rewritten every run, empty when nothing changed):', 'product-sync-feeds' ); ?><br><code><?php echo esc_html( $files->url( $latest_path ) ); ?></code></p>
					<p><?php esc_html_e( 'Changes API (JSON, any point in time):', 'product-sync-feeds' ); ?><br><code><?php echo esc_html( $changes_url ); ?>?since=&lt;unix time&gt;</code><br>
					<?php esc_html_e( 'Send the API key from the Advanced tab in the X-PSF-Key header.', 'product-sync-feeds' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( Admin::action_url( 'reset_state', $feed->id ) ); ?>" data-psf-confirm="<?php esc_attr_e( 'Forget what was synced? The next run sends every product again as a new baseline.', 'product-sync-feeds' ); ?>"><?php esc_html_e( 'Reset sync state', 'product-sync-feeds' ); ?></a></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
	</section>

	<section class="psf-panel" id="psf-panel-upload">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Upload', 'product-sync-feeds' ); ?></th>
				<td><label><input type="checkbox" name="config[upload][enabled]" value="1" <?php checked( $up['enabled'] ); ?>> <?php esc_html_e( 'Upload files after each successful run', 'product-sync-feeds' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Files', 'product-sync-feeds' ); ?></th>
				<td>
					<select name="config[upload][files]">
						<option value="full" <?php selected( $up['files'], 'full' ); ?>><?php esc_html_e( 'Full feed', 'product-sync-feeds' ); ?></option>
						<option value="delta" <?php selected( $up['files'], 'delta' ); ?>><?php esc_html_e( 'Delta files', 'product-sync-feeds' ); ?></option>
						<option value="both" <?php selected( $up['files'], 'both' ); ?>><?php esc_html_e( 'Both', 'product-sync-feeds' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="psf-protocol"><?php esc_html_e( 'Protocol', 'product-sync-feeds' ); ?></label></th>
				<td>
					<select id="psf-protocol" name="config[upload][protocol]">
						<option value="ftp" <?php selected( $up['protocol'], 'ftp' ); ?>>FTP</option>
						<option value="ftps" <?php selected( $up['protocol'], 'ftps' ); ?>>FTPS (explicit TLS)</option>
						<option value="sftp" <?php selected( $up['protocol'], 'sftp' ); ?>>SFTP</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="psf-host"><?php esc_html_e( 'Host and port', 'product-sync-feeds' ); ?></label></th>
				<td>
					<input type="text" id="psf-host" name="config[upload][host]" value="<?php echo esc_attr( $up['host'] ); ?>" class="regular-text" placeholder="ftp.example.com">
					<input type="number" name="config[upload][port]" value="<?php echo esc_attr( $up['port'] ? (string) $up['port'] : '' ); ?>" class="small-text" placeholder="21 / 22" min="0" max="65535">
				</td>
			</tr>
			<tr>
				<th><label for="psf-user"><?php esc_html_e( 'Username', 'product-sync-feeds' ); ?></label></th>
				<td><input type="text" id="psf-user" name="config[upload][username]" value="<?php echo esc_attr( $up['username'] ); ?>" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="psf-pass"><?php esc_html_e( 'Password', 'product-sync-feeds' ); ?></label></th>
				<td>
					<input type="password" id="psf-pass" name="config[upload][password]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo '' !== $up['password'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'product-sync-feeds' ) : ''; ?>">
					<?php if ( '' !== $up['password'] ) : ?>
						<label><input type="checkbox" name="config[upload][clear_password]" value="1"> <?php esc_html_e( 'Remove saved password', 'product-sync-feeds' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Stored encrypted with your site salts.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="psf-path"><?php esc_html_e( 'Remote directory', 'product-sync-feeds' ); ?></label></th>
				<td><input type="text" id="psf-path" name="config[upload][path]" value="<?php echo esc_attr( $up['path'] ); ?>" class="regular-text" placeholder="/feeds"><p class="description"><?php esc_html_e( 'FTP: relative to the login directory. SFTP: absolute path. Missing directories are created.', 'product-sync-feeds' ); ?></p></td>
			</tr>
			<tr class="psf-show-ftp">
				<th><?php esc_html_e( 'FTP options', 'product-sync-feeds' ); ?></th>
				<td>
					<label><input type="checkbox" name="config[upload][passive]" value="1" <?php checked( $up['passive'] ); ?>> <?php esc_html_e( 'Passive mode', 'product-sync-feeds' ); ?></label><br>
					<label><input type="checkbox" name="config[upload][verify_ssl]" value="1" <?php checked( $up['verify_ssl'] ); ?>> <?php esc_html_e( 'Verify the TLS certificate (FTPS)', 'product-sync-feeds' ); ?></label>
				</td>
			</tr>
			<tr class="psf-show-sftp">
				<th><label for="psf-hostkey"><?php esc_html_e( 'Host key MD5', 'product-sync-feeds' ); ?></label></th>
				<td><input type="text" id="psf-hostkey" name="config[upload][host_key_md5]" value="<?php echo esc_attr( $up['host_key_md5'] ); ?>" class="regular-text code" placeholder="32 hex characters"><p class="description"><?php esc_html_e( 'Optional. When set, the connection is refused if the server key does not match.', 'product-sync-feeds' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="psf-timeout"><?php esc_html_e( 'Timeout', 'product-sync-feeds' ); ?></label></th>
				<td><input type="number" id="psf-timeout" name="config[upload][timeout]" value="<?php echo esc_attr( (string) $up['timeout'] ); ?>" class="small-text" min="10" max="1800"> <?php esc_html_e( 'seconds', 'product-sync-feeds' ); ?></td>
			</tr>
			<tr>
				<th></th>
				<td><button type="button" class="button" id="psf-test-upload"><?php esc_html_e( 'Test connection', 'product-sync-feeds' ); ?></button> <span id="psf-test-result" aria-live="polite"></span></td>
			</tr>
		</table>
	</section>

	<section class="psf-panel" id="psf-panel-advanced">
		<table class="form-table">
			<tr>
				<th><label for="psf-batch"><?php esc_html_e( 'Batch size', 'product-sync-feeds' ); ?></label></th>
				<td><input type="number" id="psf-batch" name="config[batch_size]" value="<?php echo esc_attr( (string) $config['batch_size'] ); ?>" class="small-text" min="10" max="2000"> <p class="description"><?php esc_html_e( 'Products per background step. Each step also stops after 20 seconds. Lower it on slow hosting.', 'product-sync-feeds' ); ?></p></td>
			</tr>
			<?php if ( ! $is_new ) : ?>
			<tr>
				<th><?php esc_html_e( 'API key', 'product-sync-feeds' ); ?></th>
				<td>
					<input type="text" readonly value="<?php echo esc_attr( $feed->api_key ); ?>" class="regular-text code" onclick="this.select()">
					<a class="button" href="<?php echo esc_url( Admin::action_url( 'regenerate_key', $feed->id ) ); ?>" data-psf-confirm="<?php esc_attr_e( 'Integrations using the old key will stop working.', 'product-sync-feeds' ); ?>"><?php esc_html_e( 'Regenerate', 'product-sync-feeds' ); ?></a>
					<p class="description"><?php esc_html_e( 'Lets external systems read the changes API for this feed only.', 'product-sync-feeds' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
	</section>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save feed', 'product-sync-feeds' ); ?></button>
		<button type="submit" class="button" name="save_and_run" value="1"><?php esc_html_e( 'Save and run full', 'product-sync-feeds' ); ?></button>
	</p>
</form>
