<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * The reasons an OAuth round trip can come back without success, as short codes.
 *
 * When the callback lands on another host than the screen that started it, the screen learns why it
 * failed from its address (`?oauth_error=<code>`). Only the code travels: the screen turns it into its
 * own sentence here, and an unknown code shows nothing. A sentence carried in the address would let
 * anyone send an admin a link that prints words of their choosing inside the admin.
 */
enum OAuthError: string
{
    case Failed = 'failed';
    case NotConfigured = 'not_configured';
    case NoRefreshToken = 'no_refresh_token';
    case NoAccount = 'no_account';
    case NotMember = 'not_member';
    case AlreadyLinked = 'already_linked';

    public function message(): string
    {
        return __(match ($this) {
            self::Failed => 'admin.oauth_failed',
            self::NotConfigured => 'admin.oauth_provider_not_configured',
            self::NoRefreshToken => 'admin.oauth_no_refresh_token',
            self::NoAccount => 'admin.oauth_login_no_account',
            self::NotMember => 'admin.oauth_login_not_member',
            self::AlreadyLinked => 'admin.social_already_linked',
        });
    }

    /** The sentence for a code read from the address, or null when it is not one of ours. */
    public static function messageFor(mixed $code): ?string
    {
        return is_string($code) ? self::tryFrom($code)?->message() : null;
    }
}
