<?php

/*
 * Production error reporting (Monitoring module). The application tells the operator by mail when
 * something breaks: an unhandled error, an error the code swallowed with report(), a queued job
 * that failed for good, a scheduled command that failed. Off by default: with no recipient (or no
 * sender configured on the `monitoring` mailer in config/mail.php) nothing beyond the normal log
 * happens — dev, CI and tests stay silent.
 */
return [

    // Who receives the notices. Comma-separated for several addresses. Empty = feature off.
    'to' => env('MONITORING_MAIL_TO'),

    // The mailer the notices go out on (config/mail.php). Never the default mailer: that one is
    // rewritten per tenant and could send a platform notice through a customer's mail account.
    'mailer' => 'monitoring',

    // Every notice is written in this language and shows times in this zone, whatever the
    // request that failed was using.
    'locale' => 'vi',
    'timezone' => 'Asia/Ho_Chi_Minh',

    // The daily summary is sent at this local time, and the counting day runs from this time to
    // the same time the next day — so the summary always covers exactly the day that just closed.
    'summary_at' => '08:00',

    // A fault seen for the first time in this many seconds gets a mail right away.
    'first_seen_seconds' => 86_400,

    // A fault gets another mail when its count for the day reaches one of these numbers.
    'milestones' => [100, 1_000],

    // At most this many immediate mails per clock hour; the rest wait for the daily summary.
    'hourly_ceiling' => 5,

    // Counters and samples in Valkey expire after this many days.
    'retention_days' => 8,

    // Valkey key prefix (on top of the connection's own prefix).
    'key_prefix' => 'monitoring:',

    // How many faults the daily summary lists before it says "and N more".
    'summary_limit' => 50,

    // A bare GET to this address every 5 minutes proves the scheduler is alive (healthchecks.io
    // or similar). It carries no data. Empty = no heartbeat.
    'heartbeat_url' => env('MONITORING_HEARTBEAT_URL'),
    'heartbeat_timeout' => 5,

];
