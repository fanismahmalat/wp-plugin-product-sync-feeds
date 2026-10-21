// Manage licenses from the command line (inside the container):
//   node src/cli.js create --label "Acme Ltd" --sites 3 --expires 2027-12-31
//   node src/cli.js list
//   node src/cli.js show <id>
//   node src/cli.js set <id> [--label ..] [--sites N] [--expires YYYY-MM-DD|none] [--notes ..]
//   node src/cli.js revoke <id> | enable <id> | disable <id>
//   node src/cli.js unlink <id> <site>        free a site slot
//   node src/cli.js delete <id>
import { join } from 'node:path';
import { parseArgs } from 'node:util';
import { licenseState, openDb } from './db.js';
import { normalizeSite } from './keys.js';

const { positionals, values } = parseArgs({
  allowPositionals: true,
  options: {
    label: { type: 'string' },
    sites: { type: 'string' },
    expires: { type: 'string' },
    notes: { type: 'string' },
  },
});

const store = openDb(join(process.env.DATA_DIR || './data', 'licenses.sqlite'));
const [command, id, extra] = positionals;

function row(l) {
  return {
    id: l.id,
    key: `PSF-…-${l.key_hint}`,
    label: l.label,
    state: licenseState(l),
    sites: `${store.sitesUsed(l.id)}/${l.max_sites || '∞'}`,
    expires: l.expires_at || 'never',
  };
}

function need(license) {
  if (!license) {
    console.error(`License ${id} not found.`);
    process.exit(1);
  }
  return license;
}

function fields() {
  const out = {};
  if (values.label !== undefined) out.label = values.label;
  if (values.notes !== undefined) out.notes = values.notes;
  if (values.sites !== undefined) out.maxSites = Number(values.sites);
  if (values.expires !== undefined) out.expiresAt = values.expires === 'none' ? '' : values.expires;
  return out;
}

switch (command) {
  case 'create': {
    const f = fields();
    const created = store.createLicense({ label: f.label, maxSites: f.maxSites ?? 1, expiresAt: f.expiresAt || null, notes: f.notes });
    console.log(`License ${created.id} created. Give this key to the customer (it is not stored and cannot be shown again):\n\n  ${created.key}\n`);
    console.table([row(created)]);
    break;
  }
  case 'list':
    console.table(store.listLicenses().map(row));
    break;
  case 'show': {
    const l = need(store.getLicense(id));
    console.table([row(l)]);
    console.table(store.activations(l.id).map((a) => ({ id: a.id, site: a.site, dev: !!a.is_dev, plugin: a.plugin_version, wp: a.wp_version, last_seen: a.last_seen_at })));
    break;
  }
  case 'set':
    need(store.getLicense(id));
    console.table([row(store.updateLicense(id, fields()))]);
    break;
  case 'revoke':
  case 'disable':
  case 'enable':
    need(store.getLicense(id));
    console.table([row(store.updateLicense(id, { status: command === 'enable' ? 'active' : command === 'revoke' ? 'revoked' : 'disabled' }))]);
    break;
  case 'unlink':
    need(store.getLicense(id));
    console.log(store.deactivate(id, normalizeSite(extra)) ? 'Site removed.' : 'That site is not activated on this license.');
    break;
  case 'delete':
    need(store.getLicense(id));
    store.deleteLicense(id);
    console.log('Deleted.');
    break;
  default:
    console.log('Commands: create, list, show <id>, set <id>, revoke <id>, disable <id>, enable <id>, unlink <id> <site>, delete <id>');
    console.log('Options: --label "Name" --sites 3 --expires 2027-12-31|none --notes "..."');
}
store.close();
