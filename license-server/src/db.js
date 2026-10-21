// SQLite storage (node:sqlite, no dependencies).
import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { generateKey, hashKey, isDevSite, keyHint } from './keys.js';

const SCHEMA = `
CREATE TABLE IF NOT EXISTS licenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key_hash TEXT NOT NULL UNIQUE,
  key_hint TEXT NOT NULL,
  label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'active',
  max_sites INTEGER NOT NULL DEFAULT 1,
  expires_at TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS activations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
  site TEXT NOT NULL,
  is_dev INTEGER NOT NULL DEFAULT 0,
  plugin_version TEXT NOT NULL DEFAULT '',
  wp_version TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  UNIQUE (license_id, site)
);
`;

const now = () => new Date().toISOString();

export function openDb(file) {
  if (file !== ':memory:') mkdirSync(dirname(file), { recursive: true });
  const db = new DatabaseSync(file);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
  db.exec(SCHEMA);
  return new Store(db);
}

export class Store {
  constructor(db) {
    this.db = db;
  }

  close() {
    this.db.close();
  }

  /** Create a license. Returns the row plus the plain key (only time it exists). */
  createLicense({ label = '', maxSites = 1, expiresAt = null, notes = '' } = {}) {
    const key = generateKey();
    const info = this.db
      .prepare('INSERT INTO licenses (key_hash, key_hint, label, max_sites, expires_at, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      .run(hashKey(key), keyHint(key), String(label), Number(maxSites), expiresAt || null, String(notes), now());
    return { ...this.getLicense(Number(info.lastInsertRowid)), key };
  }

  getLicense(id) {
    return this.db.prepare('SELECT * FROM licenses WHERE id = ?').get(Number(id)) || null;
  }

  findByKey(key) {
    return this.db.prepare('SELECT * FROM licenses WHERE key_hash = ?').get(hashKey(key)) || null;
  }

  listLicenses() {
    return this.db
      .prepare(
        `SELECT l.*, (SELECT COUNT(*) FROM activations a WHERE a.license_id = l.id AND a.is_dev = 0) AS sites_used
         FROM licenses l ORDER BY l.id DESC`
      )
      .all();
  }

  updateLicense(id, fields) {
    const allowed = { label: 'label', status: 'status', maxSites: 'max_sites', expiresAt: 'expires_at', notes: 'notes' };
    const sets = [];
    const values = [];
    for (const [k, col] of Object.entries(allowed)) {
      if (fields[k] !== undefined) {
        sets.push(`${col} = ?`);
        values.push(fields[k] === '' && k === 'expiresAt' ? null : fields[k]);
      }
    }
    if (sets.length) {
      this.db.prepare(`UPDATE licenses SET ${sets.join(', ')} WHERE id = ?`).run(...values, Number(id));
    }
    return this.getLicense(id);
  }

  deleteLicense(id) {
    this.db.prepare('DELETE FROM licenses WHERE id = ?').run(Number(id));
  }

  activations(licenseId) {
    return this.db.prepare('SELECT * FROM activations WHERE license_id = ? ORDER BY id').all(Number(licenseId));
  }

  sitesUsed(licenseId) {
    return this.db.prepare('SELECT COUNT(*) AS n FROM activations WHERE license_id = ? AND is_dev = 0').get(Number(licenseId)).n;
  }

  findActivation(licenseId, site) {
    return this.db.prepare('SELECT * FROM activations WHERE license_id = ? AND site = ?').get(Number(licenseId), site) || null;
  }

  /** Activate a site; idempotent. Returns { ok, activation, reason }. */
  activate(license, site, meta = {}) {
    const existing = this.findActivation(license.id, site);
    if (existing) {
      this.touch(existing.id, meta);
      return { ok: true, activation: this.findActivation(license.id, site) };
    }
    const dev = isDevSite(site);
    if (!dev && license.max_sites > 0 && this.sitesUsed(license.id) >= license.max_sites) {
      return { ok: false, reason: 'limit' };
    }
    const t = now();
    this.db
      .prepare('INSERT INTO activations (license_id, site, is_dev, plugin_version, wp_version, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      .run(license.id, site, dev ? 1 : 0, String(meta.pluginVersion || ''), String(meta.wpVersion || ''), t, t);
    return { ok: true, activation: this.findActivation(license.id, site) };
  }

  touch(activationId, meta = {}) {
    this.db
      .prepare(
        `UPDATE activations SET last_seen_at = ?,
           plugin_version = COALESCE(NULLIF(?, ''), plugin_version),
           wp_version = COALESCE(NULLIF(?, ''), wp_version)
         WHERE id = ?`
      )
      .run(now(), String(meta.pluginVersion || ''), String(meta.wpVersion || ''), Number(activationId));
  }

  deactivate(licenseId, site) {
    return this.db.prepare('DELETE FROM activations WHERE license_id = ? AND site = ?').run(Number(licenseId), site).changes > 0;
  }

  deleteActivation(licenseId, activationId) {
    return this.db.prepare('DELETE FROM activations WHERE license_id = ? AND id = ?').run(Number(licenseId), Number(activationId)).changes > 0;
  }
}

/** Effective status of a license right now: active, expired, revoked or disabled. */
export function licenseState(license, at = new Date()) {
  if (!license) return 'invalid';
  if (license.status !== 'active') return license.status;
  if (license.expires_at) {
    // A plain date means "valid through that whole day" (UTC).
    const end = /^\d{4}-\d{2}-\d{2}$/.test(license.expires_at) ? `${license.expires_at}T23:59:59Z` : license.expires_at;
    if (new Date(end) < at) return 'expired';
  }
  return 'active';
}
