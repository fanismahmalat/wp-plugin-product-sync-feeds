// HTTP API.
//
// Public (called by the plugin):
//   POST /v1/activate     {license_key, site_url, plugin_version?, wp_version?}
//   POST /v1/deactivate   {license_key, site_url}
//   POST /v1/check        {license_key?, site_url, plugin_version?, wp_version?}
//   GET  /v1/info         latest release details (no key needed)
//   GET  /v1/download?token=...   the zip, for a signed link from /v1/check
//   GET  /healthz
//
// Admin (Authorization: Bearer ADMIN_TOKEN):
//   GET    /admin/licenses
//   POST   /admin/licenses                       {label, max_sites, expires_at, notes} -> includes the key once
//   GET    /admin/licenses/:id                   with activations
//   PATCH  /admin/licenses/:id                   {label, status, max_sites, expires_at, notes}
//   DELETE /admin/licenses/:id
//   DELETE /admin/licenses/:id/activations/:aid
import { licenseState } from './db.js';
import { normalizeSite, safeEqual, signDownload, verifyDownload } from './keys.js';

const MAX_BODY = 64 * 1024;
const STATUSES = ['active', 'revoked', 'disabled'];

const MESSAGES = {
  missing: 'No license key entered. Enter your key to receive updates.',
  invalid: 'This license key is not valid.',
  expired: 'This license has expired. Renew it to receive updates.',
  revoked: 'This license has been revoked.',
  disabled: 'This license is disabled.',
  limit: 'This license is already active on the maximum number of sites. Deactivate it on another site first.',
  site_inactive: 'The license is not activated on this site.',
  active: 'License active.',
};

class HttpError extends Error {
  constructor(status, code, message) {
    super(message || code);
    this.status = status;
    this.code = code;
  }
}

function send(res, status, body, headers = {}) {
  const json = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
  res.end(json);
}

async function readJson(req) {
  if (req.method === 'GET' || req.method === 'HEAD' || req.method === 'DELETE') return {};
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY) throw new HttpError(413, 'too_large');
    chunks.push(chunk);
  }
  if (!size) return {};
  const raw = Buffer.concat(chunks).toString('utf8');
  const type = String(req.headers['content-type'] || '');
  if (type.includes('application/x-www-form-urlencoded')) {
    return Object.fromEntries(new URLSearchParams(raw));
  }
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    throw new HttpError(400, 'bad_json', 'Request body is not valid JSON.');
  }
}

/** Fixed-window per-IP limiter for the public endpoints. */
function makeLimiter(perMinute) {
  const hits = new Map();
  return (ip) => {
    const minute = Math.floor(Date.now() / 60000);
    const key = `${ip}:${minute}`;
    const n = (hits.get(key) || 0) + 1;
    hits.set(key, n);
    if (hits.size > 10000) {
      for (const k of hits.keys()) if (!k.endsWith(`:${minute}`)) hits.delete(k);
    }
    return n <= perMinute;
  };
}

function licenseSummary(store, license) {
  const state = licenseState(license);
  return {
    status: state,
    message: MESSAGES[state] || MESSAGES.invalid,
    expires_at: license.expires_at,
    max_sites: license.max_sites,
    sites_used: store.sitesUsed(license.id),
  };
}

function releaseInfo(config, release) {
  if (!release) return null;
  return {
    slug: config.pluginSlug,
    version: release.version,
    requires: release.requires || '',
    requires_php: release.requires_php || '',
    tested: release.tested || '',
    released_at: release.released_at || '',
    changelog: release.changelog || '',
    homepage: config.homepage,
    size: release.size || 0,
  };
}

function adminLicense(store, license) {
  return {
    id: license.id,
    key_hint: license.key_hint,
    label: license.label,
    status: license.status,
    state: licenseState(license),
    max_sites: license.max_sites,
    sites_used: store.sitesUsed(license.id),
    expires_at: license.expires_at,
    notes: license.notes,
    created_at: license.created_at,
  };
}

function validateLicenseFields(body, creating) {
  const out = {};
  if (body.label !== undefined) out.label = String(body.label).slice(0, 200);
  if (body.notes !== undefined) out.notes = String(body.notes).slice(0, 2000);
  if (body.max_sites !== undefined) {
    const n = Number(body.max_sites);
    if (!Number.isInteger(n) || n < 0) throw new HttpError(400, 'bad_max_sites', 'max_sites must be a whole number, 0 = unlimited.');
    out.maxSites = n;
  } else if (creating) {
    out.maxSites = 1;
  }
  if (body.expires_at !== undefined && body.expires_at !== null && body.expires_at !== '') {
    if (Number.isNaN(Date.parse(body.expires_at))) throw new HttpError(400, 'bad_expires_at', 'expires_at must be a date (YYYY-MM-DD) or ISO timestamp.');
    out.expiresAt = String(body.expires_at);
  } else if (body.expires_at === null || body.expires_at === '') {
    out.expiresAt = '';
  }
  if (body.status !== undefined) {
    if (!STATUSES.includes(body.status)) throw new HttpError(400, 'bad_status', `status must be one of ${STATUSES.join(', ')}.`);
    out.status = body.status;
  }
  return out;
}

export function createApp({ config, store, releases, log = console }) {
  const limit = makeLimiter(config.rateLimitPerMinute);

  function clientIp(req) {
    if (config.trustProxy && req.headers['x-forwarded-for']) {
      return String(req.headers['x-forwarded-for']).split(',')[0].trim();
    }
    return req.socket.remoteAddress || '';
  }

  /** License + normalized site from a request body, or an error response object. */
  function resolve(body) {
    const site = normalizeSite(body.site_url);
    if (!site) throw new HttpError(400, 'bad_site', 'site_url is required.');
    const key = String(body.license_key || '').trim();
    if (!key) return { site, license: null, state: 'missing' };
    const license = store.findByKey(key);
    if (!license) return { site, license: null, state: 'invalid' };
    return { site, license, state: licenseState(license) };
  }

  const routes = {
    'GET /healthz': async () => [200, { ok: true }],

    'GET /v1/info': async () => {
      const release = await releases.latest();
      if (!release) throw new HttpError(404, 'no_release', 'No release published yet.');
      return [200, releaseInfo(config, release)];
    },

    'POST /v1/activate': async (req, body) => {
      const { site, license, state } = resolve(body);
      if (!license || state !== 'active') {
        return [200, { success: false, license: { status: state, message: MESSAGES[state] } }];
      }
      const result = store.activate(license, site, { pluginVersion: body.plugin_version, wpVersion: body.wp_version });
      if (!result.ok) {
        return [200, { success: false, license: { ...licenseSummary(store, license), status: 'limit', message: MESSAGES.limit } }];
      }
      return [200, { success: true, site, license: licenseSummary(store, license) }];
    },

    'POST /v1/deactivate': async (req, body) => {
      const { site, license } = resolve(body);
      if (!license) return [200, { success: false, license: { status: 'invalid', message: MESSAGES.invalid } }];
      const removed = store.deactivate(license.id, site);
      return [200, { success: removed, site, license: licenseSummary(store, license) }];
    },

    'POST /v1/check': async (req, body) => {
      const { site, license, state } = resolve(body);
      const release = await releases.latest();
      let licenseOut;
      let pkg = null;

      if (!license || state !== 'active') {
        licenseOut = { status: state, message: MESSAGES[state] || MESSAGES.invalid };
        if (license) licenseOut = { ...licenseSummary(store, license), ...licenseOut };
      } else {
        const activation = store.findActivation(license.id, site);
        if (!activation) {
          licenseOut = { ...licenseSummary(store, license), status: 'site_inactive', message: MESSAGES.site_inactive };
        } else {
          store.touch(activation.id, { pluginVersion: body.plugin_version, wpVersion: body.wp_version });
          licenseOut = licenseSummary(store, license);
          if (release) {
            const token = signDownload(config.signingSecret, {
              l: license.id,
              s: site,
              v: release.version,
              e: Math.floor(Date.now() / 1000) + config.downloadTtlSeconds,
            });
            pkg = `${config.publicUrl}/v1/download?token=${encodeURIComponent(token)}`;
          }
        }
      }

      const update = releaseInfo(config, release);
      if (update) update.package = pkg;
      return [200, { license: licenseOut, update }];
    },

    'GET /v1/download': async (req, body, url, res) => {
      const payload = verifyDownload(config.signingSecret, url.searchParams.get('token'));
      if (!payload) throw new HttpError(403, 'bad_token', 'Download link is invalid or expired. Check for updates again.');
      const license = store.getLicense(payload.l);
      if (licenseState(license) !== 'active' || !store.findActivation(payload.l, payload.s)) {
        throw new HttpError(403, 'license_inactive', 'The license for this download is no longer active.');
      }
      const release = await releases.latest();
      if (!release || release.version !== payload.v) {
        throw new HttpError(409, 'stale_link', 'A newer version is available. Check for updates again.');
      }
      const stream = await releases.open(release);
      res.writeHead(200, {
        'Content-Type': 'application/zip',
        'Content-Disposition': `attachment; filename="${config.zipAsset}"`,
        ...(release.size ? { 'Content-Length': String(release.size) } : {}),
        'Cache-Control': 'private, no-store',
      });
      await new Promise((resolveStream, reject) => {
        stream.on('error', reject);
        res.on('close', resolveStream);
        stream.pipe(res);
      });
      return null;
    },
  };

  const adminRoutes = [
    ['GET', /^\/admin\/licenses$/, async () => [200, store.listLicenses().map((l) => adminLicense(store, l))]],
    [
      'POST',
      /^\/admin\/licenses$/,
      async (req, body) => {
        const fields = validateLicenseFields(body, true);
        const created = store.createLicense({ label: fields.label, maxSites: fields.maxSites, expiresAt: fields.expiresAt || null, notes: fields.notes });
        return [201, { ...adminLicense(store, created), key: created.key }];
      },
    ],
    [
      'GET',
      /^\/admin\/licenses\/(\d+)$/,
      async (req, body, m) => {
        const license = store.getLicense(m[1]);
        if (!license) throw new HttpError(404, 'not_found');
        return [200, { ...adminLicense(store, license), activations: store.activations(license.id) }];
      },
    ],
    [
      'PATCH',
      /^\/admin\/licenses\/(\d+)$/,
      async (req, body, m) => {
        if (!store.getLicense(m[1])) throw new HttpError(404, 'not_found');
        const updated = store.updateLicense(m[1], validateLicenseFields(body, false));
        return [200, adminLicense(store, updated)];
      },
    ],
    [
      'DELETE',
      /^\/admin\/licenses\/(\d+)$/,
      async (req, body, m) => {
        if (!store.getLicense(m[1])) throw new HttpError(404, 'not_found');
        store.deleteLicense(m[1]);
        return [204, null];
      },
    ],
    [
      'DELETE',
      /^\/admin\/licenses\/(\d+)\/activations\/(\d+)$/,
      async (req, body, m) => {
        if (!store.deleteActivation(m[1], m[2])) throw new HttpError(404, 'not_found');
        return [204, null];
      },
    ],
  ];

  return async function handle(req, res) {
    const started = Date.now();
    const url = new URL(req.url, 'http://localhost');
    const path = url.pathname.replace(/\/+$/, '') || '/';
    const ip = clientIp(req);
    let status = 500;

    try {
      let result;
      if (path.startsWith('/admin/')) {
        const auth = String(req.headers.authorization || '');
        if (!auth.startsWith('Bearer ') || !safeEqual(auth.slice(7), config.adminToken)) {
          throw new HttpError(401, 'unauthorized');
        }
        const match = adminRoutes.find(([method, re]) => method === req.method && re.test(path));
        if (!match) throw new HttpError(404, 'not_found');
        const body = await readJson(req);
        result = await match[2](req, body, path.match(match[1]));
      } else {
        const handler = routes[`${req.method} ${path}`];
        if (!handler) throw new HttpError(404, 'not_found');
        if (path.startsWith('/v1/') && !limit(ip)) throw new HttpError(429, 'rate_limited', 'Too many requests.');
        const body = await readJson(req);
        result = await handler(req, body, url, res);
      }
      if (result === null) {
        status = 200; // Streamed.
      } else if (result[0] === 204) {
        status = 204;
        res.writeHead(204).end();
      } else {
        status = result[0];
        send(res, result[0], result[1]);
      }
    } catch (err) {
      status = err instanceof HttpError ? err.status : 500;
      if (!(err instanceof HttpError)) log.error(JSON.stringify({ level: 'error', msg: err.message, stack: err.stack }));
      if (!res.headersSent) {
        send(res, status, { error: err instanceof HttpError ? err.code : 'server_error', message: err instanceof HttpError ? err.message : 'Internal error.' });
      } else {
        res.destroy();
      }
    } finally {
      if (path !== '/healthz') {
        log.log(JSON.stringify({ t: new Date().toISOString(), ip, method: req.method, path, status, ms: Date.now() - started }));
      }
    }
  };
}
