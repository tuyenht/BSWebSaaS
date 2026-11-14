// Run: node --test deploy/media-worker/test/worker.test.js
// The host must decide what it serves from the key, never from what an uploader stored in R2.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';
import { publicKey, typeOf } from '../src/keys.js';

// An R2 stand-in whose objects carry whatever metadata the uploader chose (text/html, which the
// Worker must never pass on). Like R2, get() reads the Range and If-* headers itself: a failed
// precondition gives the object without a body, one byte range gives {offset, length}, a range that
// starts past the end (or several ranges) throws, and a missing key is null.
const ETAG = '"e1"';
const UPLOADED = new Date('2026-11-01T00:00:00Z');

function env(objects) {
  const meta = (key) => ({
    size: objects[key].length,
    httpEtag: ETAG,
    uploaded: UPLOADED,
    writeHttpMetadata(h) {
      h.set('Content-Type', 'text/html');
    },
  });
  return {
    MEDIA: {
      async head(key) {
        return key in objects ? meta(key) : null;
      },
      async get(key, opts = {}) {
        if (!(key in objects)) return null;
        const m = meta(key);
        if (opts.onlyIf instanceof Headers && !preconditionsHold(opts.onlyIf, m)) return m;
        const range = opts.range instanceof Headers ? opts.range.get('Range') : null;
        if (range === null) return { ...m, body: objects[key] };
        const r = /^bytes=(\d*)-(\d*)$/.exec(range);
        if (!r || (r[1] === '' && r[2] === '')) throw new Error('get: The requested range is not satisfiable (10039)');
        let offset;
        let length;
        if (r[1] === '') {
          offset = Math.max(0, m.size - Number(r[2]));
          length = m.size - offset;
        } else {
          offset = Number(r[1]);
          if (offset >= m.size) throw new Error('get: The requested range is not satisfiable (10039)');
          const last = r[2] === '' ? m.size - 1 : Math.min(Number(r[2]), m.size - 1);
          length = last - offset + 1;
        }
        return { ...m, body: objects[key].slice(offset, offset + length), range: { offset, length } };
      },
    },
  };
}

// R2's onlyIf from request headers: If-Match / If-None-Match on the etag, then the two dates.
function preconditionsHold(h, m) {
  const tags = (v) => v.split(',').map((t) => t.trim());
  const ifMatch = h.get('If-Match');
  if (ifMatch !== null && !tags(ifMatch).some((t) => t === '*' || t === m.httpEtag)) return false;
  const ifNoneMatch = h.get('If-None-Match');
  if (ifNoneMatch !== null && tags(ifNoneMatch).some((t) => t === '*' || t === m.httpEtag)) return false;
  const unmodified = Date.parse(h.get('If-Unmodified-Since') ?? '');
  if (!Number.isNaN(unmodified) && m.uploaded.getTime() > unmodified) return false;
  const modified = Date.parse(h.get('If-Modified-Since') ?? '');
  if (ifNoneMatch === null && !Number.isNaN(modified) && m.uploaded.getTime() <= modified) return false;
  return true;
}

// Every answer of the host, whatever its status, must forbid sniffing and run any body sandboxed.
function assertSafe(res, label) {
  assert.equal(res.headers.get('X-Content-Type-Options'), 'nosniff', `${label}: nosniff`);
  assert.match(res.headers.get('Content-Security-Policy') ?? '', /(^|;)\s*sandbox\s*(;|$)/, `${label}: sandbox CSP`);
  assert.notEqual(res.headers.get('Content-Type'), 'text/html', `${label}: stored type leaked`);
}

const get = (path, e, headers = {}, method = 'GET') =>
  worker.fetch(new Request(`https://files.example.test${path}`, { headers, method }), e);

test('an image key is served with the type of its extension, not the stored metadata', async () => {
  const res = await get('/an-vinh/library/may-tien-3f9a.jpg', env({ 'an-vinh/library/may-tien-3f9a.jpg': 'x' }));
  assert.equal(res.status, 200);
  assert.equal(res.headers.get('Content-Type'), 'image/jpeg');
  assert.equal(res.headers.get('X-Content-Type-Options'), 'nosniff');
  assert.match(res.headers.get('Content-Security-Policy'), /sandbox/);
});

test('an html or unknown extension is never served, even when the object exists', async () => {
  const e = env({ 'a/library/page.html': '<script>', 'a/library/noext': 'x', 'a/library/x.svg': '<svg>' });
  for (const p of ['/a/library/page.html', '/a/library/noext', '/a/library/x.svg']) {
    assert.equal((await get(p, e)).status, 404, p);
  }
});

test('uploads in flight and other underscore prefixes are not public, on either path', async () => {
  const e = env({ '_incoming/a/01x.jpg': 'x' });
  assert.equal((await get('/_incoming/a/01x.jpg', e)).status, 404);
  assert.equal((await get('/__origin/_incoming/a/01x.jpg', e)).status, 404);
  assert.equal((await get('/__origin/%5Fincoming%2Fa%2F01x.jpg', e)).status, 404);
});

test('a pdf is a download, never rendered inline', async () => {
  const res = await get('/a/library/bang-gia-1a2b.pdf', env({ 'a/library/bang-gia-1a2b.pdf': '%PDF' }));
  assert.equal(res.headers.get('Content-Type'), 'application/pdf');
  assert.equal(res.headers.get('Content-Disposition'), 'attachment; filename="bang-gia.pdf"; filename*=UTF-8\'\'bang-gia.pdf');
});

const NAMED = 'attachment; filename="ho-so-nang-luc-an-vinh.pdf"; filename*=UTF-8\'\'ho-so-nang-luc-an-vinh.pdf';

test('a pdf download drops the content hash from its file name', async () => {
  const k = 'an-vinh/library/ho-so-nang-luc-an-vinh-0123456789ab.pdf';
  assert.equal((await get(`/${k}`, env({ [k]: '%PDF' }))).headers.get('Content-Disposition'), NAMED);
});

test('a pdf download drops the default 4-hex content hash too', async () => {
  const k = 'co-khi-chinh-xac-an-vinh/library/ho-so-nang-luc-an-vinh-6ab7.pdf';
  assert.equal((await get(`/${k}`, env({ [k]: '%PDF' }))).headers.get('Content-Disposition'), NAMED);
});

test('a pdf key without a hash suffix keeps its name', async () => {
  const k = 'an-vinh/ho-so-nang-luc-an-vinh.pdf';
  assert.equal((await get(`/${k}`, env({ [k]: '%PDF' }))).headers.get('Content-Disposition'), NAMED);
});

test('only the last segment of a foldered key names the download', async () => {
  const k = 'an-vinh/library/tai-lieu/2026/ho-so-nang-luc-an-vinh-0123456789ab.pdf';
  assert.equal((await get(`/${k}`, env({ [k]: '%PDF' }))).headers.get('Content-Disposition'), NAMED);
});

test('a name with a quote or a space, or not starting with a letter or digit, falls back to a plain attachment', async () => {
  for (const k of ['a/x%22y-0123456789ab.pdf', 'a/x%20y-0123456789ab.pdf', 'a/x%3By.pdf', 'a/-0123456789ab.pdf', 'a/.hidden.pdf']) {
    const key = decodeURIComponent(k);
    const res = await get(`/${k}`, env({ [key]: '%PDF' }));
    assert.equal(res.headers.get('Content-Disposition'), 'attachment', k);
  }
});

test('a non-pdf has no Content-Disposition', async () => {
  const res = await get('/a/library/x-0123456789ab.jpg', env({ 'a/library/x-0123456789ab.jpg': 'x' }));
  assert.equal(res.headers.get('Content-Disposition'), null);
});

test('the ranged answer for a pdf carries the same file name', async () => {
  const k = 'an-vinh/library/ho-so-nang-luc-an-vinh-0123456789ab.pdf';
  const res = await get(`/${k}`, env({ [k]: '%PDF-1.7' }), { Range: 'bytes=0-3' });
  assert.equal(res.status, 206);
  assert.equal(res.headers.get('Content-Disposition'), NAMED);
});

test('a malformed escape is a 404, not a crash', async () => {
  assert.equal((await get('/a/%zz.jpg', env({}))).status, 404);
});

test('a width on a pdf serves the file itself instead of asking for a transform', async () => {
  const res = await get('/a/library/f-1a2b.pdf?w=320', env({ 'a/library/f-1a2b.pdf': '%PDF' }));
  assert.equal(res.headers.get('Content-Type'), 'application/pdf');
});

test('a transformed image keeps only an image type and the safety headers', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () =>
    new Response('img', { headers: { 'Content-Type': 'image/webp', 'Set-Cookie': 'a=b', 'Cf-Resized': 'internal=ok/- q=0' } });
  try {
    const res = await get('/a/library/x-1a2b.jpg?w=320', env({}), { Accept: 'image/webp' });
    assert.equal(res.headers.get('Content-Type'), 'image/webp');
    assert.equal(res.headers.get('Set-Cookie'), null);
    assertSafe(res, 'transformed');
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a transform that returns svg or html falls back to the master with its extension type', async () => {
  const realFetch = globalThis.fetch;
  try {
    for (const t of ['image/svg+xml', 'text/html']) {
      globalThis.fetch = async () => new Response('<svg onload=alert(1)>', { headers: { 'Content-Type': t } });
      const res = await get('/a/library/x-1a2b.jpg?w=320', env({ 'a/library/x-1a2b.jpg': 'x' }));
      assert.equal(res.headers.get('Content-Type'), 'image/jpeg', t);
      assert.equal(res.headers.get('Cache-Control'), 'public, max-age=300', t);
    }
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a failed transform serves the master for a few minutes only, a working one for a year', async () => {
  const realFetch = globalThis.fetch;
  const objects = { 'a/library/x-1a2b.jpg': 'master' };
  try {
    globalThis.fetch = async () => new Response('limit reached', { status: 403, headers: { 'Content-Type': 'text/plain' } });
    const failed = await get('/a/library/x-1a2b.jpg?w=320', env(objects), { Accept: 'image/avif' });
    assert.equal(failed.status, 200);
    assert.equal(failed.headers.get('Content-Type'), 'image/jpeg');
    assert.equal(await failed.text(), 'master');
    assert.equal(failed.headers.get('Cache-Control'), 'public, max-age=300');
    assertSafe(failed, 'fallback');

    // A failure can also come back as 200 with the source and an error in Cf-Resized.
    globalThis.fetch = async () => new Response('source', { headers: { 'Content-Type': 'image/jpeg', 'Cf-Resized': 'err=9422' } });
    const marked = await get('/a/library/x-1a2b.jpg?w=320', env(objects), { Accept: 'image/avif' });
    assert.equal(await marked.text(), 'master');
    assert.equal(marked.headers.get('Cache-Control'), 'public, max-age=300');

    // HEAD and a revalidation of the stand-in are short-lived too.
    globalThis.fetch = async () => new Response('limit reached', { status: 403 });
    const head = await get('/a/library/x-1a2b.jpg?w=320', env(objects), { Accept: 'image/avif' }, 'HEAD');
    assert.equal(head.headers.get('Cache-Control'), 'public, max-age=300');
    const again = await get('/a/library/x-1a2b.jpg?w=320', env(objects), { Accept: 'image/avif', 'If-None-Match': '"e1"' });
    assert.equal(again.status, 304);
    assert.equal(again.headers.get('Cache-Control'), 'public, max-age=300');

    // A working transform is lasting with or without Cf-Resized (Cloudflare does not always send it).
    for (const marks of [{ 'Cf-Resized': 'internal=ok/- q=0' }, {}]) {
      globalThis.fetch = async () => new Response('img', { headers: { 'Content-Type': 'image/avif', ...marks } });
      const ok = await get('/a/library/x-1a2b.jpg?w=320', env(objects), { Accept: 'image/avif' });
      assert.match(ok.headers.get('Cache-Control'), /immutable/, JSON.stringify(marks));
    }

    // A transform that ran but gave another format (too large for AVIF → WebP) is still lasting.
    globalThis.fetch = async () => new Response('img', { headers: { 'Content-Type': 'image/webp' } });
    const other = await get('/a/library/x-1a2b.jpg?w=2048', env(objects), { Accept: 'image/avif' });
    assert.equal(other.headers.get('Content-Type'), 'image/webp');
    assert.match(other.headers.get('Cache-Control'), /immutable/);
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('a missing master behind a width is a 404 without a cache header, as without the width', async () => {
  const realFetch = globalThis.fetch;
  globalThis.fetch = async () => new Response('Not found', { status: 404 });
  try {
    const res = await get('/a/library/gone-1a2b.jpg?w=320', env({}), { Accept: 'image/avif' });
    assert.equal(res.status, 404);
    assert.equal(res.headers.get('Cache-Control'), null);
  } finally {
    globalThis.fetch = realFetch;
  }
});

test('the master itself, without a width, stays cached for a year', async () => {
  const res = await get('/a/library/x-1a2b.jpg', env({ 'a/library/x-1a2b.jpg': 'master' }));
  assert.match(res.headers.get('Cache-Control'), /max-age=31536000, immutable/);
});

test('writes are refused', async () => {
  const res = await worker.fetch(new Request('https://files.example.test/a/x.jpg', { method: 'PUT', body: 'x' }), env({}));
  assert.equal(res.status, 405);
});

test('a ranged read answers 206 under the extension type, with the safety headers', async () => {
  const res = await get('/a/library/x-1a2b.jpg', env({ 'a/library/x-1a2b.jpg': 'abcdef' }), { Range: 'bytes=0-1' });
  assert.equal(res.status, 206);
  assert.equal(res.headers.get('Content-Range'), 'bytes 0-1/6');
  assert.equal(res.headers.get('Accept-Ranges'), 'bytes');
  assert.equal(res.headers.get('Content-Type'), 'image/jpeg');
  assert.equal(res.headers.get('X-Content-Type-Options'), 'nosniff');
});

test('HEAD answers the extension type and size, never the stored metadata', async () => {
  const e = env({ 'a/library/x-1a2b.webp': 'abcdef' });
  const res = await get('/a/library/x-1a2b.webp', e, {}, 'HEAD');
  assert.equal(res.status, 200);
  assert.equal(res.headers.get('Content-Type'), 'image/webp');
  assert.equal(res.headers.get('Content-Length'), '6');
  assert.equal(res.headers.get('X-Content-Type-Options'), 'nosniff');
  assert.equal((await get('/a/library/page.html', e, {}, 'HEAD')).status, 404);
  const missing = await get('/a/library/gone-1a2b.webp', e, {}, 'HEAD');
  assert.equal(missing.status, 404);
  assert.equal(missing.headers.get('X-Content-Type-Options'), 'nosniff');
});

test('a missing key answers 404 with the safety headers, on GET and on the internal path', async () => {
  for (const p of ['/a/library/gone-1a2b.jpg', '/__origin/a/library/gone-1a2b.jpg']) {
    const res = await get(p, env({}));
    assert.equal(res.status, 404, p);
    assertSafe(res, `404 ${p}`);
  }
});

test('a matching If-None-Match answers 304 without a body, with the safety headers', async () => {
  const res = await get('/a/library/x-1a2b.jpg', env({ 'a/library/x-1a2b.jpg': 'abcdef' }), { 'If-None-Match': ETAG });
  assert.equal(res.status, 304);
  assert.equal(res.headers.get('ETag'), ETAG);
  assert.equal(res.headers.get('Content-Type'), 'image/jpeg');
  assert.equal(await res.text(), '');
  assertSafe(res, '304');
});

test('a failed If-Match or If-Unmodified-Since answers 412 with the safety headers', async () => {
  const e = env({ 'a/library/x-1a2b.jpg': 'abcdef' });
  for (const h of [{ 'If-Match': '"other"' }, { 'If-Unmodified-Since': 'Sat, 01 Jan 2000 00:00:00 GMT' }]) {
    const res = await get('/a/library/x-1a2b.jpg', e, h);
    assert.equal(res.status, 412, JSON.stringify(h));
    assertSafe(res, `412 ${JSON.stringify(h)}`);
  }
});

test('a range that starts past the end answers 416 with the length and the safety headers', async () => {
  const res = await get('/a/library/x-1a2b.jpg', env({ 'a/library/x-1a2b.jpg': 'abcdef' }), { Range: 'bytes=99-' });
  assert.equal(res.status, 416);
  assert.equal(res.headers.get('Content-Range'), 'bytes */6');
  assertSafe(res, '416');
});

test('200, 206, several ranges and HEAD all carry the safety headers', async () => {
  const e = env({ 'a/library/x-1a2b.jpg': 'abcdef' });
  const full = await get('/a/library/x-1a2b.jpg', e);
  assert.equal(full.status, 200);
  assertSafe(full, '200');
  const part = await get('/a/library/x-1a2b.jpg', e, { Range: 'bytes=2-3' });
  assert.equal(part.status, 206);
  assert.equal(part.headers.get('Content-Range'), 'bytes 2-3/6');
  assert.equal(await part.text(), 'cd');
  assertSafe(part, '206');
  const several = await get('/a/library/x-1a2b.jpg', e, { Range: 'bytes=0-1,3-4' });
  assert.equal(several.status, 200);
  assert.equal(await several.text(), 'abcdef');
  assertSafe(several, '200 for several ranges');
  const head = await get('/a/library/x-1a2b.jpg', e, {}, 'HEAD');
  assert.equal(head.status, 200);
  assertSafe(head, 'HEAD 200');
  const headMissing = await get('/a/library/gone-1a2b.jpg', e, {}, 'HEAD');
  assert.equal(headMissing.status, 404);
  assertSafe(headMissing, 'HEAD 404');
});

test('refused paths and widths carry the safety headers too', async () => {
  const e = env({ 'a/library/page.html': '<script>' });
  for (const [p, status] of [['/a/library/page.html', 404], ['/_incoming/a/x.jpg', 404], ['/a/library/x-1a2b.jpg?w=999', 400]]) {
    const res = await get(p, e);
    assert.equal(res.status, status, p);
    assertSafe(res, `${status} ${p}`);
  }
  const put = await worker.fetch(new Request('https://files.example.test/a/x.jpg', { method: 'PUT', body: 'x' }), e);
  assertSafe(put, '405');
});

test('key rules', () => {
  assert.equal(publicKey('a/../b.jpg'), null);
  assert.equal(publicKey('a//b.jpg'), null);
  assert.equal(publicKey('a/b%00.jpg'), null);
  assert.equal(publicKey('a\\b.jpg'), null);
  assert.equal(publicKey('x'.repeat(901)), null);
  assert.equal(publicKey('an-vinh/library/x.JPG'), 'an-vinh/library/x.JPG');
  assert.equal(typeOf('a/b.JPG'), 'image/jpeg');
  assert.equal(typeOf('a.jpg/b'), null);
});
