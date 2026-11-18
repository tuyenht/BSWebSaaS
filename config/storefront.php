<?php

return [

    // Public pages leave the origin cacheable by a shared edge (Cloudflare): no session, no cookie.
    // `s_maxage` is how long the edge may serve a copy without asking; for `stale_while_revalidate`
    // more seconds after that it may serve the old copy once while it fetches the new one. Browsers
    // always ask again (max-age=0). Only pages whose query string uses the `query_allow` keys alone
    // are cacheable; any other key (or a signed-in admin, a sent form, a preview, an error) answers
    // `private, no-store`.
    //
    // A day, because a change is purged as it commits (Storefront\EdgeCache): a short lifetime only
    // made the first view after it expire at origin speed (Cloudflare revalidates in line, it does
    // not serve the stale copy while it fetches, and answers a Speed Brain prefetch of an expired
    // page with 503). What no purge reaches keeps a shorter one (EdgeCacheHeaders): a host outside
    // the platform's zone (a customer's own domain) keeps `unpurged_s_maxage`, and every page expires
    // by the site's next midnight, when what it shows by date alone (a deadline "closing soon") moves.
    'edge_cache' => [
        's_maxage' => (int) env('STOREFRONT_EDGE_S_MAXAGE', 86400),
        'unpurged_s_maxage' => (int) env('STOREFRONT_EDGE_UNPURGED_S_MAXAGE', 300),
        'stale_while_revalidate' => (int) env('STOREFRONT_EDGE_SWR', 86400),
        'query_allow' => ['page', 'sau', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'],
        // The search suggestions (EdgeCacheHeaders:suggest): asked several times a second while a visitor
        // types, the same answer for everyone. A change to the site purges them with its pages (the purge
        // is by host); kept a minute, served a minute longer while refetched, so what no purge reaches (a
        // post whose publish time comes, a refused host purge, a customer domain outside our zone, a site
        // suspended or closed) shows at most two minutes late. Only the typed words may vary the address.
        'suggest' => [
            's_maxage' => (int) env('STOREFRONT_SUGGEST_S_MAXAGE', 60),
            'stale_while_revalidate' => (int) env('STOREFRONT_SUGGEST_SWR', 60),
            'query_allow' => ['q'],
        ],
    ],

];
