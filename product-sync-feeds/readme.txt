=== Product Sync Feeds ===
Contributors: fanismahmalat
Tags: woocommerce, product feed, google merchant center, facebook catalog, delta sync
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce product feeds with full and delta (partial) syncs, minute-level schedules, FTP/FTPS/SFTP upload and product filters.

== Description ==

Create any number of feeds under WooCommerce > Product Sync Feeds.

= Feeds =
* Channel templates: Google Merchant Center, Meta (Facebook & Instagram), Pinterest, TikTok and Custom.
* Formats: XML (RSS 2.0 with the g: namespace, or your own root and item elements), CSV, TSV, JSON and JSON Lines.
* Field mapping: any output field name from 50+ built-in sources, or `meta:{key}`, `attr:{attribute}`, `tax:{taxonomy}` and `static:{text}`, each with a prefix, suffix, default, character limit and transforms.
* Variable products: parent and variations, variations only, or parent only.

= Filters =
Product status, product type, stock status, categories (with subcategories) and tags to include or exclude, product IDs, hidden products, empty price, image or description, a price range, and advanced conditions (equals, contains, greater than, regex...) on any source value, matching all or any.

= Delta (partial) sync =
Every run compares each product row with what the feed last sent (a hash per item), so a delta holds only:
* `upsert` rows: new products, or products whose output changed,
* `delete` rows: products deleted, trashed, unpublished or no longer matching the filters.

Two strategies:
* **Changed products only**: re-checks the products in the change log (edits, stock changes, sales, meta and term updates, trash, delete) plus anything with a newer modified date. Cheap enough to run every minute.
* **Compare every product**: scans the whole catalog. Also catches changes no hook reports.

Deltas are delivered as:
* a `-delta-latest` file, rewritten by every run, and timestamped history files,
* the changes API: `GET /wp-json/psf/v1/feeds/{id}/changes?since=<unix time>`, authenticated with the feed API key in the `X-PSF-Key` header. Upserts include the full mapped row.

= Scheduling =
Full rebuilds and delta runs have separate schedules, from every minute up to weekly, run through WooCommerce Action Scheduler in batches. Only one run per feed at a time; failed runs keep the previous file and roll back their state.

= Upload =
FTP, FTPS (explicit TLS) and SFTP through PHP cURL. Choose the full feed, delta files, or both. Files upload under a temporary name and are renamed when complete. Passwords are stored encrypted.

= Updates and license =
Updates come from your own license server (see license-server/ in the repository), not wordpress.org. Enter the license key under WooCommerce > Product Sync Feeds > License. Every site sees new versions; sites with an active license can install them with one click, including auto-updates. Without a key the plugin keeps working; only updates are unavailable.

= Developers =
* REST: `GET /psf/v1/feeds`, `GET /psf/v1/feeds/{id}`, `POST /psf/v1/feeds/{id}/run` (`{"type":"full"|"delta"}`), `GET /psf/v1/feeds/{id}/runs`, `GET /psf/v1/feeds/{id}/changes`.
* WP-CLI: `wp psf list`, `wp psf run <id> --type=delta`, `wp psf schedule`.
* Filters: `psf_channels`, `psf_row`, `psf_query_args`, `psf_include_product`, `psf_include_item`, `psf_resolve_source`, `psf_resolve_dynamic_source`, `psf_product_condition`, `psf_default_brand`, `psf_allow_empty_feed`, `psf_base_dir`, `psf_base_url`.
* Actions: `psf_run_started`, `psf_run_completed`, `psf_run_failed`, `psf_upload_failed`.

== Installation ==

1. Upload the plugin and activate it (WooCommerce must be active).
2. Go to WooCommerce > Product Sync Feeds and add a feed.
3. For minute-level schedules, add a server cron job, because WP-Cron only fires on site visits:
   `* * * * * wp action-scheduler run --hooks=psf_scheduled_full,psf_scheduled_delta,psf_process_batch,psf_maintenance --path=/path/to/wordpress`

== Frequently Asked Questions ==

= What happens on the first run? =
It becomes the baseline. A full run writes no delta file the first time, and a delta run on a new feed sends every product as an upsert.

= Do I still need full runs when deltas are on? =
They are a good safety net (daily, for example). A full run also writes a delta of anything the change log missed.

= What if a channel misses a delta? =
Fetch the full feed, or call the changes API with an older `since`. Deletes are reported for as long as the retention setting says (30 days by default).

== Changelog ==

= 1.0.0 =
* First release.
* License key screen and one-click updates from the license server.
