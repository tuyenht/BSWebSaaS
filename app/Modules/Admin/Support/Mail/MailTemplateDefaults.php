<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Lang;

/**
 * The bottom tier of a mail template: its wording in code, what a mail says while neither the
 * platform nor the website changed it, and what «Về mẫu gốc» goes back to.
 *
 * A template whose mail exists today is built from that mail's own language lines (the ones the
 * notification sends while nothing is edited), so the editor never shows a promise the mail does not
 * keep. The others take the board's wording (`admin.mt_def_*`). Bodies are HTML with the variables
 * as `{name}` words; a paragraph holding only a link is the mail's button.
 */
final class MailTemplateDefaults
{
    /**
     * @return array{subject: string, body: string}
     */
    public function for(string $key, string $locale): array
    {
        $t = fn (string $line, array $replace = []): string => self::escaped($line, $replace, $locale);

        return match ($key) {
            'reset' => [
                'subject' => Cast::string(__('auth.reset_email_subject', [], $locale)),
                'body' => '<p>'.$t('auth.reset_email_intro').'</p>'
                    .'<p><a href="{reset_url}">'.$t('auth.reset_password').'</a></p>'
                    .'<p>'.$t('auth.reset_email_outro').'</p>',
            ],
            'verify' => [
                'subject' => Cast::string(__('auth.verify_email_subject', [], $locale)),
                'body' => '<p>'.$t('auth.verify_email_intro').'</p>'
                    .'<p><a href="{verify_url}">'.$t('auth.verify_email_action').'</a></p>'
                    .'<p>'.$t('auth.verify_email_outro').'</p>',
            ],
            'team_invite' => [
                'subject' => Cast::string(__('rbac.invitation.mail_subject', ['site' => '{site_name}'], $locale)),
                'body' => '<p>'.$t('rbac.invitation.mail_greeting').'</p>'
                    .'<p>'.$t('rbac.invitation.mail_intro', ['inviter' => '{inviter_name}', 'site' => '{site_name}', 'roles' => '{roles}']).'</p>'
                    .'<p><em>“{invite_message}”</em></p>'
                    .'<p><a href="{accept_url}">'.$t('rbac.invitation.mail_action').'</a></p>'
                    .'<p>'.$t('rbac.invitation.mail_expiry', ['date' => '{expires_at}']).'</p>'
                    .'<p>'.$t('rbac.invitation.mail_ignore').'</p>',
            ],
            default => [
                'subject' => Cast::string(__('admin.mt_def_'.$key.'_s', [], $locale)),
                'body' => Cast::string(__('admin.mt_def_'.$key.'_b', [], $locale)),
            ],
        };
    }

    /** Whether code holds this template in that language (not only through the fallback language). */
    public function has(string $key, string $locale): bool
    {
        $line = match ($key) {
            'reset' => 'auth.reset_email_subject',
            'verify' => 'auth.verify_email_subject',
            'team_invite' => 'rbac.invitation.mail_subject',
            default => 'admin.mt_def_'.$key.'_s',
        };

        return Lang::has($line, $locale, false);
    }

    /**
     * A line of the mail's text in code, escaped for the template's HTML.
     *
     * @param  array<array-key, mixed>  $replace
     */
    private static function escaped(string $line, array $replace, string $locale): string
    {
        $words = [];
        foreach ($replace as $name => $value) {
            $words[(string) $name] = Cast::string($value);
        }

        return e(Cast::string(__($line, $words, $locale)));
    }
}
