<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Support\MailRelay;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Str;

/**
 * What each mail service asks its customers to publish, for the SPF / DKIM / DMARC check of the
 * Email screen. Only what the service's own documentation names is listed; where it names nothing
 * fixed (a key name made per domain, an SPF record on a name the customer picks) the check says it
 * could not look, rather than guess.
 *
 * - `spf`: where the record that lets the service send sits (`host` '' = the sending domain itself,
 *   otherwise that name under it) and the `include:` it must carry (null = any SPF record there).
 * - `spf_needed`: whether the service asks for that record; when it does not (it proves SPF on a
 *   bounce address of its own), finding it is still a pass, and not finding it is "could not check".
 * - `dkim`: the selectors the service signs with; [] = made per domain, so not checked.
 *
 * Sources (read 28/11/2026):
 * - SendGrid: twilio.com/docs/sendgrid/ui/account-and-settings/how-to-set-up-domain-authentication —
 *   s1._domainkey and s2._domainkey; "Twilio SendGrid handles the signing of your DKIM and the
 *   authentication of your SPF with CNAME records" (automated security, the default; the
 *   `include:sendgrid.net` TXT is its example with automated security off).
 * - Postmark: postmarkapp.com/support/article/setting-up-dkim-for-your-domain — the key name is
 *   per domain ("like 2023060112345pm._domainkey"); article 910-how-do-i-add-a-custom-return-path —
 *   "the default hostname value for a custom return-path is pm-bounces", a CNAME to pm.mtasv.net.
 * - Amazon SES: docs.aws.amazon.com/ses/latest/dg/send-email-authentication-dkim-easy.html —
 *   Easy DKIM names are generated tokens; SPF sits on a custom MAIL FROM name the customer picks.
 * - Mailgun: documentation.mailgun.com/docs/mailgun/user-manual/domains/domains-verify — SPF
 *   "v=spf1 include:mailgun.org ~all"; the DKIM record is per domain ("Find this record in the
 *   Domain Verification & DNS section").
 * - Brevo: help.brevo.com/hc/en-us/articles/16045394674066 (troubleshooting domain
 *   authentication) — DKIM 1 / DKIM 2 at brevo1._domainkey / brevo2._domainkey; its authentication
 *   asks for a Brevo code, DKIM and DMARC, no SPF.
 * - Resend: resend.com/docs/knowledge-base/cloudflare.md — DKIM TXT "resend._domainkey"; SPF TXT
 *   at "send" with "v=spf1 include:amazonses.com ~all".
 * - Mailtrap: docs.mailtrap.io/email-api-smtp/setup/sending-domain — DKIM rwmt1._domainkey and
 *   rwmt2._domainkey; no SPF record is listed.
 * - Google Workspace: knowledge.workspace.google.com/admin/security/set-up-dkim — "The default
 *   prefix selector is google"; …/set-up-spf — "v=spf1 include:_spf.google.com ~all".
 * - Microsoft 365: learn.microsoft.com/en-us/defender-office-365/email-authentication-dkim-configure
 *   — "The values are the same for all Microsoft 365 organizations: selector1._domainkey and
 *   selector2._domainkey"; …/email-authentication-spf-configure — "include:spf.protection.outlook.com".
 */
final class MailDnsProviders
{
    /** @var array<string, array{spf: array{host: string, include: string|null}|null, spf_needed: bool, dkim: list<string>}> */
    public const array TABLE = [
        'sendgrid' => ['spf' => ['host' => '', 'include' => 'sendgrid.net'], 'spf_needed' => false, 'dkim' => ['s1', 's2']],
        'postmark' => ['spf' => ['host' => 'pm-bounces', 'include' => null], 'spf_needed' => false, 'dkim' => []],
        'ses' => ['spf' => null, 'spf_needed' => false, 'dkim' => []],
        'mailgun' => ['spf' => ['host' => '', 'include' => 'mailgun.org'], 'spf_needed' => true, 'dkim' => []],
        'brevo' => ['spf' => null, 'spf_needed' => false, 'dkim' => ['brevo1', 'brevo2']],
        'resend' => ['spf' => ['host' => 'send', 'include' => 'amazonses.com'], 'spf_needed' => true, 'dkim' => ['resend']],
        'mailtrap' => ['spf' => null, 'spf_needed' => false, 'dkim' => ['rwmt1', 'rwmt2']],
        'google' => ['spf' => ['host' => '', 'include' => '_spf.google.com'], 'spf_needed' => true, 'dkim' => ['google']],
        'microsoft' => ['spf' => ['host' => '', 'include' => 'spf.protection.outlook.com'], 'spf_needed' => true, 'dkim' => ['selector1', 'selector2']],
        // «Email khác» on a server not listed below: nothing is known about what it needs
        'smtp' => ['spf' => null, 'spf_needed' => false, 'dkim' => []],
    ];

    /**
     * «Email khác» servers whose service is known (Google: support.google.com/a/answer/176600;
     * Microsoft: learn.microsoft.com/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365).
     */
    private const array SMTP_HOSTS = [
        'smtp.gmail.com' => 'google',
        'smtp-relay.gmail.com' => 'google',
        'smtp.office365.com' => 'microsoft',
    ];

    /** A sending domain as the check takes it: lower case, a dot-separated name with a letter ending. */
    private const string DOMAIN = '/^(?=.{4,253}\z)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.[a-z]{2,63}\z/';

    /** The service a relay (and, for «Email khác», its server) stands for in TABLE. */
    public static function of(string $relay, string $smtpHost = ''): string
    {
        if ($relay === 'smtp') {
            return self::SMTP_HOSTS[strtolower(trim($smtpHost))] ?? 'smtp';
        }

        return array_key_exists($relay, self::TABLE) ? $relay : 'smtp';
    }

    /**
     * The service of a stored «Nhà cung cấp khác» setup.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function ofConfig(array $cfg): string
    {
        return self::of(MailRelay::of($cfg), Cast::string(Cast::arr($cfg['smtp'] ?? null)['host'] ?? null));
    }

    /**
     * The domain a stored «Nhà cung cấp khác» setup sends from (as the screen's sender line reads
     * it): a service's sending domain; for «Email khác» the domain of its login, or of the stored
     * address when the login is no address. '' when there is none, or it is no domain name.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function domainOf(array $cfg): string
    {
        if (MailRelay::of($cfg) === 'smtp') {
            $login = trim(Cast::string(Cast::arr($cfg['smtp'] ?? null)['username'] ?? null));
            $from = str_contains($login, '@') ? $login : trim(Cast::string($cfg['from_address'] ?? null));

            return str_contains($from, '@') ? self::domain(Str::afterLast($from, '@')) : '';
        }

        return self::domain(Cast::string($cfg['send_domain'] ?? null));
    }

    /** $value as a sending domain the check may look up, or '' when it is none. */
    public static function domain(string $value): string
    {
        $domain = strtolower(trim($value));

        return preg_match(self::DOMAIN, $domain) === 1 ? $domain : '';
    }

    /** Whether the domain is a public mailbox's (Gmail, Outlook…): its owner publishes its records. */
    public static function isPublicMailbox(string $domain): bool
    {
        return in_array($domain, MailRelay::PUBLIC_MAILBOXES, true);
    }
}
