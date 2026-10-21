# Local test site

WordPress + WooCommerce with the plugin (`product-sync-feeds/`) mounted live from the repo, WP-CLI and a cron runner.

| File | Starts |
|---|---|
| `docker-compose.yml` (repo root) | db, wp, cli, cron |
| `license-server/compose.yml` | the license server, on its own (port 8090) |
| `docker/compose.update-test.yml` | a second site for testing real updates (port 8091) |

Commands below run from the **repo root**.

```bash
docker compose up -d                                 # test site
docker compose -f license-server/compose.yml up -d   # license server, when testing licenses
```

First start only (empty database):

```bash
docker compose exec cli wp core install --url=http://localhost:8089 --title=PSFTest --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
docker compose exec cli wp plugin install woocommerce --activate
docker compose exec cli wp plugin activate product-sync-feeds
docker compose exec wp mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp docker/mu-plugins/psf-dev.php wp:/var/www/html/wp-content/mu-plugins/psf-dev.php
```

`docker/mu-plugins/psf-dev.php` points the plugin at the license server on the host (`http://host.docker.internal:8090`). It also blocks plugin updates on this site: the plugin folder is mounted from the repo, and an update would delete it. Test real updates on the second site (below).

| What | Where | Login |
|---|---|---|
| WordPress admin | http://localhost:8089/wp-admin | `admin` / `admin` |
| Feeds screen | WooCommerce > Product Sync Feeds | |
| License server | http://localhost:8090 | admin token `dev-admin-token` |

The database, WordPress files and uploads live in named volumes (`psftest_dbdata`, `psftest_wpdata`), so they survive removing or recreating the containers.

Scheduled runs:

- **Site visits** run due tasks, like on a real host: `docker/apache-loopback.conf` lets WordPress reach itself at `http://localhost:8089`.
- **The `cron` container** runs due tasks every 30 seconds, like a server cron job, so schedules stay on time when nobody visits. Stop it (`docker compose stop cron`) to see how a site without cron behaves.

## Licenses and updates

Start the license server, then create a key:

```bash
docker compose -f license-server/compose.yml up -d
docker compose -f license-server/compose.yml exec license node src/cli.js create --label "Me" --sites 2
```

It serves whatever version is in `license-server/releases/` (git-ignored). Publish the current code there:

```bash
ALLOW_EXAMPLE_UPDATE_URI=1 bin/build-zip.sh license-server/releases
```

To see an update offered, build from a copy with a higher `Version` and `PSF_VERSION`.

### Second site for real updates (http://localhost:8091)

```bash
docker compose exec db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wp2"
docker compose -f docker/compose.update-test.yml up -d
docker compose -f docker/compose.update-test.yml exec cli2 sh -c 'wp core install --url=http://localhost:8091 --title=UpdTest --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email && wp plugin install woocommerce --activate && mkdir -p wp-content/mu-plugins && cp /seed/mu-plugins/psf-dev.php wp-content/mu-plugins/ && wp plugin install /seed/releases/product-sync-feeds.zip --activate'
```

Then publish a newer zip, enter a key under WooCommerce > Product Sync Feeds > License on that site, and update from Dashboard > Updates.

## Other commands

Smoke test (creates and removes its own products and feeds):

```bash
docker compose exec -u www-data wp php -r 'require "/var/www/html/wp-load.php"; require "/var/www/psf-tests/smoke-test.php";'
```

To include upload checks, define `PSF_TEST_FTP` and/or `PSF_TEST_SFTP` as `"host|user|password|directory"` for a server you control before the `require`. Run it in the `wp` container: the `cli` image's cURL has no SFTP support.

Remove everything, including databases:

```bash
docker compose -f docker/compose.update-test.yml down -v
docker compose down -v
docker compose -f license-server/compose.yml down -v
```
