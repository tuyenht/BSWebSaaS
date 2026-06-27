// Media serving plane (ADR-17). The ONLY public reader of the private R2 bucket `bswebsaas-media`.
// - GET /{key}        → raw master from R2 (canonical / L2 fallback)
// - GET /{key}?w=320  → cf.image transform (format=auto), source = the raw master, then edge-cached
// - GET /__origin/{key} → internal raw path that feeds cf.image (breaks the transform→fetch loop)
// Width is whitelisted (anti resize-DoS). A transform failure degrades to the raw master (image never
// disappears). The key is content-addressed ({tenant}/{collection}/{slug}-{hash}.ext) so the immutable
// cache never goes stale: changed content = new hash = new key.

const ALLOWED_WIDTHS = new Set([320, 640, 960, 1280, 1600, 2048]);
const CACHE_CONTROL = 'public, max-age=31536000, immutable';
const ORIGIN_PREFIX = '/__origin/'; // internal raw path — source for cf.image, avoids a fetch loop

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // (a) Internal path → serve the raw master from R2 (no transform). This is what cf.image fetches.
    if (url.pathname.startsWith(ORIGIN_PREFIX)) {
      return serveRaw(env, decodeURIComponent(url.pathname.slice(ORIGIN_PREFIX.length)));
    }

    const key = decodeURIComponent(url.pathname.slice(1));
    if (!key) return new Response('Not found', { status: 404 });

    const wParam = url.searchParams.get('w');
    const width = wParam ? parseInt(wParam, 10) : null;
    if (width !== null && !ALLOWED_WIDTHS.has(width)) {
      return new Response('Invalid width', { status: 400 });
    }

    // (b) No width → the raw master (canonical / L2 fallback).
    if (width === null) return serveRaw(env, key);

    // (c) Width → transform via cf.image, sourced from the internal raw path, format negotiated.
    const originUrl = `${url.origin}${ORIGIN_PREFIX}${encodeURIComponent(key)}`;
    const resp = await fetch(originUrl, {
      cf: { image: { width, format: 'auto', fit: 'scale-down' } },
      headers: { Accept: request.headers.get('Accept') || '' },
    });
    if (!resp.ok) return serveRaw(env, key); // transform failed → L2: serve the master, image survives

    const headers = new Headers(resp.headers);
    headers.set('Cache-Control', CACHE_CONTROL);
    return new Response(resp.body, { status: resp.status, headers });
  },
};

async function serveRaw(env, key) {
  const obj = await env.MEDIA.get(key);
  if (!obj) return new Response('Not found', { status: 404 });

  const headers = new Headers();
  obj.writeHttpMetadata(headers);
  headers.set('Cache-Control', CACHE_CONTROL);
  headers.set('ETag', obj.httpEtag);
  return new Response(obj.body, { headers });
}

// Future (designed-for, not wired): a host→tenant prefix map for per-tenant domains, and a
// draft-media token check at the top of fetch(). Both slot in here without changing public URLs.
