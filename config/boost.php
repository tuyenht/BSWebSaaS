<?php

// Laravel Boost is dev-tooling only: its service provider self-disables on production
// (env !== local && debug off) and while running tests. The switches below trim the parts
// this headless box cannot use or must not run.
return [
    'enabled' => env('BOOST_ENABLED', true),

    // No browser ever talks to this dev box — and the watcher would push an InjectBoost
    // middleware into the `web` group (script injection that also collides with the
    // storefront CSP). Keep the whole cluster (middleware + /_boost route + directive) off.
    'browser_logs_watcher' => false,

    // Arbitrary-PHP execution tool stays opt-in-per-need; DB access for agents goes through
    // the read-only Database Query/Schema tools instead.
    'tinker_tool_enabled' => false,
];
