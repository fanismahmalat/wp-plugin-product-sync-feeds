# Local test site

WordPress + WooCommerce with the plugin (`product-sync-feeds/`) mounted live from the repo, WP-CLI and a cron runner, all started by `docker-compose.yml` in the repo root.

Commands below run from the **repo root**.

```bash
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

The database, WordPress files and uploads live in named volumes (`psftest_dbdata`, `psftest_wpdata`), so they survive removing or recreating the containers.

## Scheduled runs

- **Site visits** run due tasks, like on a real host: `docker/apache-loopback.conf` lets WordPress reach itself at `http://localhost:8089`.
- **The `cron` container** runs due tasks every 30 seconds, like a server cron job, so schedules stay on time when nobody visits. Stop it (`docker compose stop cron`) to see how a site without cron behaves.

## Smoke test

Creates and removes its own products and feeds:

```bash
docker compose exec -u www-data wp php -r 'require "/var/www/html/wp-load.php"; require "/var/www/psf-tests/smoke-test.php";'
```

To include upload checks, define `PSF_TEST_FTP` and/or `PSF_TEST_SFTP` as `"host|user|password|directory"` for a server you control before the `require`. Run it in the `wp` container: the `cli` image's cURL has no SFTP support.

## Remove everything

Including the database and WordPress files:

```bash
docker compose down -v
```
