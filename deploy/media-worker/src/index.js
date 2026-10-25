// Media serving plane (ADR-17). The ONLY public reader of the private R2 bucket `baoson-media`.
// - GET /{key}        → raw master from R2 (canonical / L2 fallback)
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
    if (internal) return serveRaw(env, key, type);

    const wParam = url.searchParams.get('w');
    const width = wParam ? parseInt(wParam, 10) : null;
    if (width !== null && !ALLOWED_WIDTHS.has(width)) {
      return plain(400, 'Invalid width');
    }

    // (b) No width, or not a raster image → the raw master (canonical / L2 fallback).
    if (width === null || !type.startsWith('image/')) return serveRaw(env, key, type);

    // (c) Width → transform via cf.image to the client's best format, sourced from the internal raw
    // path. Format is an explicit transform param (not 'auto'), so Cloudflare's image cache splits
    // per format; the Vary below keeps browsers/proxies from mixing avif/webp/jpeg variants.
    const format = pickFormat(request.headers.get('Accept') || '');
    const originUrl = `${url.origin}${ORIGIN_PREFIX}${encodeURIComponent(key)}`;
    const resp = await fetch(originUrl, {
      cf: { image: { width, format, fit: 'scale-down' } },
    });
    if (!resp.ok) return serveRaw(env, key, type); // transform failed → L2: serve the master, image survives

    const outType = (resp.headers.get('Content-Type') || '').split(';')[0].trim();
    const headers = new Headers();
    headers.set('Content-Type', outType.startsWith('image/') ? outType : `image/${format}`);
    headers.set('Cache-Control', CACHE_CONTROL);
    headers.set('Vary', 'Accept');
    for (const [k, v] of Object.entries(SAFETY_HEADERS)) headers.set(k, v);
    return new Response(resp.body, { status: resp.status, headers });
  },
};

async function serveRaw(env, key, type) {
  const obj = await env.MEDIA.get(key);
  if (!obj) return plain(404, 'Not found');

  // Only the type from the extension and our own caching headers; never the stored HTTP metadata.
  const headers = new Headers();
  headers.set('Content-Type', type);
  if (type === 'application/pdf') headers.set('Content-Disposition', 'attachment');
  headers.set('Cache-Control', CACHE_CONTROL);
  headers.set('ETag', obj.httpEtag);
  for (const [k, v] of Object.entries(SAFETY_HEADERS)) headers.set(k, v);
  return new Response(obj.body, { headers });
}

// Future (designed-for, not wired): a host→tenant prefix map for per-tenant domains, and a
// draft-media token check at the top of fetch(). Both slot in here without changing public URLs.
