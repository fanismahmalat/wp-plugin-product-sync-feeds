// Where releases come from: a local directory or GitHub Releases.
//
// Both sources expose the same two files per release:
//   release.json             {version, requires, requires_php, tested, released_at, changelog}
//   product-sync-feeds.zip   the installable plugin (folder product-sync-feeds/ inside)
import { createReadStream, createWriteStream, existsSync, mkdirSync, readFileSync, renameSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

/** Local directory source: drop release.json and the zip into RELEASES_DIR. */
export class DirSource {
  constructor(config) {
    this.dir = config.releasesDir;
    this.zipName = config.zipAsset;
  }

  async latest() {
    const file = join(this.dir, 'release.json');
    if (!existsSync(file)) return null;
    const meta = JSON.parse(readFileSync(file, 'utf8'));
    const zip = join(this.dir, meta.zip || this.zipName);
    if (!existsSync(zip)) return null;
    return { ...meta, size: statSync(zip).size, _zip: zip };
  }

  async open(release) {
    return createReadStream(release._zip);
  }
}

/** GitHub Releases source: the latest non-draft, non-prerelease release. */
export class GithubSource {
  constructor(config) {
    this.config = config;
    this.cacheDir = join(config.dataDir, 'cache');
  }

  headers(accept = 'application/vnd.github+json') {
    const h = { Accept: accept, 'User-Agent': 'psf-license-server', 'X-GitHub-Api-Version': '2022-11-28' };
    if (this.config.githubToken) h.Authorization = `Bearer ${this.config.githubToken}`;
    return h;
  }

  async asset(assetObj) {
    // The API asset URL works for private repos too (with a token).
    const res = await fetch(assetObj.url, { headers: this.headers('application/octet-stream'), redirect: 'follow' });
    if (!res.ok) throw new Error(`GitHub asset ${assetObj.name}: HTTP ${res.status}`);
    return res;
  }

  async latest() {
    const { githubApiUrl, githubRepo, zipAsset } = this.config;
    const res = await fetch(`${githubApiUrl}/repos/${githubRepo}/releases/latest`, { headers: this.headers() });
    if (res.status === 404) return null;
    if (!res.ok) throw new Error(`GitHub releases: HTTP ${res.status}`);
    const rel = await res.json();
    const zip = rel.assets.find((a) => a.name === zipAsset);
    const metaAsset = rel.assets.find((a) => a.name === 'release.json');
    if (!zip) return null;
    const meta = metaAsset ? await (await this.asset(metaAsset)).json() : {};
    return {
      version: meta.version || String(rel.tag_name).replace(/^v/, ''),
      requires: meta.requires || '',
      requires_php: meta.requires_php || '',
      tested: meta.tested || '',
      released_at: meta.released_at || rel.published_at,
      changelog: meta.changelog || rel.body || '',
      size: zip.size,
      _asset: zip,
    };
  }

  /** Stream the zip, caching it on disk so GitHub is hit once per version. */
  async open(release) {
    mkdirSync(this.cacheDir, { recursive: true });
    const cached = join(this.cacheDir, `${release.version.replace(/[^\w.-]/g, '_')}.zip`);
    if (!existsSync(cached)) {
      const res = await this.asset(release._asset);
      const tmp = `${cached}.part`;
      await pipeline(Readable.fromWeb(res.body), createWriteStream(tmp));
      renameSync(tmp, cached);
    }
    return createReadStream(cached);
  }
}

/** Caches latest() so update checks from many sites do not hit the source each time. */
export class ReleaseProvider {
  constructor(source, cacheSeconds) {
    this.source = source;
    this.cacheMs = cacheSeconds * 1000;
    this.cached = null;
    this.cachedAt = 0;
  }

  async latest() {
    if (this.cached !== null && Date.now() - this.cachedAt < this.cacheMs) return this.cached;
    try {
      this.cached = await this.source.latest();
      this.cachedAt = Date.now();
    } catch (err) {
      // Keep serving the last known release when the source is down.
      if (this.cached === null) throw err;
      console.error(JSON.stringify({ level: 'warn', msg: 'release source failed, using cache', error: err.message }));
    }
    return this.cached;
  }

  open(release) {
    return this.source.open(release);
  }
}

export function makeProvider(config) {
  const source = config.releaseSource === 'dir' ? new DirSource(config) : new GithubSource(config);
  return new ReleaseProvider(source, config.releaseCacheSeconds);
}
