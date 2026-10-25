// Run: node --test deploy/media-worker/test/worker.test.js
// The host must decide what it serves from the key, never from what an uploader stored in R2.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';
import { publicKey, typeOf } from '../src/keys.js';

// An R2 stand-in whose objects carry whatever metadata the uploader chose.
function env(objects) {
  return {
    MEDIA: {
      async get(key) {
        if (!(key in objects)) return null;
        return {
          body: objects[key],
          httpEtag: '"e1"',
          writeHttpMetadata(h) {
            h.set('Content-Type', 'text/html');
          },
        };
      },
    },
  };
}

const get = (path, e, headers = {}) => worker.fetch(new Request(`https://files.example.test${path}`, { headers }), e);

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

test('writes are refused', async () => {
  const res = await worker.fetch(new Request('https://files.example.test/a/x.jpg', { method: 'PUT', body: 'x' }), env({}));
  assert.equal(res.status, 405);
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
