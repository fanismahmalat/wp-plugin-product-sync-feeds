// Configuration from environment variables. See README.md for the full list.

function required(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing required environment variable ${name}`);
  }
  return value;
}

export function loadConfig(env = process.env) {
  const releaseSource = env.RELEASES_DIR ? 'dir' : 'github';
  const config = {
    port: Number(env.PORT || 8080),
    dataDir: env.DATA_DIR || './data',
    publicUrl: (env.PUBLIC_URL || `http://localhost:${env.PORT || 8080}`).replace(/\/+$/, ''),
    signingSecret: env.SIGNING_SECRET || required('SIGNING_SECRET'),
    adminToken: env.ADMIN_TOKEN || required('ADMIN_TOKEN'),
    pluginSlug: env.PLUGIN_SLUG || 'product-sync-feeds',
    homepage: env.PLUGIN_HOMEPAGE || 'https://github.com/fanismahmalat/wp-plugin-product-sync-feeds',
    releaseSource,
    releasesDir: env.RELEASES_DIR || '',
    githubRepo: env.GITHUB_REPO || 'fanismahmalat/wp-plugin-product-sync-feeds',
    githubToken: env.GITHUB_TOKEN || '',
    githubApiUrl: (env.GITHUB_API_URL || 'https://api.github.com').replace(/\/+$/, ''),
    zipAsset: env.ZIP_ASSET || 'product-sync-feeds.zip',
    releaseCacheSeconds: Number(env.RELEASE_CACHE_SECONDS || 300),
    downloadTtlSeconds: Number(env.DOWNLOAD_TTL_SECONDS || 3600),
    rateLimitPerMinute: Number(env.RATE_LIMIT_PER_MINUTE || 120),
    trustProxy: env.TRUST_PROXY === '1' || env.TRUST_PROXY === 'true',
  };
  if (config.signingSecret.length < 32) {
    throw new Error('SIGNING_SECRET must be at least 32 characters');
  }
  return config;
}
