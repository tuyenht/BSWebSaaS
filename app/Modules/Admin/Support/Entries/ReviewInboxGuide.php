<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\Guide\Guide;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;

/**
 * The guide of «Hộp duyệt»: the two tabs, finding a post in the list, and what the reader's bar does.
 * Steps point at the screen's data-guide="…" marks; a mark the screen did not draw (no post open, the
 * type filter hidden because the member sees one type) is skipped in the browser. Its text is
 * guide.inbox.*, the labels filled in by the controls themselves ([[Label]]).
 */
final class ReviewInboxGuide
{
    public static function for(User $user): Guide
    {
        $label = fn (string $key): string => '[['.Cast::string(__($key)).']]';
        $can = fn (string $permission): bool => $user->can($permission);

        $guide = new Guide(
            'review-inbox',
            Cast::string(__('admin.inbox.title')),
            ($can('entries.approve') ? 'a' : '').($can('entries.review') ? 'r' : ''),
            null,
            self::t('purpose'),
        );

        $guide->part('find', self::t('part_find'), [
            Guide::step('tabs', [Guide::on('tabs', self::t('tabs_title'), self::t('tabs', [
                'mine' => $label('admin.inbox.tab_mine'),
                'queue' => $label('admin.inbox.tab_queue'),
            ]))], intro: true),
            Guide::step('search', [Guide::on('search', self::t('search_title'), self::t('search'))]),
            Guide::step('type', [Guide::on('type', self::t('type_title'), self::t('type', ['all' => $label('admin.inbox.all_types')]))]),
            Guide::step('rows', [Guide::on('rows', self::t('rows_title'), self::t('rows', ['late' => Cast::string(__('admin.inbox.late'))]))]),
        ]);

        $guide->part('decide', self::t('part_decide'), [
            Guide::step('reader', [Guide::on('reader', self::t('reader_title'), self::t('reader'))]),
            Guide::step('bar', [Guide::on('bar', self::t('bar_title'), self::t('bar'))]),
            Guide::step('nav', [Guide::on('nav', self::t('nav_title'), self::t('nav'))]),
            Guide::step('reload', [Guide::on('reload', self::t('reload_title'), self::t('reload', ['reload' => $label('admin.inbox.reload')]))]),
        ]);

        return $guide;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        return Cast::string(__('guide.inbox.'.$key, $replace));
    }
}
