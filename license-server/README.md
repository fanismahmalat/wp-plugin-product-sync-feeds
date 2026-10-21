# License server

A small service that checks Product Sync Feeds license keys and delivers plugin updates. It's Node 24 with no npm dependencies, stores data in SQLite and runs in Docker.

## How it fits together

```
WordPress site                         license server                     releases
──────────────                         ──────────────                     ────────
License screen ── POST /v1/activate ──▶ key valid? site slot free?
update check   ── POST /v1/check ─────▶ latest version + signed link ◀── GitHub Releases
"Update now"   ── GET /v1/download ───▶ zip (link valid 1 hour)           or RELEASES_DIR
```

- Every site sees that a new version exists. Only sites with an active license get a download link; the others see "Automatic update is unavailable" with a prompt to enter a key.
- Keys are stored hashed (SHA-256); the plain key is shown once, at creation.
- `max_sites` counts production sites only. Local and staging installs (`localhost`, `*.local`, `*.test`, `staging.*`, …) are free.
- Download links are signed (HMAC) and expire. The plugin fetches a fresh link right before downloading, so the hours WordPress caches update data do not matter.
- Revoking or expiring a license stops downloads at once, including links already handed out. The plugin keeps working on the site; only updates stop.

## Run

Settings live in `.env` (git-ignored). Start from the example and fill in at least `SIGNING_SECRET`, `ADMIN_TOKEN` and `PUBLIC_URL`:

```bash
cp .env.example .env
docker compose up -d        # http://localhost:8090
```

Without a `.env` the compose file uses local-only dev values (dev secrets, releases from `./releases`), which is what the Docker test sites expect. Once `.env` exists its values are used as they are, so empty secrets stop the server rather than falling back to the dev ones. `npm start` reads the same `.env`.

Or build the image and pass the variables yourself:

```bash
docker build -t psf-license-server .
docker run -d --name psf-license -p 8080:8080 -v psf-license-data:/data \
  -e SIGNING_SECRET="$(openssl rand -hex 32)" \
  -e ADMIN_TOKEN="$(openssl rand -hex 24)" \
  -e PUBLIC_URL=https://licenses.yourdomain.com \
  psf-license-server
```

Put it behind your reverse proxy with HTTPS (set `TRUST_PROXY=1` so rate limiting sees client IPs). Then set the plugin's `Update URI` header to the same URL as `PUBLIC_URL` before you build a release.

Keep `SIGNING_SECRET` and `ADMIN_TOKEN` stable and secret; changing the secret only invalidates outstanding download links.

### Backups

Everything lives in `DATA_DIR/licenses.sqlite`. Stop the container before copying it (SQLite writes recent changes to `licenses.sqlite-wal` until it closes). When restoring, delete any `licenses.sqlite-wal` and `licenses.sqlite-shm` already in the target folder first, or SQLite will replay them over the restored file.

### Environment

| Variable | Default | |
|---|---|---|
| `SIGNING_SECRET` | required | 32+ characters, signs download links |
| `ADMIN_TOKEN` | required | Bearer token for `/admin/*` |
| `PUBLIC_URL` | `http://localhost:8080` | Base URL sites use, for download links |
| `PORT` | `8080` | |
| `DATA_DIR` | `/data` (image) | SQLite database and GitHub zip cache |
| `GITHUB_REPO` | `fanismahmalat/wp-plugin-product-sync-feeds` | Release source |
| `GITHUB_TOKEN` | | Only for a private repo or higher rate limits |
| `RELEASES_DIR` | | Serve releases from a folder instead of GitHub |
| `ZIP_ASSET` | `product-sync-feeds.zip` | Asset / file name of the zip |
| `RELEASE_CACHE_SECONDS` | `300` | How long the latest release is cached |
| `DOWNLOAD_TTL_SECONDS` | `3600` | Download link lifetime |
| `RATE_LIMIT_PER_MINUTE` | `120` | Per IP, public endpoints |
| `TRUST_PROXY` | off | Use `X-Forwarded-For` |
| `PLUGIN_HOMEPAGE` | GitHub repo URL | Shown in the plugin details popup |

## Releases

**GitHub (default).** Push a tag like `v1.1.0` and the repo's Release workflow builds `product-sync-feeds.zip` and `release.json` and attaches them to a GitHub release. The server picks up the latest release within `RELEASE_CACHE_SECONDS`.

**Folder.** Set `RELEASES_DIR`, run `bin/build-zip.sh <dir>` (from the repo root) and the server serves what is in that folder.

## Managing licenses

CLI inside the container:

```bash
docker exec psf-license node src/cli.js create --label "Acme Ltd" --sites 3 --expires 2027-12-31
docker exec psf-license node src/cli.js list
docker exec psf-license node src/cli.js show 4
docker exec psf-license node src/cli.js set 4 --sites 5 --expires none
docker exec psf-license node src/cli.js revoke 4        # or disable / enable
docker exec psf-license node src/cli.js unlink 4 shop.example.com
```

Or the admin API (`Authorization: Bearer $ADMIN_TOKEN`):

| | |
|---|---|
| `GET /admin/licenses` | list |
| `POST /admin/licenses` `{label, max_sites, expires_at, notes}` | create, response includes `key` once |
| `GET /admin/licenses/:id` | details and activated sites |
| `PATCH /admin/licenses/:id` `{label, status, max_sites, expires_at, notes}` | `status`: active, revoked, disabled; `max_sites` 0 = unlimited |
| `DELETE /admin/licenses/:id` | delete |
| `DELETE /admin/licenses/:id/activations/:aid` | free a site slot |

## Plugin API

| | |
|---|---|
| `POST /v1/activate` `{license_key, site_url}` | `{success, license}` |
| `POST /v1/deactivate` `{license_key, site_url}` | frees the slot |
| `POST /v1/check` `{license_key?, site_url, plugin_version}` | `{license, update}`; `update.package` is a signed link or null |
| `GET /v1/info` | latest release details |
| `GET /v1/download?token=…` | the zip |
| `GET /healthz` | |

`license.status` is one of `active`, `missing`, `invalid`, `expired`, `revoked`, `disabled`, `site_inactive`, `limit`, with a human-readable `message`.

## Development

```bash
npm test
cp .env.example .env    # then set SIGNING_SECRET, ADMIN_TOKEN, RELEASES_DIR=./releases
npm start
```
