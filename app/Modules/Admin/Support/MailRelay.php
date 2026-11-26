<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;

/**
 * The mail services a website picks under «Nhà cung cấp khác», each sent through that service's own
 * SMTP relay (no extra package). The server name, port and encryption come from this table, never
 * from the browser; only «Email khác» (`smtp`) takes a server the member types in. The website's
 * key is the SMTP password (stored encrypted as before). A service that issues an SMTP login per
 * account (Brevo, Mailgun, Amazon SES) takes it in a field; the others fix it for everyone, and
 * Postmark's login is the key itself (so it is never stored in the clear).
 *
 * Hosts checked against each service's own SMTP page on 27/11/2026.
 */
final class MailRelay
{
    /** In the board's tile order; `smtp` is «Email khác (nhập máy chủ gửi thư)». */
    public const array KEYS = ['sendgrid', 'postmark', 'ses', 'mailgun', 'brevo', 'resend', 'mailtrap', 'smtp'];

    /** Amazon SES regions that have an SMTP endpoint; a region is picked from here, never typed into a host. */
    public const array SES_REGIONS = ['us-east-1', 'us-east-2', 'us-west-2', 'eu-west-1', 'eu-central-1',
        'ap-southeast-1', 'ap-southeast-2', 'ap-northeast-1', 'ap-south-1'];

    public const array MAILGUN_REGIONS = ['us', 'eu'];

    /** The services whose SMTP login is issued per account and typed in. */
    public const array OWN_LOGIN = ['brevo', 'mailgun', 'ses'];

    /** The login a service fixes for everyone. */
    private const array FIXED_LOGIN = ['sendgrid' => 'apikey', 'resend' => 'resend', 'mailtrap' => 'api'];

    /** A sending address's mailbox provider whose SMTP server is filled in for «Email khác». */
    private const array KNOWN_MAILBOXES = [
        'gmail.com' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'googlemail.com' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'yahoo.com' => ['host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl'],
    ];

    /** Microsoft's consumer mailboxes refuse password sign-in for SMTP; they sign in on the «Microsoft» row. */
    private const array MICROSOFT_MAILBOXES = ['outlook.com', 'hotmail.com', 'live.com'];

    /**
     * The relay a stored setup names, or null when it names none (a setup saved before relays existed).
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function stored(array $cfg): ?string
    {
        $relay = $cfg['relay'] ?? null;

        return is_string($relay) && in_array($relay, self::KEYS, true) ? $relay : null;
    }

    /**
     * The relay a stored setup sends through: an older one is its own mail server.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function of(array $cfg): string
    {
        return self::stored($cfg) ?? 'smtp';
    }

    /** @return list<string> the regions offered for a relay ([] = none) */
    public static function regions(string $relay): array
    {
        return match ($relay) {
            'ses' => self::SES_REGIONS,
            'mailgun' => self::MAILGUN_REGIONS,
            default => [],
        };
    }

    /** The region a newly picked relay starts on ('' = the member must pick one). */
    public static function defaultRegion(string $relay): string
    {
        return $relay === 'mailgun' ? 'us' : '';
    }

    public static function hasOwnLogin(string $relay): bool
    {
        return in_array($relay, self::OWN_LOGIN, true);
    }

    /** Whether the SMTP login is the key itself (Postmark's Server API token). */
    public static function loginIsKey(string $relay): bool
    {
        return $relay === 'postmark';
    }

    /**
     * The relay's server, or null for «Email khác» (typed in) and for a region not offered.
     *
     * @return array{host: string, port: int, encryption: string}|null
     */
    public static function server(string $relay, string $region = ''): ?array
    {
        $host = match ($relay) {
            'sendgrid' => 'smtp.sendgrid.net',
            'postmark' => 'smtp.postmarkapp.com',
            'resend' => 'smtp.resend.com',
            'mailtrap' => 'live.smtp.mailtrap.io',
            'brevo' => 'smtp-relay.brevo.com',
            'mailgun' => match ($region) {
                'us' => 'smtp.mailgun.org',
                'eu' => 'smtp.eu.mailgun.org',
                default => null,
            },
            'ses' => in_array($region, self::SES_REGIONS, true) ? 'email-smtp.'.$region.'.amazonaws.com' : null,
            default => null,
        };

        return $host === null ? null : ['host' => $host, 'port' => 587, 'encryption' => 'tls'];
    }

    /** The login a relay fixes ('' when typed in, or when it is the key). */
    public static function fixedLogin(string $relay): string
    {
        return self::FIXED_LOGIN[$relay] ?? '';
    }

    /**
     * The SMTP settings a stored setup sends with: «Email khác» as stored; a service's server from
     * this table (a stored host is never trusted for it), with its login.
     *
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    public static function smtpOf(array $cfg): array
    {
        $smtp = Cast::arr($cfg['smtp'] ?? null);
        $relay = self::of($cfg);
        if ($relay === 'smtp') {
            return $smtp;
        }

        $server = self::server($relay, Cast::string($cfg['relay_region'] ?? null));
        $login = self::hasOwnLogin($relay) ? Cast::string($smtp['username'] ?? null) : self::fixedLogin($relay);

        return [...$smtp, ...($server ?? ['host' => '']), 'username' => $login];
    }

    /**
     * Whether a stored setup has all a send needs: a server, a password (key) and a login.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function ready(array $cfg): bool
    {
        $relay = self::of($cfg);
        $smtp = self::smtpOf($cfg);

        return ! empty($smtp['host']) && ! empty($smtp['password'])
            && (self::loginIsKey($relay) || ! empty($smtp['username']));
    }

    /**
     * The address mail goes out from when the website set none: a service's is on its sending
     * domain (its login is no address); «Email khác»'s is its login, as before.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function defaultFrom(array $cfg): ?string
    {
        if (self::of($cfg) === 'smtp') {
            $login = Cast::arr($cfg['smtp'] ?? null)['username'] ?? null;

            return is_string($login) && $login !== '' ? $login : null;
        }
        $domain = Cast::string($cfg['send_domain'] ?? null);

        return $domain !== '' ? 'no-reply@'.$domain : null;
    }

    /**
     * The server of a sending address's well-known mailbox provider, for «Email khác».
     *
     * @return array{host: string, port: int, encryption: string}|null
     */
    public static function mailboxServer(string $email): ?array
    {
        return self::KNOWN_MAILBOXES[self::domainOf($email)] ?? null;
    }

    /** A Microsoft consumer address (signs in on the «Microsoft» row instead). */
    public static function isMicrosoftMailbox(string $email): bool
    {
        return in_array(self::domainOf($email), self::MICROSOFT_MAILBOXES, true);
    }

    private static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(trim(substr($email, $at + 1)));
    }
}
