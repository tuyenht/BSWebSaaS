<?php

return [

    // Content revision history (design.md §8, entry_revisions). Per (entry, language) the newest
    // `keep` revisions are always kept; an older one is removed by the daily cms:prune-revisions
    // only once it is also more than `days` days old. The newest revision is never removed.
    'revisions' => [
        'keep' => (int) env('CMS_REVISIONS_KEEP', 20),
        'days' => (int) env('CMS_REVISIONS_DAYS', 90),
    ],

    // sitemap.xml (sitemaps.org caps one file at 50,000 URLs). A site with more listed routes
    // gets a <sitemapindex> of /sitemap-{n}.xml keyset pages; their start cursors are cached per
    // site for `plan_ttl` seconds (at least 60), so a page can lag new content by up to that long.
    // One request per site builds the plan; the others wait up to `plan_lock_wait` seconds for it
    // (default 0: a waiter pins a pooled DB connection inside its tenant transaction), then use
    // the last plan built (kept plan_ttl x 24), or get a 503 with Retry-After when there is none.
    'sitemap' => [
        'max_urls' => (int) env('CMS_SITEMAP_MAX_URLS', 50000),
        'plan_ttl' => (int) env('CMS_SITEMAP_PLAN_TTL', 3600),
        'plan_lock_wait' => (int) env('CMS_SITEMAP_PLAN_LOCK_WAIT', 0),
    ],

    // Hosts a storefront map may come from — the footer's "view map" link and the contact block's
    // embedded map (a subdomain matches too), e.g. google.com,maps.app.goo.gl. Empty: any http(s)
    // link passes the link check.
    'map_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CMS_MAP_HOSTS', ''))))),

    // The slugs a site's privacy-policy page may have in its default language. The consent sentence
    // of a lead form links to the first published page found under one of them, and has no link
    // while the site has none. The site template creates the page under the first slug.
    'privacy_page_slugs' => ['chinh-sach-bao-mat', 'privacy-policy'],

];
