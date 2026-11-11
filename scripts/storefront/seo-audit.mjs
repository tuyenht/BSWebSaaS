#!/usr/bin/env node
// Structure and SEO audit of a live storefront, read from its public sitemap (any site, any page type).
// Usage: node scripts/storefront/seo-audit.mjs https://example.com [out.json]
// Exit 0 = clean · 1 = a page breaks a rule in .claude/rules/storefront-seo.md that HTML alone can show
// · 2 = the audit could not run (bad argument, sitemap missing or empty, network failure).
import { writeFileSync } from 'node:fs';

const stop = (msg) => {
    console.error(`seo-audit: ${msg}`);
    process.exit(2);
};
let origin;
try {
    origin = new URL(process.argv[2] || '').origin;
} catch {
    stop('usage: seo-audit.mjs https://host [out.json]');
}

const UA = { 'User-Agent': 'Mozilla/5.0 (compatible; bs-seo-audit)' };
const get = async (url) => {
    const res = await fetch(url, { headers: UA, redirect: 'manual', signal: AbortSignal.timeout(30000) });
    return { status: res.status, headers: res.headers, body: await res.text() };
};
const unescape = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();
const all = (re, s) => [...s.matchAll(re)];
// Attributes of every <name …> tag, in any order and with either quote style.
const tags = (html, name) =>
    all(new RegExp(`<${name}\\b([^>]*)>`, 'gi'), html).map((m) => {
        const attrs = { _index: m.index };
        for (const a of all(/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g, m[1])) {
            attrs[a[1].toLowerCase()] = unescape(a[2] ?? a[3] ?? a[4] ?? '');
        }
        return attrs;
    });
// A URL's identity for comparisons: origin + path without a trailing slash (the home page stays "/").
const key = (u, base) => {
    const x = new URL(u, base);
    return x.origin + (x.pathname.replace(/\/+$/, '') || '/') + x.search;
};

// Sitemap: a <sitemapindex> lists child sitemaps, a <urlset> lists pages.
const urls = [];
const entries = [];
const readSitemap = async (url, depth) => {
    const r = await get(url);
    if (r.status !== 200) {
        stop(`${url} answered ${r.status}`);
    }
    if (/<sitemapindex[\s>]/.test(r.body)) {
        if (depth > 2) {
            stop(`${url}: sitemap indexes nested too deep`);
        }
        for (const m of all(/<sitemap>[\s\S]*?<loc>([^<]+)<\/loc>/g, r.body)) {
            await readSitemap(unescape(m[1]), depth + 1);
        }
        return;
    }
    for (const m of all(/<url>([\s\S]*?)<\/url>/g, r.body)) {
        const loc = m[1].match(/<loc>([^<]+)<\/loc>/);
        if (loc) {
            urls.push(unescape(loc[1]));
            entries.push(m[1]);
        }
    }
};
try {
    await readSitemap(`${origin}/sitemap.xml`, 0);
} catch (e) {
    stop(`sitemap: ${e.message}`);
}
if (!urls.length) {
    stop('the sitemap lists no pages');
}

const NODE_ONLY = new Set(['@type', '@id', 'name', 'url']);
const pages = [];
for (const url of urls) {
    const path = new URL(url).pathname || '/';
    let r;
    try {
        r = await get(url);
    } catch (e) {
        pages.push({ url: path, status: 0, error: e.message });
        continue;
    }
    const h = r.body;
    const headEnd = h.search(/<\/head>/i);
    const head = headEnd > 0 ? h.slice(0, headEnd) : '';
    const meta = (name) => (tags(head, 'meta').find((t) => t.name === name) || {}).content || '';
    const headings = all(/<h([1-6])[\s>]/gi, h).map((m) => Number(m[1]));
    const ld = [];
    let ldBroken = 0;
    for (const m of all(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi, h)) {
        if (!/type\s*=\s*["']?application\/ld\+json/i.test(m[1])) {
            continue;
        }
        try {
            const d = JSON.parse(m[2]);
            for (const n of Array.isArray(d) ? d : d['@graph'] || [d]) {
                ld.push({ type: n['@type'], id: n['@id'] || null, full: Object.keys(n).some((k) => !k.startsWith('@context') && !NODE_ONLY.has(k)) });
            }
        } catch {
            ldBroken++;
        }
    }
    const ids = new Set(tags(h, '[a-z0-9-]+').map((t) => t.id).filter(Boolean));
    const mainAt = h.search(/<main[\s>]/i);
    const firstLink = tags(h.slice(headEnd > 0 ? headEnd : 0, mainAt > 0 ? mainAt : undefined), 'a').find((t) => (t.href || '').startsWith('#'));
    const robots = `${meta('robots')} ${r.headers.get('x-robots-tag') || ''}`;
    const canonical = (tags(head, 'link').find((t) => (t.rel || '').split(/\s+/).includes('canonical')) || {}).href || '';
    pages.push({
        url: path,
        status: r.status,
        title: unescape(((h.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '').replace(/\s+/g, ' ')),
        description: meta('description'),
        canonicalOk: canonical !== '' && key(canonical, url) === key(url),
        lang: (tags(h, 'html')[0] || {}).lang || '',
        h1: headings.filter((n) => n === 1).length,
        jumps: headings.slice(1).filter((n, i) => n > headings[i] + 1).length,
        main: all(/<main[\s>]/gi, h).length,
        header: /<header[\s>]/i.test(h),
        footer: /<footer[\s>]/i.test(h),
        navNoName: tags(h, 'nav').filter((t) => !t['aria-label'] && !t['aria-labelledby']).length,
        skip: Boolean(firstLink && ids.has(firstLink.href.slice(1))),
        current: /aria-current\s*=\s*["']?page/i.test(h),
        imgNoAlt: tags(h, 'img').filter((t) => !('alt' in t)).length,
        imgNoSize: tags(h, 'img').filter((t) => !('width' in t && 'height' in t)).length,
        ld,
        ldBroken,
        noindex: /noindex/i.test(robots),
        etag: r.headers.has('etag') || r.headers.has('last-modified'),
    });
}

const ok = pages.filter((p) => p.status === 200);
const count = (k) => ok.reduce((m, p) => m.set(p[k], (m.get(p[k]) || 0) + 1), new Map());
const titles = count('title');
const descs = count('description');
const rules = {
    'status is not 200 (or the page did not load)': (p) => p.status !== 200,
    'noindex page listed in the sitemap': (p) => p.noindex,
    'canonical missing or not the page itself': (p) => !p.canonicalOk,
    'no lang': (p) => !p.lang,
    'not exactly one h1': (p) => p.h1 !== 1,
    'heading level skipped': (p) => p.jumps > 0,
    'no header or footer, or not exactly one main': (p) => !p.header || !p.footer || p.main !== 1,
    'nav without an accessible name': (p) => p.navNoName > 0,
    'no skip link to an existing id before main': (p) => !p.skip,
    'image without alt': (p) => p.imgNoAlt > 0,
    'empty title, or one shared with another page': (p) => !p.title || titles.get(p.title) > 1,
    'empty description, or one shared with another page': (p) => !p.description || descs.get(p.description) > 1,
    'broken JSON-LD block': (p) => p.ldBroken > 0,
    'BreadcrumbList on the home page': (p) => p.url === '/' && p.ld.some((n) => n.type === 'BreadcrumbList'),
    'full Organization node away from the home page (point to it by @id)': (p) => p.url !== '/' && p.ld.some((n) => n.type === 'Organization' && n.full),
};
const failures = [];
for (const [rule, test] of Object.entries(rules)) {
    const pool = rule.startsWith('status') ? pages : ok;
    const hit = pool.filter(test).map((p) => p.url);
    if (hit.length) {
        failures.push({ rule, pages: hit.length, sample: hit.slice(0, 8) });
    }
}
const types = {};
for (const p of ok) {
    for (const n of p.ld) {
        types[n.type] = (types[n.type] || 0) + 1;
    }
}
const info = {
    pages: pages.length,
    sitemapWithoutLastmod: entries.filter((e) => !e.includes('<lastmod>')).length,
    sitemapWithImage: entries.filter((e) => e.includes('<image:image>')).length,
    pagesWithEtagOrLastModified: ok.filter((p) => p.etag).length,
    imagesWithoutSize: ok.reduce((n, p) => n + p.imgNoSize, 0),
    jsonLdTypes: types,
};
console.log(JSON.stringify({ info, failures }, null, 1));
if (process.argv[3]) {
    writeFileSync(process.argv[3], JSON.stringify({ origin, info, failures, pages }, null, 1));
}
process.exit(failures.length ? 1 : 0);
