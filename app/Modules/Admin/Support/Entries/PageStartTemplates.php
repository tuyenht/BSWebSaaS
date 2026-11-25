<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Shared\Support\Cast;

/**
 * «Bắt đầu từ mẫu» (board 07 «Trình sửa trang»): the starts a new page is offered while its body is
 * still empty — Giới thiệu, Dịch vụ, Liên hệ, each a written body (one formatted-text block, as the
 * «Soạn thảo» tab stores it) with headings and suggested text, and «Trang trống», which writes
 * nothing. The words live in the language files, so a template is written in the language of the
 * version being started (the website's default one).
 */
final class PageStartTemplates
{
    public const string BLANK = 'blank';

    /** Each start, in the board's order, with its icon. */
    private const array ICONS = [
        'about' => 'ri-information',
        'service' => 'ri-briefcase',
        'contact' => 'ri-map-pin',
        self::BLANK => 'ri-file',
    ];

    /**
     * The buttons of the card, in the language of the screen.
     *
     * @return list<array{key: string, icon: string, label: string, desc: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::ICONS as $key => $icon) {
            $out[] = [
                'key' => $key,
                'icon' => $icon,
                'label' => Cast::string(__('entries.ed.start_'.$key)),
                'desc' => Cast::string(__('entries.ed.start_'.$key.'_d')),
            ];
        }

        return $out;
    }

    /**
     * What a start writes, in $locale: the title it gives an untitled page and the body (both empty
     * for «Trang trống»). Null for a key that is not one of the starts.
     *
     * @return array{title: string, html: string}|null
     */
    public static function content(string $key, string $locale): ?array
    {
        if (! array_key_exists($key, self::ICONS)) {
            return null;
        }
        if ($key === self::BLANK) {
            return ['title' => '', 'html' => ''];
        }

        return [
            'title' => Cast::string(__('entries.ed.start_'.$key, [], $locale)),
            'html' => Cast::string(__('entries.ed.start_'.$key.'_body', [], $locale)),
        ];
    }
}
