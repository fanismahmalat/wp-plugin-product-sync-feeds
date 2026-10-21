import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createApp } from '../src/app.js';
import { openDb } from '../src/db.js';
import { isDevSite, normalizeSite, signDownload, verifyDownload } from '../src/keys.js';
import { makeProvider } from '../src/releases.js';

const SECRET = 'x'.repeat(40);
const ADMIN = 'admin-token-for-tests';

describe('keys', () => {
  test('normalizeSite ignores scheme, www and trailing slash', () => {
    assert.equal(normalizeSite('https://www.Shop.com/en/'), 'shop.com/en');
    assert.equal(normalizeSite('http://shop.com/en'), 'shop.com/en');
    assert.equal(normalizeSite('shop.com'), 'shop.com');
    assert.equal(normalizeSite('http://localhost:8089'), 'localhost:8089');
    assert.equal(normalizeSite(''), '');
  });

  test('dev sites are recognised', () => {
    assert.ok(isDevSite('localhost:8089'));
    assert.ok(isDevSite('shop.test'));
    assert.ok(isDevSite('staging.shop.com'));
    assert.ok(!isDevSite('shop.com'));
  });

  test('download tokens are signed and expire', () => {
    const token = signDownload(SECRET, { l: 1, s: 'a.com', v: '1.0.0', e: 2000 });
    assert.deepEqual(verifyDownload(SECRET, token, 1000), { l: 1, s: 'a.com', v: '1.0.0', e: 2000 });
    assert.equal(verifyDownload(SECRET, token, 3000), null, 'expired');
    assert.equal(verifyDownload('y'.repeat(40), token, 1000), null, 'wrong secret');
    const [body] = token.split('.');
    assert.equal(verifyDownload(SECRET, `${body}.AAAA`, 1000), null, 'tampered');
  });
});

describe('api', () => {
  let server;
  let base;
  let store;
  const dir = mkdtempSync(join(tmpdir(), 'psf-rel-'));

  before(async () => {
    writeFileSync(join(dir, 'product-sync-feeds.zip'), Buffer.from('PK-fake-zip'));
    writeFileSync(
      join(dir, 'release.json'),
      JSON.stringify({ version: '1.2.0', requires: '6.2', requires_php: '7.4', tested: '6.8', changelog: '* New stuff' })
    );
    const config = {
      publicUrl: 'http://placeholder',
      signingSecret: SECRET,
      adminToken: ADMIN,
      pluginSlug: 'product-sync-feeds',
      homepage: 'https://example.com',
      releaseSource: 'dir',
      releasesDir: dir,
      zipAsset: 'product-sync-feeds.zip',
      releaseCacheSeconds: 0,
      downloadTtlSeconds: 60,
      rateLimitPerMinute: 1000,
      trustProxy: false,
      dataDir: dir,
    };
    store = openDb(':memory:');
    const silent = { log() {}, error() {} };
    server = createServer(createApp({ config, store, releases: makeProvider(config), log: silent }));
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    base = `http://127.0.0.1:${server.address().port}`;
    config.publicUrl = base;
  });

  after(() => {
    server.close();
    store.close();
  });

  const post = (path, body, headers = {}) =>
    fetch(base + path, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(body) }).then(async (r) => ({
      status: r.status,
      body: r.status === 204 ? null : await r.json(),
    }));
  const admin = (method, path, body) =>
    fetch(base + path, {
      method,
      headers: { Authorization: `Bearer ${ADMIN}`, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    }).then(async (r) => ({ status: r.status, body: r.status === 204 ? null : await r.json() }));

  test('admin API requires the token', async () => {
    const r = await fetch(`${base}/admin/licenses`);
    assert.equal(r.status, 401);
    const wrong = await fetch(`${base}/admin/licenses`, { headers: { Authorization: 'Bearer nope' } });
    assert.equal(wrong.status, 401);
  });

  test('full license lifecycle', async () => {
    const created = await admin('POST', '/admin/licenses', { label: 'Acme', max_sites: 1 });
    assert.equal(created.status, 201);
    const key = created.body.key;
    assert.match(key, /^PSF-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/);

    // No key: update info without a package.
    let check = await post('/v1/check', { site_url: 'https://shop-one.com' });
    assert.equal(check.body.license.status, 'missing');
    assert.equal(check.body.update.version, '1.2.0');
    assert.equal(check.body.update.package, null);

    // Wrong key.
    check = await post('/v1/check', { site_url: 'https://shop-one.com', license_key: 'PSF-AAAA-AAAA-AAAA-AAAA' });
    assert.equal(check.body.license.status, 'invalid');

    // Valid key but not activated on this site yet.
    check = await post('/v1/check', { site_url: 'https://shop-one.com', license_key: key });
    assert.equal(check.body.license.status, 'site_inactive');
    assert.equal(check.body.update.package, null);

    // Activate (lowercase and spaces in the key are fine).
    let act = await post('/v1/activate', { site_url: 'https://www.shop-one.com/', license_key: ` ${key.toLowerCase()} ` });
    assert.equal(act.body.success, true);
    assert.equal(act.body.license.sites_used, 1);

    // Second real site is over the limit, a local dev site is free.
    act = await post('/v1/activate', { site_url: 'https://shop-two.com', license_key: key });
    assert.equal(act.body.success, false);
    assert.equal(act.body.license.status, 'limit');
    act = await post('/v1/activate', { site_url: 'http://localhost:8089', license_key: key });
    assert.equal(act.body.success, true);
    assert.equal(act.body.license.sites_used, 1);

    // Activated site gets a working download link.
    check = await post('/v1/check', { site_url: 'shop-one.com', license_key: key, plugin_version: '1.0.0' });
    assert.equal(check.body.license.status, 'active');
    assert.match(check.body.update.package, /\/v1\/download\?token=/);
    const zip = await fetch(check.body.update.package);
    assert.equal(zip.status, 200);
    assert.equal(zip.headers.get('content-type'), 'application/zip');
    assert.equal(await zip.text(), 'PK-fake-zip');

    // Revoking stops downloads, even with a link issued earlier.
    const id = created.body.id;
    await admin('PATCH', `/admin/licenses/${id}`, { status: 'revoked' });
    assert.equal((await fetch(check.body.update.package)).status, 403);
    check = await post('/v1/check', { site_url: 'shop-one.com', license_key: key });
    assert.equal(check.body.license.status, 'revoked');
    assert.equal(check.body.update.package, null);

    // Re-enable, then expire.
    await admin('PATCH', `/admin/licenses/${id}`, { status: 'active', expires_at: '2020-01-01' });
    check = await post('/v1/check', { site_url: 'shop-one.com', license_key: key });
    assert.equal(check.body.license.status, 'expired');
    await admin('PATCH', `/admin/licenses/${id}`, { expires_at: null });

    // Deactivating frees the slot for another site.
    const de = await post('/v1/deactivate', { site_url: 'https://shop-one.com', license_key: key });
    assert.equal(de.body.success, true);
    act = await post('/v1/activate', { site_url: 'https://shop-two.com', license_key: key });
    assert.equal(act.body.success, true);

    const detail = await admin('GET', `/admin/licenses/${id}`);
    assert.deepEqual(detail.body.activations.map((a) => a.site).sort(), ['localhost:8089', 'shop-two.com']);
    assert.equal(detail.body.key, undefined, 'key is never shown again');
  });

  test('tampered and expired download links are refused', async () => {
    assert.equal((await fetch(`${base}/v1/download?token=abc.def`)).status, 403);
    const old = signDownload(SECRET, { l: 1, s: 'shop-two.com', v: '1.2.0', e: 1 });
    assert.equal((await fetch(`${base}/v1/download?token=${old}`)).status, 403);
  });

  test('info and validation', async () => {
    const info = await fetch(`${base}/v1/info`).then((r) => r.json());
    assert.equal(info.version, '1.2.0');
    assert.equal(info.changelog, '* New stuff');
    const bad = await post('/v1/check', {});
    assert.equal(bad.status, 400);
    const badJson = await fetch(`${base}/v1/check`, { method: 'POST', body: '{nope' });
    assert.equal(badJson.status, 400);
    const badAdmin = await admin('POST', '/admin/licenses', { max_sites: -1 });
    assert.equal(badAdmin.status, 400);
  });
});
