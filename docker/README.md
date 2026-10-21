# Local test site

WordPress + WooCommerce with the plugin (`product-sync-feeds/`) mounted live from the repo, WP-CLI and a cron runner.

| File | Starts |
|---|---|
| `docker-compose.yml` (repo root) | db, wp, cli, cron |
| `docker/compose.yml` | the same, plus FTP and SFTP servers to upload feeds to |

Commands below run from the **repo root**.

```bash
docker compose up -d                          # test site, no FTP/SFTP
docker compose -f docker/compose.yml up -d    # with FTP/SFTP
```

Both use the project name `psftest`, so they manage the same containers and data. Starting the root file while FTP/SFTP run prints an "orphan containers" warning; that is harmless (do not add `--remove-orphans` unless you want them removed).

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

The `cron` container runs the plugin queue every 30 seconds. It stands in for the server cron job, because Action Scheduler's loopback requests cannot reach `localhost:8089` from inside the container. Stop it (`docker compose stop cron`) to see how a site without cron behaves.

## Other commands

See uploaded files:

```bash
docker compose -f docker/compose.yml exec ftp ls -la /home/feeder/feeds
docker compose -f docker/compose.yml exec sftp ls -la /home/feeder/upload/feeds
```

Smoke test (creates and removes its own products and feeds; the FTP/SFTP checks need `docker/compose.yml`):

```bash
docker compose exec -u www-data wp php -r 'require "/var/www/html/wp-load.php"; define("PSF_TEST_FTP","ftp|feeder|secret|/feeds"); define("PSF_TEST_SFTP","sftp|feeder|secret|/upload/feeds"); require "/var/www/psf-tests/smoke-test.php";'
```

Run it in the `wp` container: the `cli` image's cURL has no SFTP support.

Remove everything, including the database:

```bash
docker compose -f docker/compose.yml down -v
```
