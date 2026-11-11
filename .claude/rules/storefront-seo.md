---
paths:
  - "themes/**"
  - "app/Modules/Storefront/**"
  - "app/Modules/Theme/**"
  - "app/Modules/Cms/**"
  - "app/Modules/Catalog/**"
  - "database/content-packs/**"
  - "routes/web.php"
  - "scripts/storefront/**"
---

# Storefront SEO and AI search (every site, every page type)

Sources with quotes and URLs: `docs/notes/seo-geo-sources.md` (read 2026-11-11). Google: "optimizing for generative AI search is optimizing for the search experience, and thus still SEO", so one rule set serves Google, Bing and AI answer engines. Everything here is platform code with a sensible fallback; a site pack only supplies words. Tests assert the generic rule on any seeded site, never one site's strings.

## 1. Crawling and indexing
- HTML is complete on the server (Blade SSR). Links are real `<a href>`; nothing important appears only after JS.
- Real status codes: missing = 404 (or 410 when removed for good), moved = 301 to the new URL, never a 200 "not found" page.
- Every indexable page has a self `canonical`. Paged lists canonicalise to themselves.
- Google stopped using rel=prev/next in 2019; keep them only as harmless link hints — crawlable `<a href>` links to every page plus a self canonical are what count.
- `sitemap.xml` lists only pages that answer 200, are canonical and are indexable. Never list noindex pages (thank-you, search results, cart, checkout, account, admin).
- `lastmod` is the time of the last significant change, so Google and Bing keep trusting it. Never write a fake "now". A list page's lastmod is its newest member's change.
- Add `<image:image><image:loc>` for each page's main images. Leave out `changefreq` and `priority`: both engines ignore them. The Google sitemap ping is gone.
- `robots.txt` names the sitemap.
- HTML answers carry `ETag` or `Last-Modified` and honour `If-None-Match` / `If-Modified-Since` with 304. Googlebot supports both, and it cuts crawl cost.
- Owner steps per live domain: Google Search Console and Bing Webmaster Tools verification. The site's verification field supports both, plus the sitemap submit. IndexNow pings on publish wait for the owner's decision.

## 2. HTML structure
- Exactly one `<h1>` and it equals the visible page title. Heading levels never skip.
- Exactly one `main`, a page `header` and `footer` (an `article` may have its own). Each `nav` has an `aria-label`.
- A skip link comes before the header. `<html lang>` is the page's locale, and `aria-current="page"` marks the current item in the menu and the breadcrumb.
- Use the element that means the thing:
  - `<article>` for a post, product or job;
  - `<time datetime>` for dates;
  - `<figure>` + `<figcaption>` for captioned images;
  - `<address>` for contact blocks;
  - `<table>` with `<th scope>` for specifications;
  - `<ul>` / `<ol>` for lists;
  - `<details>` / `<summary>` or headings + paragraphs for Q&A.
- Images:
  - meaningful `alt`, or `alt=""` when decorative;
  - `width` and `height` set (CLS);
  - `srcset`/`sizes`;
  - `loading="lazy"` only below the first screen; the first-screen main image is never lazy.
- Breadcrumbs:
  - list and info pages end with the current page;
  - detail pages (post, project, job, product) end the visible trail at the parent;
  - JSON-LD keeps the full path;
  - no BreadcrumbList with fewer than 2 items.
- Class names carry no meaning for search engines or AI (project judgement: no source ranks them; meaning comes from elements, JSON-LD and text). Keep BEM (`block__part--variant`); shared parts use the `sf-` prefix; no mass renames.

## 3. Titles and descriptions
- Every title is unique: `{page title} | {site name}`, with the brand short and at the end. The home page may lead with what the business does.
- Two pages with the same name (a product category and a project category both "Khuôn mẫu") get their parent added: "Khuôn mẫu · Dự án".
- Description fallback chain: the page's own SEO description → its excerpt or description → a sentence built from its name, parent and count. Never one shared fallback sentence on many pages. Project target about 70–160 characters (Google sets no length; it wants unique, accurate text), with no keyword lists.
- Staff can override both per page, category, product and site. The fallback must already be good.

## 4. Structured data (JSON-LD only, true to what is visible)
- One `@graph` per page. Stable `@id`s: `{origin}/#organization`, `{origin}/#website`, `{url}#webpage`.
- The full `Organization` sits on the home page, or on one About page, only. Google: "You don't need to include it on every page of your site." Other pages point to it by `@id` (publisher, manufacturer, brand).
- `WebSite` is on the home page only.
- `Article` on posts: headline, image (not the logo), `datePublished` and `dateModified` with time zone, author with a name (and url when there is an author page), and publisher by `@id`.
- `Product`:
  - always name, image, description, `brand` and `manufacturer` by `@id`, `category`, plus `material` and `additionalProperty` from the spec table.
  - `offers` only with a real price above 0. Never invent a price, rating or review. A price-less catalogue simply gets no Product rich result.
- Branches use the most specific `LocalBusiness` subtype, only with a real address, phone and opening hours.
- `FAQPage` may stay as plain markup for other engines. Google stopped showing FAQ rich results on 2026-05-07, so never build a feature for it.
- `ItemList` carousels are Google-only for Course, Movie, Recipe and Restaurant. Use `CollectionPage` + `mainEntity` for list pages instead.
- Never build (retired): FAQ rich results (Google ended them 2026-05-07), HowTo rich results, the sitemap ping, `changefreq` / `priority`.
- Validate the JSON (the audit fails on a broken block). Mark up nothing that a reader cannot see.

## 5. Content that AI answers quote (GEO)
These rules apply to pack writers, demo content and the admin's writing hints.
- People-first, unique, non-commodity text: who made it (author or company), when (updated date shown), how (process, equipment, standards).
- Concrete facts with numbers and units: tolerances, areas, capacity, years, counts. Name the sources: standard numbers, certifying bodies, customers with consent.
  - The GEO study (Aggarwal et al., 2023) measured these methods: quotations +43% (27.8 vs 19.5, relative gain in position-adjusted word count; the paper's headline is "up to 40%"), statistics +33%, cited sources +28%.
  - Keyword stuffing scored below baseline. It is a 2023 study on simulated engines, so treat it as direction, not a promise.
- Writing style (project judgement for readers, not an AI requirement: Google says content need not be chunked for AI): questions as headings with the answer first, then details; real lists and tables for list and table content.
- One clear topic per page, internal links with descriptive anchor text, no thin pages and no near-duplicates.

## 6. Crawlers and AI bots
- Default `robots.txt` allows everything.
- Search crawlers decide visibility: Googlebot, Bingbot, OAI-SearchBot (ChatGPT search), PerplexityBot and Claude-SearchBot. Never block them by default.
- Training crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot) are a per-site business choice. Blocking them does not remove a site from AI search answers.
- Do not add `nosnippet` / `max-snippet` limits unless a site asks: they also cut AI use.
- `llms.txt`: Google Search does not use it. It is optional and not built now.

## 7. Speed (also an AI-search factor per Google)
- Project gates: LCP ≤ 1.5 s, storefront JS ≤ 30 KB (CLAUDE.md §6). Google's own thresholds: INP < 200 ms, CLS < 0.1.
- No layout shift from images, fonts or late blocks.

## 8. Several languages
- `hreflang` is reciprocal: every version lists itself and every other version, plus `x-default`.
- `og:locale` and `og:locale:alternate` cover every published locale.
- URLs come from PathBuilder. A translated page has its own translated title and description.

## 9. Check
- After a deploy or a template change: `node scripts/storefront/seo-audit.mjs https://<site> <out.json>`.
  - It reads the sitemap and checks:
    - status;
    - noindex pages in the sitemap;
    - canonical;
    - lang;
    - one h1;
    - heading order;
    - landmarks and nav names;
    - skip link;
    - alt;
    - unique titles and descriptions;
    - JSON-LD parse;
    - Organization placement;
    - viewport, one canonical tag, one description tag, mixed content (http:// resources on https), placeholder text (lorem ipsum, TODO; `{{`, undefined and NaN only warn, and code in `pre`/`code` is skipped), malformed canonical or hreflang URLs;
    - sitemap hygiene: over 50,000 URLs or 50 MB per file, URLs on another host (fail); duplicate URLs and lastmod in the future (warn);
    - hreflang (HTML links): self link equal to the canonical, return links between fetched pages, valid language codes, alternates answering 200;
    - `robots.txt` (5xx fails: Google treats it as disallow-all) and soft 404 (an unknown path must answer 404 or 410).
  - Warnings (advice, never an exit code): identical lastmod on 10+ URLs (looks fake), several hreflang languages without `x-default`, `robots.txt` without a sitemap line, HTML over 2 MB (a reported Googlebot limit, not in our sources note), missing og:title / og:image / og:url, first content image lazy-loaded, title over 60 characters (Google sets no limit).
  - It also prints coverage numbers: lastmod, sitemap images, ETag, images without size.
  - It sends one request at a time. The pure checks have tests: `node --test tests/js/seo-audit.test.mjs`.
  - It exits 1 on any rule failure and 2 when it cannot run (no sitemap, empty sitemap, network). Paste the summary into the report.
- What it cannot see, check by hand: words that match the visible content, real facts, and Search Console reports (owner's account).
