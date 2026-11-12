<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use App\Modules\Shared\Support\Cast;

/**
 * How a member reaches the platform's support desk from an error page: phone, Zalo and email,
 * each only when configured (config/support.php). Read from config alone, so a page drawn while
 * the database is down or before any site is resolved still lists them.
 */
final class SupportChannels
{
    /**
     * @return array{phone: string, zalo: string, email: string, tel: string, zaloHref: string, mailto: string}
     */
    public static function all(): array
    {
        $phone = trim(Cast::string(config('support.phone')));
        $zalo = trim(Cast::string(config('support.zalo')));
        $email = trim(Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address')));
        $digits = static fn (string $value): string => (string) preg_replace('/[^0-9+]/', '', $value);

        return [
            'phone' => $phone,
            'zalo' => $zalo,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'tel' => $phone !== '' ? 'tel:'.$digits($phone) : '',
            'zaloHref' => $zalo !== '' ? 'https://zalo.me/'.$digits($zalo) : '',
            'mailto' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$email : '',
        ];
    }

    /**
     * The channels as links, in the design's order: call, Zalo, email.
     *
     * @return list<array{icon: string, label: string, href: string}>
     */
    public static function links(): array
    {
        $s = self::all();
        $links = [];
        if ($s['tel'] !== '') {
            $links[] = ['icon' => 'ri-phone', 'label' => __('errors.call', ['phone' => $s['phone']]), 'href' => $s['tel']];
        }
        if ($s['zaloHref'] !== '') {
            $links[] = ['icon' => 'ri-chat-3', 'label' => __('errors.zalo', ['zalo' => $s['zalo']]), 'href' => $s['zaloHref']];
        }
        if ($s['mailto'] !== '') {
            $links[] = ['icon' => 'ri-mail', 'label' => $s['email'], 'href' => $s['mailto']];
        }

        return $links;
    }
}
