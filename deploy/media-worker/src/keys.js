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

export function typeOf(key) {
  const dot = key.lastIndexOf('.');
  if (dot < 0 || dot < key.lastIndexOf('/')) return null;
  return TYPES.get(key.slice(dot + 1).toLowerCase()) ?? null;
}
