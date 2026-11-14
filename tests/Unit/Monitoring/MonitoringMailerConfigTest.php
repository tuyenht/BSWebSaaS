<?php

declare(strict_types=1);

/*
 * The `monitoring` mailer in config/mail.php: with its own host set it uses its own connection;
 * otherwise the platform sender's MAIL_* connection as one group, and the sender falls back key by
 * key, so turning notices on needs only a recipient. With neither set the mailer has no host or
 * sender and nothing is sent.
 */

/**
 * The monitoring mailer as config/mail.php builds it under exactly these mail variables (every
 * other one unset), restoring the process environment afterwards.
 *
 * @param  array<string, string>  $vars
 * @return array<string, mixed>
 */
function monitoringMailerUnder(array $vars): array
{
    $keys = [
        'MONITORING_MAIL_SCHEME', 'MONITORING_MAIL_HOST', 'MONITORING_MAIL_PORT', 'MONITORING_MAIL_USERNAME',
        'MONITORING_MAIL_PASSWORD', 'MONITORING_MAIL_FROM_ADDRESS', 'MONITORING_MAIL_FROM_NAME',
        'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
    ];
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
        if (array_key_exists($key, $vars)) {
            $_SERVER[$key] = $vars[$key];
            $_ENV[$key] = $vars[$key];
            putenv($key.'='.$vars[$key]);
        }
    }

    try {
        /** @var array{mailers: array{monitoring: array<string, mixed>}} $config */
        $config = require dirname(__DIR__, 3).'/config/mail.php';

        return $config['mailers']['monitoring'];
    } finally {
        foreach ($saved as $key => [$server, $env, $put]) {
            unset($_SERVER[$key], $_ENV[$key]);
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            putenv($put === false ? $key : $key.'='.$put);
        }
    }
}

it('falls back to the platform sender when no separate notice sender is set', function (): void {
    $mailer = monitoringMailerUnder([
        'MAIL_SCHEME' => 'smtps', 'MAIL_HOST' => 'mail.platform.test', 'MAIL_PORT' => '465',
        'MAIL_USERNAME' => 'sender@platform.test', 'MAIL_PASSWORD' => 'platform-secret',
        'MAIL_FROM_ADDRESS' => 'sender@platform.test', 'MAIL_FROM_NAME' => 'Platform',
    ]);

    expect($mailer['scheme'])->toBe('smtps')
        ->and($mailer['host'])->toBe('mail.platform.test')
        ->and($mailer['port'])->toBe('465')
        ->and($mailer['username'])->toBe('sender@platform.test')
        ->and($mailer['password'])->toBe('platform-secret')
        ->and($mailer['from'])->toBe(['address' => 'sender@platform.test', 'name' => 'Platform']);
});

it('uses its own connection as a whole once its own host is set, never the platform password', function (): void {
    $mailer = monitoringMailerUnder([
        'MAIL_SCHEME' => 'smtps', 'MAIL_HOST' => 'mail.platform.test', 'MAIL_PORT' => '465',
        'MAIL_USERNAME' => 'sender@platform.test', 'MAIL_PASSWORD' => 'platform-secret',
        'MAIL_FROM_ADDRESS' => 'sender@platform.test', 'MAIL_FROM_NAME' => 'Platform',
        'MONITORING_MAIL_HOST' => 'alerts.mail.test', 'MONITORING_MAIL_FROM_ADDRESS' => 'alerts@platform.test',
    ]);

    expect($mailer['host'])->toBe('alerts.mail.test')
        ->and($mailer['scheme'])->toBeNull()
        ->and($mailer['port'])->toBe(587)
        ->and($mailer['username'])->toBeNull()
        ->and($mailer['password'])->toBeNull()
        ->and($mailer['from'])->toBe(['address' => 'alerts@platform.test', 'name' => 'Platform']);
});

it('has no host or sender when neither is set, so no notice goes out', function (): void {
    $mailer = monitoringMailerUnder([]);

    expect($mailer['host'])->toBeNull()
        ->and($mailer['scheme'])->toBeNull()
        ->and($mailer['port'])->toBe(587)
        ->and($mailer['from'])->toBe(['address' => null, 'name' => null]);
});
