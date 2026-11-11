#!/usr/bin/env node
// Structure and SEO audit of a live storefront, read from its public sitemap (any site, any page type).
// Usage: node scripts/storefront/seo-audit.mjs https://example.com [out.json]
// Exit 0 = clean · 1 = a page or the site breaks a rule in .claude/rules/storefront-seo.md that HTML alone can show
// · 2 = the audit could not run (bad argument, sitemap missing or empty, network failure).
// `warnings` are advice (heuristics or limits Google does not state as rules) and never change the exit code.
// Requests are sequential (one at a time) so a live site is never hammered.
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export const unescape = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();
export const all = (re, s) => [...s.matchAll(re)];
// Attributes of every <name …> tag, in any order and with either quote style.
export const tags = (html, name) =>
    all(new RegExp(`<${name}\\b([^>]*)>`, 'gi'), html).map((m) => {
        const attrs = { _index: m.index };
        for (const a of all(/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g, m[1])) {
            attrs[a[1].toLowerCase()] = unescape(a[2] ?? a[3] ?? a[4] ?? '');
        }
        return attrs;
    });
// A URL's identity for comparisons: origin + path without a trailing slash (the home page stays "/").
export const key = (u, base) => {
    const x = new URL(u, base);
    return x.origin + (x.pathname.replace(/\/+$/, '') || '/') + x.search;
};

// ---- Pure checks (no network; unit-tested in tests/js/seo-audit.test.mjs) ----

// Language-region code as Google documents it (ISO 639-1, optional script, optional region) or x-default.
export const validHreflang = (code) => /^(x-default|[a-z]{2,3}(-[a-z]{4})?(-([a-z]{2}|\d{3}))?)$/i.test(code || '');

// pages: [{ url, canonical, alternates: [{ lang, href }] }] with absolute URLs. A→B needs B→A when B is among the pages.
export const hreflangIssues = (pages) => {
    const byKey = new Map(pages.map((p) => [key(p.url), p]));
    const out = { noSelf: [], noReturn: [], badCode: [], noXDefault: [] };
    for (const p of pages) {
        if (!p.alternates.length) {
            continue;
        }
        const self = key(p.canonical || p.url, p.url);
        const keys = p.alternates.map((a) => key(a.href, p.url));
        if (!keys.includes(self)) {
            out.noSelf.push(p.url);
        }
        if (p.alternates.some((a) => !validHreflang(a.lang))) {
            out.badCode.push(p.url);
        }
        const langs = p.alternates.map((a) => a.lang.toLowerCase());
        if (langs.filter((l) => l !== 'x-default').length > 1 && !langs.includes('x-default')) {
            out.noXDefault.push(p.url);
        }
        const mine = [key(p.url), self];
        const missing = keys.some((k) => {
            const other = byKey.get(k);
            if (!other || mine.includes(k)) {
                return false;
            }
            return !other.alternates.some((a) => mine.includes(key(a.href, other.url)));
        });
        if (missing) {
            out.noReturn.push(p.url);
        }
    }
    return out;
};

export const SITEMAP_MAX_URLS = 50000;
export const SITEMAP_MAX_BYTES = 50 * 1024 * 1024;

// files: [{ url, bytes, entries: [{ loc, lastmod }] }]; host: the site's hostname.
export const sitemapIssues = (files, host, now = Date.now()) => {
    const out = { tooMany: [], tooBig: [], duplicates: [], otherHost: [], futureLastmod: [], uniformLastmod: false };
    const seen = new Set();
    const lastmods = [];
    for (const f of files) {
        if (f.entries.length > SITEMAP_MAX_URLS) {
            out.tooMany.push(f.url);
        }
        if (f.bytes > SITEMAP_MAX_BYTES) {
            out.tooBig.push(f.url);
        }
        for (const e of f.entries) {
            let k;
            try {
                k = key(e.loc);
                if (new URL(e.loc).hostname !== host) {
                    out.otherHost.push(e.loc);
                }
            } catch {
                out.otherHost.push(e.loc);
                continue;
            }
            if (seen.has(k)) {
                out.duplicates.push(e.loc);
            }
            seen.add(k);
            if (e.lastmod) {
                lastmods.push(e.lastmod);
                const t = Date.parse(e.lastmod);
                // one day of slack: a date-only value written in a time zone ahead of UTC is not "the future"
                if (!Number.isNaN(t) && t > now + 86400000) {
                    out.futureLastmod.push(e.loc);
                }
            }
        }
    }
    out.uniformLastmod = lastmods.length >= 10 && new Set(lastmods).size === 1;
    return out;
};

// Text a visitor can read: no head, scripts, styles, templates, comments or tags.
export const visibleText = (html) => {
    const body = (html.match(/<body\b[\s\S]*$/i) || [html])[0];
    return unescape(
        body
            .replace(/<!--[\s\S]*?-->/g, ' ')
            .replace(/<(script|style|noscript|template|svg|pre|code)\b[\s\S]*?<\/\1>/gi, ' ')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' '),
    );
};
const PLACEHOLDERS = [
    ['lorem ipsum', /lorem ipsum/i],
    ['TODO', /\bTODO\b/],
    ['{{', /\{\{/],
    ['undefined', /\bundefined\b/],
    ['NaN', /\bNaN\b/],
];
export const placeholderHits = (html) => {
    const text = visibleText(html);
    return PLACEHOLDERS.filter(([, re]) => re.test(text)).map(([name]) => name);
};

// The first image inside <main> (or the first on the page) is the likely LCP element: it must not be lazy.
export const firstImageLazy = (html) => {
    const at = html.search(/<main[\s>]/i);
    const img = tags(at > 0 ? html.slice(at) : html, 'img')[0];
    return Boolean(img && (img.loading || '').toLowerCase() === 'lazy');
};

// An absolute URL, or '' when the href cannot be parsed (reported as a rule failure, never a crash).
const abs = (href, base) => {
    try {
        return new URL(href, base).href;
    } catch {
        return '';
    }
};
const lc = (v) => (v || '').toLowerCase();

// http:// resources an https page loads (mixed content). Plain links (<a href>) are not resources.
export const mixedContent = (body, url) => {
    if (!url.startsWith('https://')) {
        return [];
    }
    // Comments and inline script text are not loaded resources (a URL inside a JS string is not a request).
    const html = body.replace(/<!--[\s\S]*?-->/g, ' ').replace(/(<script\b[^>]*>)[\s\S]*?(<\/script>)/gi, '$1$2');
    const found = [];
    for (const t of tags(html, '(?:img|script|iframe|source|video|audio|embed|track)')) {
        if (/^http:\/\//i.test(t.src || '')) {
            found.push(t.src);
        }
    }
    for (const t of tags(html, 'link')) {
        if (/^http:\/\//i.test(t.href || '') && /stylesheet|preload|icon/i.test(t.rel || '')) {
            found.push(t.href);
        }
    }
    return found;
};

export const HTML_LIMIT = 2 * 1024 * 1024;

// Facts read from one page's HTML for the checks that need no other page.
export const pageFacts = (h, url) => {
    const headEnd = h.search(/<\/head>/i);
    const head = headEnd > 0 ? h.slice(0, headEnd) : '';
    const metas = tags(head, 'meta');
    const links = tags(head, 'link');
    const rel = (t, r) => (t.rel || '').toLowerCase().split(/\s+/).includes(r);
    const canon = links.filter((t) => rel(t, 'canonical'));
    const title = unescape(((h.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i) || [])[1] || '').replace(/\s+/g, ' '));
    return {
        canonicalHref: canon[0] ? abs(canon[0].href || url, url) : '',
        canonicalCount: canon.length,
        descriptionCount: metas.filter((t) => lc(t.name) === 'description').length,
        alternates: links.filter((t) => rel(t, 'alternate') && t.hreflang).map((t) => ({ lang: t.hreflang, href: abs(t.href || '', url) })).filter((a) => a.href),
        badHref: links.filter((t) => (rel(t, 'canonical') || (rel(t, 'alternate') && t.hreflang)) && !abs(t.href || '', url)).length,
        ogMissing: ['og:title', 'og:image', 'og:url'].filter((p) => !metas.some((t) => lc(t.property) === p && t.content)),
        viewport: metas.some((t) => lc(t.name) === 'viewport'),
        mixed: mixedContent(h, url).length,
        bytes: Buffer.byteLength(h),
        placeholders: placeholderHits(h),
        lazyFirst: firstImageLazy(h),
        titleLong: title.length > 60,
    };
};

// ---- The audit itself (runs only when this file is started directly) ----

const NODE_ONLY = new Set(['@type', '@id', 'name', 'url']);
const UA = { 'User-Agent': 'Mozilla/5.0 (compatible; bs-seo-audit)' };

const main = async () => {
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
    const host = new URL(origin).hostname;
    const get = async (url) => {
        const res = await fetch(url, { headers: UA, redirect: 'manual', signal: AbortSignal.timeout(30000) });
        return { status: res.status, headers: res.headers, body: await res.text() };
    };

    // Sitemap: a <sitemapindex> lists child sitemaps, a <urlset> lists pages.
    const urls = [];
    const entries = [];
    const sitemapFiles = [];
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
        const file = { url, bytes: Buffer.byteLength(r.body), entries: [] };
        sitemapFiles.push(file);
        for (const m of all(/<url>([\s\S]*?)<\/url>/g, r.body)) {
            const loc = m[1].match(/<loc>([^<]+)<\/loc>/);
            if (loc) {
                urls.push(unescape(loc[1]));
                entries.push(m[1]);
                file.entries.push({ loc: unescape(loc[1]), lastmod: ((m[1].match(/<lastmod>([^<]+)<\/lastmod>/) || [])[1] || '').trim() });
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

    const pages = [];
    const fetched = [];
    for (const url of urls) {
        if (new URL(url).hostname !== host) {
            continue; // reported by the sitemap check; another site is never crawled
        }
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
        const facts = pageFacts(h, url);
        fetched.push({ url, status: r.status, canonical: facts.canonicalHref, alternates: facts.alternates });
        pages.push({
            url: path,
            status: r.status,
            ...facts,
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
        'more than one canonical tag': (p) => p.canonicalCount > 1,
        'more than one meta description tag': (p) => p.descriptionCount > 1,
        'no lang': (p) => !p.lang,
        'no viewport meta': (p) => !p.viewport,
        'not exactly one h1': (p) => p.h1 !== 1,
        'heading level skipped': (p) => p.jumps > 0,
        'no header or footer, or not exactly one main': (p) => !p.header || !p.footer || p.main !== 1,
        'nav without an accessible name': (p) => p.navNoName > 0,
        'no skip link to an existing id before main': (p) => !p.skip,
        'image without alt': (p) => p.imgNoAlt > 0,
        'http:// resource on an https page (mixed content)': (p) => p.mixed > 0,
        'placeholder text in the visible page (lorem ipsum, TODO)': (p) => p.placeholders.some((n) => n === 'lorem ipsum' || n === 'TODO'),
        'canonical or hreflang href is not a valid URL': (p) => p.badHref > 0,
        'empty title, or one shared with another page': (p) => !p.title || titles.get(p.title) > 1,
        'empty description, or one shared with another page': (p) => !p.description || descs.get(p.description) > 1,
        'broken JSON-LD block': (p) => p.ldBroken > 0,
        'BreadcrumbList on the home page': (p) => p.url === '/' && p.ld.some((n) => n.type === 'BreadcrumbList'),
        'full Organization node away from the home page (point to it by @id)': (p) => p.url !== '/' && p.ld.some((n) => n.type === 'Organization' && n.full),
    };
    const warns = {
        'og:title, og:image or og:url missing': (p) => p.ogMissing.length > 0,
        'template text in the visible page ({{, undefined, NaN; code samples in pre/code are skipped)': (p) => p.placeholders.some((n) => n === '{{' || n === 'undefined' || n === 'NaN'),
        'HTML over 2 MB (reported limit: Googlebot reads only the first 2 MB of HTML)': (p) => p.bytes > HTML_LIMIT,
        'first content image is loading="lazy" (hurts LCP)': (p) => p.lazyFirst,
        'title over 60 characters (may be cut in results; Google sets no hard limit)': (p) => p.titleLong,
    };
    const failures = [];
    const warnings = [];
    for (const [rule, test] of Object.entries(rules)) {
        const pool = rule.startsWith('status') ? pages : ok;
        const hit = pool.filter(test).map((p) => p.url);
        if (hit.length) {
            failures.push({ rule, pages: hit.length, sample: hit.slice(0, 8) });
        }
    }
    for (const [rule, test] of Object.entries(warns)) {
        const hit = ok.filter(test).map((p) => p.url);
        if (hit.length) {
            warnings.push({ rule, pages: hit.length, sample: hit.slice(0, 8) });
        }
    }
    const site = (list, rule, sample) => {
        if (sample.length) {
            list.push({ rule, pages: sample.length, sample: sample.slice(0, 8) });
        }
    };

    // Sitemap hygiene.
    const sm = sitemapIssues(sitemapFiles, host);
    site(failures, 'sitemap file over 50,000 URLs', sm.tooMany);
    site(failures, 'sitemap file over 50 MB uncompressed', sm.tooBig);
    site(warnings, 'sitemap lists the same URL more than once', sm.duplicates);
    site(failures, 'sitemap lists URLs on another host', sm.otherHost);
    site(warnings, 'sitemap lastmod in the future', sm.futureLastmod);
    if (sm.uniformLastmod) {
        warnings.push({ rule: 'every sitemap lastmod is identical (looks fake; Google ignores a lastmod that is not verifiably accurate)', pages: urls.length, sample: [] });
    }

    // hreflang on the fetched pages (HTML link method); alternates outside the sitemap are fetched once to see they answer 200.
    const hl = hreflangIssues(fetched.filter((p) => p.status === 200));
    site(failures, 'hreflang without a link to the page itself (its canonical)', hl.noSelf);
    site(failures, 'hreflang without a return link from the alternate page', hl.noReturn);
    site(failures, 'hreflang code is not a valid language(-region) code', hl.badCode);
    site(warnings, 'several hreflang languages but no x-default', hl.noXDefault);
    const status = new Map(fetched.map((p) => [key(p.url), p.status]));
    const badAlt = [];
    // Only alternates on the audited host are fetched: another site is never crawled.
    const extra = [...new Set(fetched.flatMap((p) => p.alternates.map((a) => a.href)))].filter((u) => !status.has(key(u)) && new URL(u).hostname === host);
    for (const u of extra.slice(0, 100)) {
        try {
            status.set(key(u), (await get(u)).status);
        } catch {
            status.set(key(u), 0);
        }
    }
    for (const p of fetched) {
        for (const a of p.alternates) {
            if (new URL(a.href).hostname === host && status.get(key(a.href)) !== 200 && !badAlt.includes(a.href)) {
                badAlt.push(a.href);
            }
        }
    }
    site(failures, 'hreflang alternate URL does not answer 200', badAlt);

    // robots.txt and soft 404.
    const robotsInfo = {};
    try {
        const r = await get(`${origin}/robots.txt`);
        robotsInfo.status = r.status;
        if (r.status >= 500) {
            failures.push({ rule: `robots.txt answers ${r.status} (Google treats it as "disallow everything")`, pages: 1, sample: ['/robots.txt'] });
        } else if (r.status === 200) {
            robotsInfo.namesSitemap = /^\s*sitemap:\s*\S+/im.test(r.body);
            if (!robotsInfo.namesSitemap) {
                warnings.push({ rule: 'robots.txt does not name the sitemap', pages: 1, sample: ['/robots.txt'] });
            }
        }
    } catch (e) {
        robotsInfo.error = e.message;
        failures.push({ rule: 'robots.txt could not be fetched', pages: 1, sample: [e.message] });
    }
    const missing = `/__seo-audit-${Math.random().toString(36).slice(2, 10)}-missing`;
    try {
        const r = await get(origin + missing);
        if (r.status === 200) {
            failures.push({ rule: 'soft 404: an unknown URL answers 200 (must be 404 or 410)', pages: 1, sample: [missing] });
        } else if (r.status !== 404 && r.status !== 410) {
            warnings.push({ rule: `an unknown URL answers ${r.status} (expected 404 or 410)`, pages: 1, sample: [missing] });
        }
        robotsInfo.unknownUrlStatus = r.status;
    } catch (e) {
        robotsInfo.unknownUrlError = e.message;
    }

    const types = {};
    for (const p of ok) {
        for (const n of p.ld) {
            types[n.type] = (types[n.type] || 0) + 1;
        }
    }
    const info = {
        pages: pages.length,
        sitemapFiles: sitemapFiles.length,
        sitemapWithoutLastmod: entries.filter((e) => !e.includes('<lastmod>')).length,
        sitemapWithImage: entries.filter((e) => e.includes('<image:image>')).length,
        pagesWithEtagOrLastModified: ok.filter((p) => p.etag).length,
        imagesWithoutSize: ok.reduce((n, p) => n + p.imgNoSize, 0),
        pagesWithHreflang: fetched.filter((p) => p.alternates.length).length,
        robots: robotsInfo,
        jsonLdTypes: types,
    };
    console.log(JSON.stringify({ info, warnings, failures }, null, 1));
    if (process.argv[3]) {
        writeFileSync(process.argv[3], JSON.stringify({ origin, info, warnings, failures, pages }, null, 1));
    }
    process.exit(failures.length ? 1 : 0);
};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await main();
}
