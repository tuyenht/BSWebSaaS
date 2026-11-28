<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

/**
 * The automatic mails a website can word itself (the «Mẫu thư» tab), as the Email board lists them
 * for a company website. Each template:
 *
 *  - kind: `switch` (the website may turn it off), `required` («Bắt buộc»: always sent, editable, and
 *    a save must keep its required variables) or `system` («Hệ thống»: worded by the platform only,
 *    the website sees it);
 *  - i18n: false for a mail only the website's own people get (one copy, in its default language);
 *  - wired: whether a mail that exists today is built from it (MailTemplateMail). The others are
 *    listed as the board lists them and keep their wording for the day their mail is built;
 *  - module: the website module the template belongs to (null = every website);
 *  - vars: the variables offered in the editor, in the board's order; required: those a save keeps.
 *
 * The keys are the `template_key` of mail_templates / platform_mail_templates.
 */
final class MailTemplateCatalog
{
    public const string SWITCH = 'switch';

    public const string REQUIRED = 'required';

    public const string SYSTEM = 'system';

    /**
     * @var array<string, array{group: string, icon: string, kind: string, i18n: bool, wired: bool, module: string|null, vars: list<string>, required: list<string>}>
     */
    public const array TEMPLATES = [
        'welcome' => [
            'group' => 'account', 'icon' => 'ri-emotion-happy', 'kind' => self::SWITCH, 'i18n' => true, 'wired' => false, 'module' => null,
            'vars' => ['{customer_name}', '{customer_email}', '{account_url}', '{site_name}', '{site_url}', '{contact_email}', '{hotline}'],
            'required' => [],
        ],
        'verify' => [
            'group' => 'account', 'icon' => 'ri-shield-check', 'kind' => self::REQUIRED, 'i18n' => true, 'wired' => true, 'module' => null,
            'vars' => ['{customer_name}', '{customer_email}', '{verify_url}', '{site_name}', '{contact_email}'],
            'required' => ['{verify_url}'],
        ],
        'reset' => [
            'group' => 'account', 'icon' => 'ri-lock-2', 'kind' => self::REQUIRED, 'i18n' => true, 'wired' => true, 'module' => null,
            'vars' => ['{customer_name}', '{reset_url}', '{site_name}', '{contact_email}', '{hotline}'],
            'required' => ['{reset_url}'],
        ],
        'pwchanged' => [
            'group' => 'account', 'icon' => 'ri-key-2', 'kind' => self::REQUIRED, 'i18n' => true, 'wired' => false, 'module' => null,
            'vars' => ['{customer_name}', '{change_time}', '{change_ip}', '{site_name}', '{contact_email}', '{hotline}'],
            'required' => [],
        ],
        'team_invite' => [
            'group' => 'team', 'icon' => 'ri-user-add', 'kind' => self::SWITCH, 'i18n' => true, 'wired' => true, 'module' => 'users',
            'vars' => ['{inviter_name}', '{site_name}', '{roles}', '{invite_message}', '{accept_url}', '{expires_at}'],
            'required' => ['{accept_url}'],
        ],
        'contact' => [
            'group' => 'contact', 'icon' => 'ri-mail-check', 'kind' => self::SWITCH, 'i18n' => true, 'wired' => false, 'module' => 'contacts',
            'vars' => ['{customer_name}', '{site_name}', '{contact_email}', '{hotline}'],
            'required' => [],
        ],
        'ntf_contact' => [
            'group' => 'internal', 'icon' => 'ri-notification-3', 'kind' => self::SYSTEM, 'i18n' => false, 'wired' => false, 'module' => 'contacts',
            'vars' => ['{customer_name}', '{customer_email}', '{customer_phone}', '{message}', '{form_name}', '{submitted_at}', '{admin_url}'],
            'required' => [],
        ],
    ];

    /** The board's groups, in its order. @var list<string> */
    public const array GROUPS = ['account', 'team', 'contact', 'internal'];

    /**
     * Every variable: its group in the editor's filter and whether it is a link (offered in the link box).
     *
     * @var array<string, array{group: string, link: bool}>
     */
    public const array VARS = [
        '{customer_name}' => ['group' => 'customer', 'link' => false],
        '{customer_email}' => ['group' => 'customer', 'link' => false],
        '{customer_phone}' => ['group' => 'customer', 'link' => false],
        '{verify_url}' => ['group' => 'account', 'link' => true],
        '{reset_url}' => ['group' => 'account', 'link' => true],
        '{account_url}' => ['group' => 'account', 'link' => true],
        '{change_time}' => ['group' => 'account', 'link' => false],
        '{change_ip}' => ['group' => 'account', 'link' => false],
        '{inviter_name}' => ['group' => 'account', 'link' => false],
        '{roles}' => ['group' => 'account', 'link' => false],
        '{invite_message}' => ['group' => 'account', 'link' => false],
        '{accept_url}' => ['group' => 'account', 'link' => true],
        '{expires_at}' => ['group' => 'account', 'link' => false],
        '{site_name}' => ['group' => 'website', 'link' => false],
        '{site_url}' => ['group' => 'website', 'link' => true],
        '{site_tagline}' => ['group' => 'website', 'link' => false],
        '{contact_email}' => ['group' => 'website', 'link' => false],
        '{hotline}' => ['group' => 'website', 'link' => false],
        '{address}' => ['group' => 'website', 'link' => false],
        '{message}' => ['group' => 'contact', 'link' => false],
        '{form_name}' => ['group' => 'contact', 'link' => false],
        '{submitted_at}' => ['group' => 'contact', 'link' => false],
        '{admin_url}' => ['group' => 'contact', 'link' => true],
    ];

    /** The editor's variable groups, in the board's order. @var list<string> */
    public const array VAR_GROUPS = ['customer', 'account', 'website', 'contact'];

    public const int SUBJECT_MAX = 200;

    public const int BODY_MAX = 60000;

    public static function has(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /**
     * @return array{group: string, icon: string, kind: string, i18n: bool, wired: bool, module: string|null, vars: list<string>, required: list<string>}|null
     */
    public static function get(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? null;
    }

    /** A website may change its wording (every kind but «Hệ thống»). */
    public static function editable(string $key): bool
    {
        $t = self::get($key);

        return $t !== null && $t['kind'] !== self::SYSTEM;
    }

    /** A website may turn it off. */
    public static function switchable(string $key): bool
    {
        return (self::get($key)['kind'] ?? null) === self::SWITCH;
    }

    public static function isLinkVar(string $token): bool
    {
        return self::VARS[$token]['link'] ?? false;
    }

    /**
     * The templates a website with these modules sees.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function visible(array $modules): array
    {
        $keys = [];
        foreach (self::TEMPLATES as $key => $t) {
            if ($t['module'] === null || in_array($t['module'], $modules, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
