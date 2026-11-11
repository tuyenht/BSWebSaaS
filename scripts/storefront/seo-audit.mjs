#!/usr/bin/env node
// Structure and SEO audit of a live storefront, read from its public sitemap (any site, any page type).
// Usage: node scripts/storefront/seo-audit.mjs https://example.com [out.json]
// Exits 1 when a page breaks a rule in .claude/rules/storefront-seo.md that HTML alone can show.
import { writeFileSync } from 'node:fs';

const host = (process.argv[2] || '').replace(/\/$/, '');
if (!/^https?:\/\//.test(host)) {
    console.error('usage: seo-audit.mjs https://host [out.json]');
    process.exit(2);
}
const UA = { 'User-Agent': 'Mozilla/5.0 (compatible; bs-seo-audit)' };
const get = async (url) => {
    const res = await fetch(url, { headers: UA, redirect: 'manual' });
    return { status: res.status, headers: res.headers, body: await res.text() };
};
const all = (re, s) => [...s.matchAll(re)];

const sitemap = await get(`${host}/sitemap.xml`);
let urls = [];
const entries = [];
for (const loc of all(/<loc>([^<]+)<\/loc>/g, sitemap.body).map((m) => m[1])) {
    if (loc.endsWith('.xml')) {
        const child = await get(loc);
        for (const u of all(/<url>([\s\S]*?)<\/url>/g, child.body)) entries.push(u[1]);
    }
}
if (!entries.length) for (const u of all(/<url>([\s\S]*?)<\/url>/g, sitemap.body)) entries.push(u[1]);
urls = entries.map((e) => e.match(/<loc>([^<]+)<\/loc>/)[1]);
const noLastmod = entries.filter((e) => !e.includes('<lastmod>')).length;
const withImage = entries.filter((e) => e.includes('<image:image>')).length;

const pages = [];
for (const url of urls) {
    const r = await get(url);
    const h = r.body;
    const head = h.slice(0, h.indexOf('</head>'));
    const headings = all(/<h([1-6])[\s>]/g, h).map((m) => Number(m[1]));
    const ld = [];
    let ldBroken = 0;
    for (const m of all(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g, h)) {
        try {
            const d = JSON.parse(m[1]);
            const nodes = Array.isArray(d) ? d : d['@graph'] || [d];
            for (const n of nodes) ld.push({ type: n['@type'], id: n['@id'] || null });
        } catch {
            ldBroken++;
        }
    }
    const robots = (head.match(/<meta name="robots" content="([^"]*)"/) || [])[1] || '';
    pages.push({
        url: url.replace(host, '') || '/',
        status: r.status,
        title: ((h.match(/<title>\s*([\s\S]*?)\s*<\/title>/) || [])[1] || '').trim(),
        description: (head.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '',
        canonical: (head.match(/<link rel="canonical" href="([^"]*)"/) || [])[1] || '',
        lang: (h.match(/<html[^>]*\blang="([^"]*)"/) || [])[1] || '',
        h1: headings.filter((n) => n === 1).length,
        jumps: headings.slice(1).filter((n, i) => n > headings[i] + 1).length,
        landmarks: Object.fromEntries(['header', 'nav', 'main', 'footer'].map((t) => [t, all(new RegExp(`<${t}[\\s>]`, 'g'), h).length])),
        skip: /<a[^>]+href="#[^"]+"[^>]*>\s*[^<]{2,}/.test(h.slice(0, h.indexOf('<header') > 0 ? h.indexOf('<header') : 4000)),
        current: /aria-current="page"/.test(h),
        imgNoAlt: all(/<img(?![^>]*\balt=)[^>]*>/g, h).length,
        imgNoSize: all(/<img(?![^>]*\bwidth=)[^>]*>/g, h).length,
        ld,
        ldBroken,
        noindex: /noindex/.test(robots),
        etag: r.headers.has('etag') || r.headers.has('last-modified'),
    });
}

const count = (key) => pages.reduce((m, p) => m.set(p[key], (m.get(p[key]) || 0) + 1), new Map());
const titles = count('title');
const descs = count('description');
const rules = {
    'status is not 200': (p) => p.status !== 200,
    'noindex page listed in the sitemap': (p) => p.noindex,
    'canonical is not the page itself': (p) => p.canonical.replace(/\/$/, '') !== (host + p.url).replace(/\/$/, ''),
    'no lang': (p) => !p.lang,
    'not exactly one h1': (p) => p.h1 !== 1,
    'heading level skipped': (p) => p.jumps > 0,
    'missing header, main or footer, or more than one main': (p) => !p.landmarks.header || p.landmarks.main !== 1 || !p.landmarks.footer,
    'no skip link before the header': (p) => !p.skip,
    'image without alt': (p) => p.imgNoAlt > 0,
    'title shared with another page': (p) => titles.get(p.title) > 1,
    'empty description, or one shared with another page': (p) => !p.description || descs.get(p.description) > 1,
    'broken JSON-LD block': (p) => p.ldBroken > 0,
    'BreadcrumbList on the home page': (p) => p.url === '/' && p.ld.some((n) => n.type === 'BreadcrumbList'),
    'full Organization node away from the home page without an @id': (p) => p.url !== '/' && p.ld.some((n) => n.type === 'Organization' && !n.id),
};
const failures = [];
for (const [rule, test] of Object.entries(rules)) {
    const hit = pages.filter(test).map((p) => p.url);
    if (hit.length) failures.push({ rule, pages: hit.length, sample: hit.slice(0, 8) });
}
const types = {};
for (const p of pages) for (const n of p.ld) types[n.type] = (types[n.type] || 0) + 1;
const info = {
    pages: pages.length,
    sitemapWithoutLastmod: noLastmod,
    sitemapWithImage: withImage,
    pagesWithEtagOrLastModified: pages.filter((p) => p.etag).length,
    imagesWithoutSize: pages.reduce((n, p) => n + p.imgNoSize, 0),
    jsonLdTypes: types,
};
console.log(JSON.stringify({ info, failures }, null, 1));
if (process.argv[3]) writeFileSync(process.argv[3], JSON.stringify({ host, info, failures, pages }, null, 1));
process.exit(failures.length ? 1 : 0);
