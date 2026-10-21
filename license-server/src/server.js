import { createServer } from 'node:http';
import { join } from 'node:path';
import { createApp } from './app.js';
import { loadConfig } from './config.js';
import { openDb } from './db.js';
import { makeProvider } from './releases.js';

const config = loadConfig();
const store = openDb(join(config.dataDir, 'licenses.sqlite'));
const releases = makeProvider(config);
const server = createServer(createApp({ config, store, releases }));

server.listen(config.port, () => {
  console.log(JSON.stringify({ level: 'info', msg: 'license server listening', port: config.port, releases: config.releaseSource }));
});

function shutdown() {
  server.close(() => {
    store.close();
    process.exit(0);
  });
  setTimeout(() => process.exit(0), 5000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
