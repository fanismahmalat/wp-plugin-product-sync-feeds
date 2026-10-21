# Product Sync Feeds

WooCommerce product feeds for Google Merchant Center, Meta, Pinterest, TikTok and custom integrations, with **delta (partial) syncs**, minute-level schedules, FTP/FTPS/SFTP upload and product filters.

## Features

- **Feeds:** XML (RSS 2.0 with `g:` namespace or custom elements), CSV, TSV, JSON, JSON Lines. Field mapping from 50+ built-in values plus `meta:`, `attr:`, `tax:` and `static:` sources, with prefix, suffix, default, length limit and transforms.
- **Filters:** status, type, stock, categories and tags (include or exclude), product IDs, hidden or incomplete products, price range, and custom conditions (equals, contains, greater than, regex…).
- **Delta syncs:** each run compares every row with what was last sent, so deltas contain only `upsert` and `delete` rows. Choose "changed products only" (fast, every minute) or "compare every product" (also catches changes no hook reports).
- **Delta delivery:** a latest-delta file, timestamped history files, and a changes API (`GET /wp-json/psf/v1/feeds/{id}/changes?since=…`, authenticated with a per-feed key).
- **Scheduling:** separate full and delta schedules from every minute to weekly, through WooCommerce Action Scheduler, in batches.
- **Upload:** FTP, FTPS and SFTP via cURL, uploaded under a temporary name and renamed when complete.
- **Developers:** REST API, WP-CLI (`wp psf list|run|schedule`), filters and actions. See [readme.txt](readme.txt).

## Install

Download the zip from [Releases](../../releases) (or zip the repo as `product-sync-feeds/`), then **Plugins → Add New → Upload Plugin**. Requires WordPress 6.2+, WooCommerce, PHP 7.4+.

For minute-level schedules add a server cron job (WordPress cron only fires on site visits):

```bash
* * * * * wp action-scheduler run --hooks=psf_scheduled_full,psf_scheduled_delta,psf_process_batch,psf_maintenance --path=/path/to/wordpress
```

## Development

A Docker test site (WordPress, WooCommerce, FTP and SFTP servers) and a smoke test are in [docker/](docker/README.md) and [tests/](tests/smoke-test.php).

## License

GPL-2.0-or-later.
