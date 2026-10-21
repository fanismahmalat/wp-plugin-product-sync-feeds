# Local test site

WordPress + WooCommerce with this plugin mounted live from the repo, plus FTP and SFTP servers to upload to.

```bash
cd docker
docker compose up -d
```

First start only (empty database):

```bash
docker compose exec cli wp core install --url=http://localhost:8089 --title=PSFTest --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
docker compose exec cli wp plugin install woocommerce --activate
docker compose exec cli wp plugin activate product-sync-feeds
```

| What | Where | Login |
|---|---|---|
| WordPress admin | http://localhost:8089/wp-admin | `admin` / `admin` |
| Feeds screen | WooCommerce > Product Sync Feeds | |
| FTP upload target | host `ftp`, port 21, directory e.g. `/feeds` | `feeder` / `secret` |
| SFTP upload target | host `sftp`, port 22, directory `/upload/feeds` | `feeder` / `secret` |

The `cron` container runs the plugin queue every 30 seconds. It stands in for the server cron job, because Action Scheduler's loopback requests cannot reach `localhost:8089` from inside the container. Scheduled and manual runs therefore start within about 30 seconds.

See uploaded files:

```bash
docker compose exec ftp ls -la /home/feeder/feeds
docker compose exec sftp ls -la /home/feeder/upload/feeds
```

Smoke test (creates and removes its own products and feeds):

```bash
docker compose exec -u www-data wp php -r 'require "/var/www/html/wp-load.php"; define("PSF_TEST_FTP","ftp|feeder|secret|/feeds"); define("PSF_TEST_SFTP","sftp|feeder|secret|/upload/feeds"); require WP_PLUGIN_DIR."/product-sync-feeds/tests/smoke-test.php";'
```

Run it in the `wp` container: the `cli` image's cURL has no SFTP support.

Remove everything, including the database:

```bash
docker compose down -v
```
