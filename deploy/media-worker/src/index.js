// Media serving plane (ADR-17). The ONLY public reader of the private R2 bucket `baoson-media`.
// - GET /{key}        → raw master from R2 (canonical / L2 fallback). Honours one byte Range (206 +
//                       Content-Range; Safari/iOS plays a <video> only from ranged answers) and the
//                       If-* preconditions (304/412); HEAD answers the headers without the body
// - GET /{key}?w=320  → cf.image transform to the client's best format (avif/webp/jpeg), edge-cached
// - GET /__origin/{key} → internal raw path that feeds cf.image (breaks the transform→fetch loop)
// Width is whitelisted (anti resize-DoS). A transform failure degrades to the raw master (image never
// disappears). The key is content-addressed ({tenant}/{collection}/{slug}-{hash}.ext) so the immutable
// cache never goes stale: changed content = new hash = new key.
//
// What the host may serve is decided here, never by the object's stored metadata: an uploader controls
// the Content-Type of the temporary object it PUTs, so trusting R2 metadata would let anyone with an
// upload URL publish an HTML page under this domain. The type comes from the key's extension (the app
// names every final key from the sniffed MIME); keys whose first segment starts with "_" (uploads in
// flight, internal prefixes) are never public.

import { publicKey, typeOf } from './keys.js';

const ALLOWED_WIDTHS = new Set([320, 640, 960, 1280, 1600, 2048]);
const CACHE_CONTROL = 'public, max-age=31536000, immutable';
const RASTER = new Set(['image/avif', 'image/webp', 'image/jpeg', 'image/png', 'image/gif']);
const ORIGIN_PREFIX = '/__origin/'; // internal raw path — source for cf.image, avoids a fetch loop
// Sent on every response: no sniffing, and a sandbox so even a mislabelled body can never run script
// or reach this origin's cookies.
const SAFETY_HEADERS = {
  'X-Content-Type-Options': 'nosniff',
  'Content-Security-Policy': "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
};

// cf.image format:'auto' does not negotiate reliably inside a Worker subrequest, so pick the output
// format explicitly from the client's Accept header (avif > webp > jpeg fallback).
function pickFormat(accept) {
  if (accept.includes('image/avif')) return 'avif';
  if (accept.includes('image/webp')) return 'webp';
  return 'jpeg';
}

function plain(status, text) {
  return new Response(text, { status, headers: { 'Content-Type': 'text/plain; charset=utf-8', ...SAFETY_HEADERS } });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method !== 'GET' && request.method !== 'HEAD') return plain(405, 'Method not allowed');

    // (a) Internal path → serve the raw master from R2 (no transform). This is what cf.image fetches.
    const internal = url.pathname.startsWith(ORIGIN_PREFIX);
    const key = publicKey(url.pathname.slice(internal ? ORIGIN_PREFIX.length : 1));
    if (key === null) return plain(404, 'Not found');
    const type = typeOf(key);
    if (type === null) return plain(404, 'Not found');
    if (internal) return serveRaw(request, env, key, type);

    const wParam = url.searchParams.get('w');
    const width = wParam ? parseInt(wParam, 10) : null;
    if (width !== null && !ALLOWED_WIDTHS.has(width)) {
      return plain(400, 'Invalid width');
    }

    // (b) No width, or not a raster image → the raw master (canonical / L2 fallback).
    if (width === null || !type.startsWith('image/')) return serveRaw(request, env, key, type);

    // (c) Width → transform via cf.image to the client's best format, sourced from the internal raw
    // path. Format is an explicit transform param (not 'auto'), so Cloudflare's image cache splits
    // per format; the Vary below keeps browsers/proxies from mixing avif/webp/jpeg variants.
    const format = pickFormat(request.headers.get('Accept') || '');
    const originUrl = `${url.origin}${ORIGIN_PREFIX}${encodeURIComponent(key)}`;
    const resp = await fetch(originUrl, {
      cf: { image: { width, format, fit: 'scale-down' } },
    });
    if (!resp.ok) return serveRaw(request, env, key, type); // transform failed → L2: serve the master, image survives

    // Only raster output we asked for or that cf.image passes through; anything else (an svg, a
    // body that is not an image) falls back to the master under its extension's type.
    const outType = (resp.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
    if (!RASTER.has(outType)) return serveRaw(request, env, key, type);
    const headers = new Headers();
    headers.set('Content-Type', outType);
    headers.set('Cache-Control', CACHE_CONTROL);
    headers.set('Vary', 'Accept');
    for (const [k, v] of Object.entries(SAFETY_HEADERS)) headers.set(k, v);
    return new Response(resp.body, { status: resp.status, headers });
  },
};

// The raw master. R2 reads the request's Range and If-* headers itself: a ranged read gives a 206
// with Content-Range, a failed precondition gives an object without a body (304, or 412 for a
// failed If-Match / If-Unmodified-Since), a range past the end throws (416). Every answer here
// says Accept-Ranges: bytes, so a <video> knows it may seek. The type is the one from the key's
// extension, passed in by fetch(); the object's stored HTTP metadata is never read.
async function serveRaw(request, env, key, type) {
  if (request.method === 'HEAD') {
    const meta = await env.MEDIA.head(key);
    if (!meta) return new Response(null, { status: 404, headers: SAFETY_HEADERS });
    const headers = rawHeaders(meta, type, key);
    headers.set('Content-Length', String(meta.size));
    return new Response(null, { headers });
  }

  let obj;
  try {
    obj = await env.MEDIA.get(key, { range: request.headers, onlyIf: request.headers });
  } catch (err) {
    // Only two ranged failures are answered here; anything else (an R2 fault) stays an error.
    const range = (request.headers.get('Range') ?? '').trim();
    if (range === '') throw err;
    const single = /^bytes=(\d*)-(\d*)$/i.exec(range);
    if (single) {
      const meta = await env.MEDIA.head(key);
      if (!meta) return plain(404, 'Not found');
      if (single[1] === '' || Number(single[1]) < meta.size) throw err;
      const headers = rawHeaders(meta, type, key); // starts past the end: say how long the file is
      headers.set('Content-Range', `bytes */${meta.size}`);
      return new Response(null, { status: 416, headers });
    }
    // several ranges or another unit, which this Worker does not serve: RFC 9110 lets it answer in full
    obj = await env.MEDIA.get(key, { onlyIf: request.headers });
  }
  if (!obj) return plain(404, 'Not found');

  const headers = rawHeaders(obj, type, key);
  if (!('body' in obj) || !obj.body) {
    return new Response(null, { status: preconditionStatus(request, obj), headers });
  }

  if (obj.range && request.headers.has('Range')) {
    const [start, end] = byteSpan(obj.range, obj.size);
    headers.set('Content-Range', `bytes ${start}-${end}/${obj.size}`);
    headers.set('Content-Length', String(end - start + 1));
    return new Response(obj.body, { status: 206, headers });
  }

  return new Response(obj.body, { headers });
}

// Only the type from the extension and our own caching and safety headers.
function rawHeaders(obj, type, key) {
  const headers = new Headers();
  headers.set('Content-Type', type);
  if (type === 'application/pdf') headers.set('Content-Disposition', attachment(key));
  headers.set('Cache-Control', CACHE_CONTROL);
  headers.set('ETag', obj.httpEtag);
  headers.set('Accept-Ranges', 'bytes');
  for (const [k, v] of Object.entries(SAFETY_HEADERS)) headers.set(k, v);
  return headers;
}

// A download keeps the readable name of its key: the last segment without the content hash before
// the extension (4 hex by default, longer only after a collision: media.url_hash_length). A name that does not start with a letter or digit, or holds anything outside
// [A-Za-z0-9._-] (quotes, spaces, CR/LF, ';'), gets no name at all.
function attachment(key) {
  const name = key.slice(key.lastIndexOf('/') + 1).replace(/-[0-9a-f]{4,64}(?=\.[^.]*$)/, '');
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(name)) return 'attachment';
  return `attachment; filename="${name}"; filename*=UTF-8''${name}`;
}

// The first and last byte R2 returned: its range is {offset, length?} or {suffix}.
function byteSpan(range, size) {
  if (range.suffix !== undefined) {
    return [Math.max(0, size - range.suffix), size - 1];
  }
  const start = range.offset ?? 0;
  const end = range.length !== undefined ? Math.min(start + range.length, size) - 1 : size - 1;
  return [start, end];
}

// RFC 9110 §13.2.2: If-Match is checked first (strong compare, a failure is 412); without it, a
// failed If-Unmodified-Since is 412; otherwise the failure was If-None-Match / If-Modified-Since: 304.
function preconditionStatus(request, obj) {
  const ifMatch = request.headers.get('If-Match');
  if (ifMatch !== null) {
    const tags = ifMatch.split(',').map((t) => t.trim());
    return tags.includes('*') || tags.includes(obj.httpEtag) ? 304 : 412;
  }
  const since = Date.parse(request.headers.get('If-Unmodified-Since') ?? '');
  if (!Number.isNaN(since) && obj.uploaded instanceof Date && Math.floor(obj.uploaded.getTime() / 1000) * 1000 > since) return 412;
  return 304;
}

// Future (designed-for, not wired): a host→tenant prefix map for per-tenant domains, and a
// draft-media token check at the top of fetch(). Both slot in here without changing public URLs.
