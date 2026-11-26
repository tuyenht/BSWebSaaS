<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Notifications\DomainHelpRequestNotification;
use App\Modules\Admin\Notifications\DomainNoticeNotification;
use App\Modules\Admin\Notifications\EmailChangeCodeNotification;
use App\Modules\Admin\Notifications\EmailChangedNotification;
use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Notifications\OwnerRecoveredNotification;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Notifications\SupportSignInNotification;
use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Forms\Notifications\NewLeadNotification;
use Illuminate\Auth\Notifications\VerifyEmail;

/**
 * The kind of each website mail (`mail_logs.kind`, whose CHECK lists the same codes) and whether the
 * daily limit may hold it back. Security mails (codes, links, warnings about the account) and the
 * contact-form notice are never held: a reset link a day late is worse than a few mails over the
 * limit. Only the ordinary kinds are held. A notification class not listed here is logged as `other`
 * and, like a security mail, never held (a new security mail must not wait a day because nobody
 * listed it yet); tests/Feature/Admin/MailLogTest.php fails on any mail notification left out.
 */
final class MailKinds
{
    /** @var array<class-string, string> notification class => kind */
    public const array KINDS = [
        ResetPasswordLinkNotification::class => 'password_reset',
        EmailOtpNotification::class => 'email_otp',
        EmailChangeCodeNotification::class => 'email_change_code',
        EmailChangedNotification::class => 'email_changed',
        SupportSignInNotification::class => 'support_sign_in',
        OwnerRecoveredNotification::class => 'owner_recovered',
        TwoFactorDisabledNotification::class => 'two_factor_disabled',
        VerifyEmail::class => 'email_verification',
        NewLeadNotification::class => 'lead',
        MemberInvitationNotification::class => 'invitation',
        DomainNoticeNotification::class => 'domain_notice',
        DomainHelpRequestNotification::class => 'domain_help',
    ];

    /** The kinds the daily limit may hold back to the next day. */
    public const array HOLDABLE = ['invitation', 'domain_notice', 'domain_help'];

    public const string OTHER = 'other';

    /** @return list<string> every code `mail_logs.kind` accepts */
    public static function all(): array
    {
        return [...array_values(self::KINDS), self::OTHER];
    }

    public static function of(string $notificationClass): string
    {
        return self::KINDS[$notificationClass] ?? self::OTHER;
    }

    public static function holdable(string $kind): bool
    {
        return in_array($kind, self::HOLDABLE, true);
    }
}
