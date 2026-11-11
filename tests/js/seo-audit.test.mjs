// The pure checks of the live SEO audit (scripts/storefront/seo-audit.mjs), without a network:
// `node --test tests/js/seo-audit.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { firstImageLazy, hreflangIssues, mixedContent, pageFacts, placeholderHits, sitemapIssues, validHreflang } from '../../scripts/storefront/seo-audit.mjs';

const page = (url, alternates, canonical = url) => ({ url, canonical, alternates: alternates.map(([lang, href]) => ({ lang, href })) });
const A = 'https://x.test/';
const B = 'https://x.test/en';

test('hreflang: a full mesh with x-default is clean', () => {
    const set = [['vi', A], ['en', B], ['x-default', A]];
    const r = hreflangIssues([page(A, set), page(B, set)]);
    assert.deepEqual(r, { noSelf: [], noReturn: [], badCode: [], noXDefault: [] });
});

test('hreflang: missing self link, missing return link, bad code, no x-default', () => {
    const r = hreflangIssues([
        page(A, [['en', B], ['fr', 'https://x.test/fr']]),
        page(B, [['en', B]]),
    ]);
    assert.deepEqual(r.noSelf, [A]);
    assert.deepEqual(r.noReturn, [A]);
    assert.deepEqual(r.noXDefault, [A]);
    const bad = hreflangIssues([page(A, [['english', A]])]);
    assert.deepEqual(bad.badCode, [A]);
});

test('hreflang: trailing slash does not break the self or return match', () => {
    const r = hreflangIssues([page(`${B}/`, [['en', B], ['vi', A]], `${B}/`), page(A, [['vi', A], ['en', `${B}/`]])]);
    assert.deepEqual(r.noSelf, []);
    assert.deepEqual(r.noReturn, []);
});

test('hreflang: codes', () => {
    for (const c of ['vi', 'en-US', 'zh-Hant-TW', 'x-default']) {
        assert.ok(validHreflang(c), c);
    }
    for (const c of ['english', 'en_US', '', 'e']) {
        assert.ok(!validHreflang(c), c);
    }
});

const xml = (n, lastmod) => Array.from({ length: n }, (_, i) => ({ loc: `https://x.test/p${i}`, lastmod: lastmod(i) }));

test('sitemap: a clean file', () => {
    const r = sitemapIssues([{ url: 's', bytes: 100, entries: xml(12, (i) => `2026-01-${String(i + 1).padStart(2, '0')}`) }], 'x.test', Date.parse('2026-06-01'));
    assert.deepEqual(r, { tooMany: [], tooBig: [], duplicates: [], otherHost: [], futureLastmod: [], uniformLastmod: false });
});

test('sitemap: limits, duplicates, other host, future and uniform lastmod', () => {
    const entries = [...xml(10, () => '2026-01-01'), { loc: 'https://x.test/p1/', lastmod: '2026-01-01' }, { loc: 'https://evil.test/a', lastmod: '2099-01-01' }];
    const r = sitemapIssues([{ url: 's', bytes: 51 * 1024 * 1024, entries }], 'x.test', Date.parse('2026-06-01'));
    assert.deepEqual(r.tooBig, ['s']);
    assert.deepEqual(r.duplicates, ['https://x.test/p1/']);
    assert.deepEqual(r.otherHost, ['https://evil.test/a']);
    assert.deepEqual(r.futureLastmod, ['https://evil.test/a']);
    assert.equal(r.uniformLastmod, false); // the 2099 value differs
    const same = sitemapIssues([{ url: 's', bytes: 1, entries: xml(10, () => '2026-01-01') }], 'x.test', Date.parse('2026-06-01'));
    assert.equal(same.uniformLastmod, true);
    const few = sitemapIssues([{ url: 's', bytes: 1, entries: xml(9, () => '2026-01-01') }], 'x.test', Date.parse('2026-06-01'));
    assert.equal(few.uniformLastmod, false);
    const many = sitemapIssues([{ url: 's', bytes: 1, entries: xml(50001, () => '') }], 'x.test');
    assert.deepEqual(many.tooMany, ['s']);
});

test('placeholder text: found in visible text only', () => {
    assert.deepEqual(placeholderHits('<body><p>Lorem ipsum dolor</p><p>Price: NaN</p></body>'), ['lorem ipsum', 'NaN']);
    assert.deepEqual(placeholderHits('<body><p>Hello {{ name }}</p><p>TODO fix</p><p>undefined</p></body>'), ['TODO', '{{', 'undefined']);
    assert.deepEqual(placeholderHits('<head><title>TODO</title></head><body><script>var a = undefined; {{x}}</script><!-- TODO --><p>Fine</p></body>'), []);
});

test('first content image: lazy is flagged', () => {
    assert.equal(firstImageLazy('<header><img src="logo.png"></header><main><img src="a.jpg" loading="lazy"></main>'), true);
    assert.equal(firstImageLazy('<main><img src="a.jpg" loading="eager"><img src="b.jpg" loading="lazy"></main>'), false);
    assert.equal(firstImageLazy('<p>no images</p>'), false);
});

test('mixed content: only resources on an https page', () => {
    const h = '<img src="http://a.test/x.png"><a href="http://b.test/">l</a><link rel="stylesheet" href="http://c.test/s.css"><script src="https://d.test/j.js"></script>';
    assert.deepEqual(mixedContent(h, 'https://x.test/'), ['http://a.test/x.png', 'http://c.test/s.css']);
    assert.deepEqual(mixedContent(h, 'http://x.test/'), []);
});

test('page facts: duplicates, og, viewport, long title, alternates', () => {
    const h = `<html><head><title>${'T'.repeat(61)}</title>
<link rel="canonical" href="/a"><link rel="canonical" href="/b">
<meta name="description" content="one"><meta name="description" content="two">
<meta property="og:title" content="t"><link rel="alternate" hreflang="en" href="/en">
</head><body><main></main></body></html>`;
    const f = pageFacts(h, 'https://x.test/a');
    assert.equal(f.canonicalCount, 2);
    assert.equal(f.descriptionCount, 2);
    assert.deepEqual(f.ogMissing, ['og:image', 'og:url']);
    assert.equal(f.viewport, false);
    assert.equal(f.titleLong, true);
    assert.deepEqual(f.alternates, [{ lang: 'en', href: 'https://x.test/en' }]);
});
