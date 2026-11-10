// Run: node --test deploy/media-worker/test/worker.test.js
// The host must decide what it serves from the key, never from what an uploader stored in R2.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';
import { publicKey, typeOf } from '../src/keys.js';

// An R2 stand-in whose objects carry whatever metadata the uploader chose. A ranged get answers the
// first two bytes (the only range these tests ask for), as R2 does with { offset, length }.
function env(objects) {
  const meta = (key) => ({
    size: objects[key].length,
    httpEtag: '"e1"',
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
        const ranged = opts.range instanceof Headers && opts.range.has('Range');
        return {
          ...meta(key),
          body: ranged ? objects[key].slice(0, 2) : objects[key],
          range: ranged ? { offset: 0, length: 2 } : undefined,
        };
      },
    },
  };
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
  assert.equal(res.headers.get('Content-Disposition'), 'attachment');
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
  globalThis.fetch = async () => new Response('img', { headers: { 'Content-Type': 'image/webp', 'Set-Cookie': 'a=b' } });
  try {
    const res = await get('/a/library/x-1a2b.jpg?w=320', env({}), { Accept: 'image/webp' });
    assert.equal(res.headers.get('Content-Type'), 'image/webp');
    assert.equal(res.headers.get('Set-Cookie'), null);
    assert.equal(res.headers.get('X-Content-Type-Options'), 'nosniff');
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
    }
  } finally {
    globalThis.fetch = realFetch;
  }
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
