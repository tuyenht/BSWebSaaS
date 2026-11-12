<?php

return [

    // Public pages leave the origin cacheable by a shared edge (Cloudflare): no session, no cookie.
    // `s_maxage` is how long the edge may serve a copy without asking (also the longest a change
    // takes to show when no purge runs); for `stale_while_revalidate` more seconds after that it may
    // serve the old copy once while it fetches the new one. Browsers always ask again (max-age=0).
    // Only pages whose query string uses the `query_allow` keys alone are cacheable; any other key
    // (or a signed-in admin, a sent form, a preview, an error) answers `private, no-store`.
    'edge_cache' => [
        's_maxage' => (int) env('STOREFRONT_EDGE_S_MAXAGE', 300),
        'stale_while_revalidate' => (int) env('STOREFRONT_EDGE_SWR', 86400),
        'query_allow' => ['page', 'sau', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'],
    ],

];
