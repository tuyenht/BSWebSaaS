<?php

return [

    // Content revision history (design.md §8, entry_revisions). Per (entry, language) the newest
    // `keep` revisions are always kept; an older one is removed by the daily cms:prune-revisions
    // only once it is also more than `days` days old. The newest revision is never removed.
    'revisions' => [
        'keep' => (int) env('CMS_REVISIONS_KEEP', 20),
        'days' => (int) env('CMS_REVISIONS_DAYS', 90),
    ],

];
