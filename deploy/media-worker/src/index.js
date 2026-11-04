// Media serving plane (ADR-17). The ONLY public reader of the private R2 bucket `baoson-media`.
// - GET /{key}        → raw master from R2 (canonical / L2 fallback). Honours one byte Range (206 +
//                       Content-Range; Safari/iOS plays a <video> only from ranged answers) and the
//                       If-* preconditions (304/412); HEAD answers the headers without the body
// - GET /{key}?w=320  → cf.image transform to the client's best format (avif/webp/jpeg), edge-cached
// - GET /__origin/{key} → internal raw path that feeds cf.image (breaks the transform→fetch loop)
// Width is whitelisted (anti resize-DoS). A transform failure degrades to the raw master (image never
// disappears). The key is content-addressed ({tenant}/{collection}/{slug}-{hash}.ext) so the immutable
// cache never goes stale: changed content = new hash = new key.

const ALLOWED_WIDTHS = new Set([320, 640, 960, 1280, 1600, 2048]);
const CACHE_CONTROL = 'public, max-age=31536000, immutable';
const ORIGIN_PREFIX = '/__origin/'; // internal raw path — source for cf.image, avoids a fetch loop

// cf.image format:'auto' does not negotiate reliably inside a Worker subrequest, so pick the output
// format explicitly from the client's Accept header (avif > webp > jpeg fallback).
function pickFormat(accept) {
  if (accept.includes('image/avif')) return 'avif';
  if (accept.includes('image/webp')) return 'webp';
  return 'jpeg';
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // (a) Internal path → serve the raw master from R2 (no transform). This is what cf.image fetches.
    if (url.pathname.startsWith(ORIGIN_PREFIX)) {
      return serveRaw(request, env, decodeURIComponent(url.pathname.slice(ORIGIN_PREFIX.length)));
    }

    const key = decodeURIComponent(url.pathname.slice(1));
    if (!key) return new Response('Not found', { status: 404 });

    const wParam = url.searchParams.get('w');
    const width = wParam ? parseInt(wParam, 10) : null;
    if (width !== null && !ALLOWED_WIDTHS.has(width)) {
      return new Response('Invalid width', { status: 400 });
    }

    // (b) No width → the raw master (canonical / L2 fallback).
    if (width === null) return serveRaw(request, env, key);

    // (c) Width → transform via cf.image to the client's best format, sourced from the internal raw
    // path. Format is an explicit transform param (not 'auto'), so Cloudflare's image cache splits
    // per format; the Vary below keeps browsers/proxies from mixing avif/webp/jpeg variants.
    const format = pickFormat(request.headers.get('Accept') || '');
    const originUrl = `${url.origin}${ORIGIN_PREFIX}${encodeURIComponent(key)}`;
    const resp = await fetch(originUrl, {
      cf: { image: { width, format, fit: 'scale-down' } },
    });
    if (!resp.ok) return serveRaw(request, env, key); // transform failed → L2: serve the master, image survives

    const headers = new Headers(resp.headers);
    headers.set('Cache-Control', CACHE_CONTROL);
    headers.set('Vary', 'Accept');
    return new Response(resp.body, { status: resp.status, headers });
  },
};

// The raw master. R2 reads the request's Range and If-* headers itself: a ranged read gives a 206
// with Content-Range, a failed precondition gives an object without a body (304, or 412 for a
// failed If-Match / If-Unmodified-Since), a range past the end throws (416). Every answer here
// says Accept-Ranges: bytes, so a <video> knows it may seek.
async function serveRaw(request, env, key) {
  if (request.method === 'HEAD') {
    const meta = await env.MEDIA.head(key);
    if (!meta) return new Response(null, { status: 404 });
    const headers = rawHeaders(meta);
    headers.set('Content-Length', String(meta.size));
    return new Response(null, { headers });
  }

  let obj;
  try {
    obj = await env.MEDIA.get(key, { range: request.headers, onlyIf: request.headers });
  } catch (err) {
    if (!request.headers.has('Range')) throw err;
    const meta = await env.MEDIA.head(key); // an unsatisfiable range: say how long the file is
    if (!meta) return new Response('Not found', { status: 404 });
    const headers = rawHeaders(meta);
    headers.set('Content-Range', `bytes */${meta.size}`);
    return new Response(null, { status: 416, headers });
  }
  if (!obj) return new Response('Not found', { status: 404 });

  const headers = rawHeaders(obj);
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

function rawHeaders(obj) {
  const headers = new Headers();
  obj.writeHttpMetadata(headers);
  headers.set('Cache-Control', CACHE_CONTROL);
  headers.set('ETag', obj.httpEtag);
  headers.set('Accept-Ranges', 'bytes');
  return headers;
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
