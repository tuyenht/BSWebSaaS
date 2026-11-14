<?php

// The connection the platform error notices use: their own MONITORING_MAIL_* settings when a host
// is set there, otherwise the platform sender's MAIL_* settings as one group (see the mailer below).
$monitoringMail = filled(env('MONITORING_MAIL_HOST')) ? 'MONITORING_MAIL_' : 'MAIL_';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        // Platform error notices (config/monitoring.php). Built from MONITORING_MAIL_*. With no
        // MONITORING_MAIL_HOST the whole connection (scheme, host, port, username, password) comes
        // from the platform's own MAIL_* values, never a mix, so the platform password is never sent
        // to another host; the sender falls back key by key. Read from the environment, so the
        // per-tenant mail settings never reach it. Never
        // a fallback for the default mailer: with no host or sender here no notice is sent at all.
        // A short timeout, because a notice is sent by the `monitoring` queue's worker and that
        // worker waits on the mail host.
        'monitoring' => [
            'transport' => 'smtp',
            'scheme' => env($monitoringMail.'SCHEME') ?: null, // `smtps` for port 465, else empty
            'host' => env($monitoringMail.'HOST') ?: null,
            'port' => env($monitoringMail.'PORT') ?: 587,
            'username' => env($monitoringMail.'USERNAME'),
            'password' => env($monitoringMail.'PASSWORD'),
            'timeout' => 5,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
            'from' => [
                'address' => env('MONITORING_MAIL_FROM_ADDRESS') ?: env('MAIL_FROM_ADDRESS'),
                'name' => env('MONITORING_MAIL_FROM_NAME') ?: env('MAIL_FROM_NAME'),
            ],
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
