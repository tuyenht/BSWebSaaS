// Run: node --test deploy/media-worker/test/worker.test.js
// The host must decide what it serves from the key, never from what an uploader stored in R2.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';
import { legacyPrefixes, legacyTarget, publicKey, typeOf } from '../src/keys.js';

// An R2 stand-in whose objects carry whatever metadata the uploader chose (text/html, which the
// Worker must never pass on). Like R2, get() reads the Range and If-* headers itself: a failed
// precondition gives the object without a body, one byte range gives {offset, length}, a range that
// starts past the end (or several ranges) throws, and a missing key is null.
const ETAG = '"e1"';
const UPLOADED = new Date('2026-11-01T00:00:00Z');

// `etag` stands in for R2's MD5 of the bytes: equal bodies, equal etags.
function env(objects, vars = {}) {
  const heads = [];
  const meta = (key) => ({
    size: objects[key].length,
    etag: `md5-of-${objects[key]}`,
    httpEtag: ETAG,
    uploaded: UPLOADED,
    writeHttpMetadata(h) {
      h.set('Content-Type', 'text/html');
    },
  });
  return {
    ...vars,
    heads,
    MEDIA: {
      async head(key) {
        heads.push(key);
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

// --- Legacy prefixes: a website's files from before website codes, moved under its code ---------------

const LEGACY = { LEGACY_PREFIXES: { 'an-vinh/': 'zpvjzx', 'co-khi-chinh-xac-an-vinh/library/': 'zpvjzx' } };

test('a legacy key with an identical copy under the code answers 301 there, kept five minutes', async () => {
  const e = env(
    {
      'co-khi-chinh-xac-an-vinh/library/may-tien-3f9a.jpg': 'img',
      'an-vinh/hero/banner-1a2b.webp': 'hero',
      'zpvjzx/may-tien-3f9a.jpg': 'img',
      'zpvjzx/banner-1a2b.webp': 'hero',
    },
    LEGACY,
  );
  for (const [from, to] of [
    ['/co-khi-chinh-xac-an-vinh/library/may-tien-3f9a.jpg', '/zpvjzx/may-tien-3f9a.jpg'],
    ['/an-vinh/hero/banner-1a2b.webp', '/zpvjzx/banner-1a2b.webp'],
  ]) {
    const res = await get(from, e);
    assert.equal(res.status, 301, from);
    assert.equal(res.headers.get('Location'), to, from);
    assert.equal(res.headers.get('Cache-Control'), 'public, max-age=300', from);
    assert.equal(await res.text(), '', from);
    assertSafe(res, `301 ${from}`);
  }
  const head = await get('/an-vinh/hero/banner-1a2b.webp', e, {}, 'HEAD');
  assert.equal(head.status, 301);
  assert.equal(head.headers.get('Location'), '/zpvjzx/banner-1a2b.webp');
});

test('a legacy key with no copy under the code is served as before, never sent to a 404', async () => {
  // A shared demo seed file that only exists under its old folder.
  const e = env({ 'an-vinh/seed/xuong-0a1b.jpg': 'seed' }, LEGACY);
  const res = await get('/an-vinh/seed/xuong-0a1b.jpg', e);
  assert.equal(res.status, 200);
  assert.equal(await res.text(), 'seed');
  assert.match(res.headers.get('Cache-Control'), /immutable/);
  assert.equal(res.headers.get('Location'), null);
  assert.deepEqual(e.heads, ['an-vinh/seed/xuong-0a1b.jpg', 'zpvjzx/xuong-0a1b.jpg']);
});

test('a made-up name under an old folder costs one R2 check, not two', async () => {
  const e = env({ 'zpvjzx/x-1a2b.jpg': 'x' }, LEGACY);
  assert.equal((await get('/an-vinh/made-up-9z9z.jpg', e)).status, 404);
  assert.deepEqual(e.heads.slice(0, 1), ['an-vinh/made-up-9z9z.jpg']);
  assert.ok(!e.heads.includes('zpvjzx/made-up-9z9z.jpg'));
});

test('an R2 fault during the check serves the old object instead of failing', async () => {
  const e = env({ 'an-vinh/x-1a2b.jpg': 'x', 'zpvjzx/x-1a2b.jpg': 'x' }, LEGACY);
  const realHead = e.MEDIA.head;
  let calls = 0;
  e.MEDIA.head = async (key) => {
    calls += 1;
    if (calls === 2) throw new Error('R2 unavailable');
    return realHead(key);
  };
  const res = await get('/an-vinh/x-1a2b.jpg', e);
  assert.equal(res.status, 200);
  assert.equal(await res.text(), 'x');
});

test('a copy with other bytes under the same name is not a redirect target', async () => {
  // Two old folders held the same name; media:rekey-site gave the second one a longer hash.
  // Same length, so only the ETag tells them apart.
  const e = env({ 'an-vinh/x-1a2b.jpg': 'other', 'zpvjzx/x-1a2b.jpg': 'first' }, LEGACY);
  const res = await get('/an-vinh/x-1a2b.jpg', e);
  assert.equal(res.status, 200);
  assert.equal(await res.text(), 'other');
});

test('a legacy key whose old object is gone stays a 404, even when the code holds the name', async () => {
  const e = env({ 'zpvjzx/x-1a2b.jpg': 'x' }, LEGACY);
  const res = await get('/an-vinh/x-1a2b.jpg', e);
  assert.equal(res.status, 404);
  assertSafe(res, 'legacy 404');
});

test('the width and the rest of the query go with the redirect; a bad width is still a 400', async () => {
  const e = env({ 'an-vinh/x-1a2b.jpg': 'x', 'zpvjzx/x-1a2b.jpg': 'x', 'an-vinh/bang-gia-1a2b.pdf': '%PDF', 'zpvjzx/bang-gia-1a2b.pdf': '%PDF' }, LEGACY);
  const res = await get('/an-vinh/x-1a2b.jpg?w=320', e, { Accept: 'image/avif' });
  assert.equal(res.status, 301);
  assert.equal(res.headers.get('Location'), '/zpvjzx/x-1a2b.jpg?w=320');
  assert.equal((await get('/an-vinh/x-1a2b.jpg?w=640&v=2', e)).headers.get('Location'), '/zpvjzx/x-1a2b.jpg?w=640&v=2');
  assert.equal((await get('/an-vinh/bang-gia-1a2b.pdf?w=320', e)).headers.get('Location'), '/zpvjzx/bang-gia-1a2b.pdf?w=320');
  const bad = await get('/an-vinh/x-1a2b.jpg?w=999', e);
  assert.equal(bad.status, 400);
  assert.equal(bad.headers.get('Location'), null);
});

test('the Location is a path on this host with each segment escaped', async () => {
  const e = env({ 'an-vinh/a b-1a2b.jpg': 'x', 'zpvjzx/a b-1a2b.jpg': 'x' }, LEGACY);
  const res = await get('/an-vinh/a%20b-1a2b.jpg', e);
  assert.equal(res.headers.get('Location'), '/zpvjzx/a%20b-1a2b.jpg');
});

test('private paths, the internal path and unknown types are never redirected', async () => {
  const e = env(
    {
      'an-vinh/x-1a2b.jpg': 'x',
      'zpvjzx/x-1a2b.jpg': 'x',
      'an-vinh/page.html': '<script>',
      'zpvjzx/page.html': '<script>',
      '_incoming/an-vinh/x-1a2b.jpg': 'x',
    },
    { LEGACY_PREFIXES: { ...LEGACY.LEGACY_PREFIXES, '_incoming/': 'zpvjzx' } },
  );
  assert.equal((await get('/_incoming/an-vinh/x-1a2b.jpg', e)).status, 404);
  const internal = await get('/__origin/an-vinh/x-1a2b.jpg', e);
  assert.equal(internal.status, 200, 'cf.image reads the old key itself');
  assert.equal(internal.headers.get('Location'), null);
  assert.equal((await get('/an-vinh/page.html', e)).status, 404);
  assert.deepEqual(e.heads, [], 'no R2 check for a request that is refused or internal');
});

test('without the map, or with a bad line, nothing is redirected', async () => {
  const objects = { 'an-vinh/x-1a2b.jpg': 'x', 'zpvjzx/x-1a2b.jpg': 'x', 'an-vinhx/y-1a2b.jpg': 'y', 'zpvjzx/y-1a2b.jpg': 'y' };
  assert.equal((await get('/an-vinh/x-1a2b.jpg', env(objects))).status, 200);
  for (const map of ['not json', '["an-vinh/"]', { 'an-vinh/': 'ZPVJZX' }, { 'an-vinh/': 'zpv' }, { 'an-vinh': 'zpvjzx' }, { '../': 'zpvjzx' }]) {
    const res = await get('/an-vinh/x-1a2b.jpg', env(objects, { LEGACY_PREFIXES: map }));
    assert.equal(res.status, 200, JSON.stringify(map));
  }
  // The prefix is a folder: "an-vinh/" does not hold "an-vinhx/".
  assert.equal((await get('/an-vinhx/y-1a2b.jpg', env(objects, LEGACY))).status, 200);
  // The map may also arrive as a JSON string (a var set as text).
  const str = await get('/an-vinh/x-1a2b.jpg', env(objects, { LEGACY_PREFIXES: JSON.stringify(LEGACY.LEGACY_PREFIXES) }));
  assert.equal(str.status, 301);
});

test('the legacy check is kept in the edge cache five minutes, found or not', async () => {
  const store = new Map();
  const realCaches = globalThis.caches;
  globalThis.caches = {
    default: {
      async match(url) {
        return store.has(url) ? new Response(store.get(url).body) : undefined;
      },
      async put(url, res) {
        store.set(url, { body: await res.text(), cacheControl: res.headers.get('Cache-Control') });
      },
    },
  };
  try {
    const e = env({ 'an-vinh/x-1a2b.jpg': 'x', 'zpvjzx/x-1a2b.jpg': 'x', 'an-vinh/seed-1a2b.jpg': 's' }, LEGACY);
    const waits = [];
    const ctx = { waitUntil: (p) => waits.push(p) };
    const fetchIt = (path) => worker.fetch(new Request(`https://files.example.test${path}`), e, ctx);

    assert.equal((await fetchIt('/an-vinh/x-1a2b.jpg')).status, 301);
    assert.equal((await fetchIt('/an-vinh/seed-1a2b.jpg')).status, 200);
    await Promise.all(waits);
    assert.equal(e.heads.length, 4);
    assert.deepEqual(store.get('https://files.example.test/__legacy/an-vinh%2Fx-1a2b.jpg'), { body: 'zpvjzx/x-1a2b.jpg', cacheControl: 'max-age=300' });
    assert.deepEqual(store.get('https://files.example.test/__legacy/an-vinh%2Fseed-1a2b.jpg'), { body: '', cacheControl: 'max-age=300' });

    // Answered from the cache: no further R2 check, same outcome.
    assert.equal((await fetchIt('/an-vinh/x-1a2b.jpg?w=320')).headers.get('Location'), '/zpvjzx/x-1a2b.jpg?w=320');
    assert.equal((await fetchIt('/an-vinh/seed-1a2b.jpg')).status, 200);
    assert.equal(e.heads.length, 4);

    // A cached answer for another target (the map changed since) is not trusted as a redirect.
    store.set('https://files.example.test/__legacy/an-vinh%2Fx-1a2b.jpg', { body: 'abcdef/x-1a2b.jpg' });
    assert.equal((await fetchIt('/an-vinh/x-1a2b.jpg')).status, 200);
    // The check's own path is never public.
    assert.equal((await fetchIt('/__legacy/an-vinh%2Fx-1a2b.jpg')).status, 404);
  } finally {
    globalThis.caches = realCaches;
  }
});

test('legacy map rules', () => {
  const prefixes = legacyPrefixes({
    'a/': 'zpvjzx',
    'a/deep/': '23456z',
    '_x/': 'zpvjzx',
    'b/./': 'zpvjzx',
    'c//': 'zpvjzx',
    // A code folder is never an old folder: to itself (a loop) or into another website's folder.
    'zpvjzx/': 'zpvjzx',
    'abcdef/': 'zpvjzx',
  });
  assert.deepEqual(prefixes, [['a/deep/', '23456z'], ['a/', 'zpvjzx']]);
  assert.equal(legacyTarget('a/deep/f/x.jpg', prefixes), '23456z/x.jpg', 'the longest prefix wins');
  assert.equal(legacyTarget('a/x.jpg', prefixes), 'zpvjzx/x.jpg');
  assert.equal(legacyTarget('a/', prefixes), null);
  assert.equal(legacyTarget('ab/x.jpg', prefixes), null);
  assert.equal(legacyTarget('zpvjzx/x.jpg', [['zpvjzx/', 'zpvjzx']]), null, 'never to itself');
  assert.deepEqual(legacyPrefixes(undefined), []);
  assert.deepEqual(legacyPrefixes(null), []);
});
