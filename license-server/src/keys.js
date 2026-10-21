// License keys, site normalization and signed download tokens.
import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';

// Crockford-style alphabet without 0/O/1/I/L/U so keys survive being read aloud.
const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

/** New key like PSF-7KQ2-M9XA-3TRW-H8DP (16 random symbols, ~78 bits). */
export function generateKey() {
  const bytes = randomBytes(16);
  const chars = Array.from(bytes, (b) => ALPHABET[b % ALPHABET.length]).join('');
  return `PSF-${chars.match(/.{4}/g).join('-')}`;
}

/** Canonical form: uppercase, no whitespace. */
export function normalizeKey(key) {
  return String(key || '').toUpperCase().replace(/\s+/g, '');
}

/** Keys are stored hashed; the server never keeps the plain key. */
export function hashKey(key) {
  return createHash('sha256').update(normalizeKey(key)).digest('hex');
}

/** Last block, shown in listings to tell keys apart. */
export function keyHint(key) {
  return normalizeKey(key).slice(-4);
}

/**
 * Site identity: host (lowercase, without www.) plus path, no scheme, no trailing slash.
 * https://www.Shop.com/en/ and http://shop.com/en are the same site.
 */
export function normalizeSite(url) {
  const raw = String(url || '').trim();
  if (!raw) return '';
  let parsed;
  try {
    parsed = new URL(/^[a-z]+:\/\//i.test(raw) ? raw : `https://${raw}`);
  } catch {
    return '';
  }
  const host = parsed.hostname.toLowerCase().replace(/^www\./, '');
  const port = parsed.port ? `:${parsed.port}` : '';
  const path = parsed.pathname.replace(/\/+$/, '');
  return `${host}${port}${path}`;
}

/** Local and staging installs do not use up a site slot. */
export function isDevSite(site) {
  const host = site.split('/')[0].replace(/:\d+$/, '');
  return (
    host === 'localhost' ||
    /^127\./.test(host) ||
    host === '[::1]' ||
    /\.(local|test|localhost|invalid|example)$/.test(host) ||
    /^(staging|stage|dev|test)\./.test(host)
  );
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

/** Token for one download: license, site and version, valid until `exp` (unix seconds). */
export function signDownload(secret, payload) {
  const body = b64url(JSON.stringify(payload));
  const sig = createHmac('sha256', secret).update(body).digest('base64url');
  return `${body}.${sig}`;
}

/** Payload of a valid, unexpired token, or null. */
export function verifyDownload(secret, token, now = Math.floor(Date.now() / 1000)) {
  const [body, sig] = String(token || '').split('.');
  if (!body || !sig) return null;
  const expected = createHmac('sha256', secret).update(body).digest();
  const given = Buffer.from(sig, 'base64url');
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) return null;
  let payload;
  try {
    payload = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
  } catch {
    return null;
  }
  if (!payload || typeof payload.e !== 'number' || payload.e < now) return null;
  return payload;
}

/** Constant-time string comparison for the admin token. */
export function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && timingSafeEqual(x, y);
}
