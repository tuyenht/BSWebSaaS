// Which request paths are servable storage keys, and the one Content-Type each may carry.
// Kept apart from index.js so it can be tested with plain Node (a Worker module exports only handlers).

const MAX_KEY_BYTES = 900; // R2 keys max out at 1,024 bytes; anything near that is not ours

// Extension → the only Content-Type this host will ever send for it. Mirrors UploadMedia::MIME_EXT
// (plus avif for future renditions). Anything else is 404, whatever R2 holds.
const TYPES = new Map([
  ['jpg', 'image/jpeg'],
  ['jpeg', 'image/jpeg'],
  ['png', 'image/png'],
  ['webp', 'image/webp'],
  ['gif', 'image/gif'],
  ['avif', 'image/avif'],
  ['pdf', 'application/pdf'],
]);

// Decode a request path into a storage key, or null when it is not a key this host may serve.
export function publicKey(rawPath) {
  let key;
  try {
    key = decodeURIComponent(rawPath);
  } catch {
    return null; // malformed escape such as %zz
  }
  if (!key || new TextEncoder().encode(key).length > MAX_KEY_BYTES) return null;
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f\u007f\\]/.test(key)) return null;
  const parts = key.split('/');
  if (parts[0].startsWith('_')) return null;
  if (parts.some((p) => p === '' || p === '.' || p === '..')) return null;
  return key;
}

// A website code (MediaKey::CODE_PATTERN): what a legacy prefix may point to. A mistyped code in the
// config is ignored rather than turned into a redirect to some other folder.
const CODE = /^[0-9a-hjkmnp-tv-z]{6}$/;

// The legacy-prefix map from the Worker config (wrangler.jsonc `vars.LEGACY_PREFIXES`): an object, or
// the same object as a JSON string, of `{old prefix}/` → website code. Entries that are not a clean
// prefix ending in "/" (no "_" first segment, no empty, "." or ".." segment) with a valid code are
// dropped, and so is a prefix whose first segment is itself a code: that would send a website's own
// files to themselves, or one website's files into another's folder. Longest prefix first, so a
// deeper prefix wins over a shorter one.
export function legacyPrefixes(raw) {
  let map = raw;
  if (typeof map === 'string') {
    try {
      map = JSON.parse(map);
    } catch {
      return [];
    }
  }
  if (map === null || typeof map !== 'object' || Array.isArray(map)) return [];
  return Object.entries(map)
    .filter(
      ([prefix, code]) =>
        typeof code === 'string' &&
        CODE.test(code) &&
        prefix.endsWith('/') &&
        publicKey(`${prefix}x`) === `${prefix}x` &&
        !CODE.test(prefix.slice(0, prefix.indexOf('/'))),
    )
    .sort(([a], [b]) => b.length - a.length);
}

// The key a legacy key was copied to: `{code}/{file}`, the file being the old key's last segment, as
// media:rekey-site names it when nothing clashes. Null when no prefix holds the key.
export function legacyTarget(key, prefixes) {
  for (const [prefix, code] of prefixes) {
    if (key.startsWith(prefix) && key.length > prefix.length) {
      const target = `${code}/${key.slice(key.lastIndexOf('/') + 1)}`;
      return target === key ? null : target;
    }
  }
  return null;
}

export function typeOf(key) {
  const dot = key.lastIndexOf('.');
  if (dot < 0 || dot < key.lastIndexOf('/')) return null;
  return TYPES.get(key.slice(dot + 1).toLowerCase()) ?? null;
}
