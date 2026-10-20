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
    // site for `plan_ttl` seconds, so a page can lag new content by up to that long.
    'sitemap' => [
        'max_urls' => (int) env('CMS_SITEMAP_MAX_URLS', 50000),
        'plan_ttl' => (int) env('CMS_SITEMAP_PLAN_TTL', 3600),
    ],

];
