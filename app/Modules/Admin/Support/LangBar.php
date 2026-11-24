<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Lang;

/**
 * What the shared language bar (<x-bs-admin::lang-bar>, board 07 «Bài viết / Trang — trình sửa», the
 * same bar on board 09 for products) shows for each of the site's enabled languages: its name, the
 * state of its version, how many of the translatable fields it has filled, and where it opens.
 *
 * The states come from what is already stored, with no column of their own:
 *   source   the site's default language, the one the others translate from;
 *   empty    no version yet, or none of its translatable fields filled (a source without a version
 *            is empty too, and keeps its star);
 *   stale    the source's content changed after this version's content last did (the whole
 *            version is compared, never field by field);
 *   partial  some of the translatable fields filled;
 *   saved    every translatable field filled.
 * The board's "AI vừa dịch" and "Đang dịch…" states are never drawn: there is no translation tool.
 *
 * A screen reads its rows once per load (and again after it saves), then hands them here.
 */
final class LangBar
{
    public const array KINDS = ['source', 'empty', 'partial', 'stale', 'saved'];

    /** More languages than this: the bar draws one selector and its panel, never the pills. */
    public const int PILLS_MAX = 10;

    /** From this many languages the panel has a search box. */
    public const int SEARCH_FROM = 6;

    /** The state of one version (see the class comment). */
    public static function kind(bool $isSource, bool $exists, int $done, int $total, ?CarbonImmutable $mark, ?CarbonImmutable $sourceMark): string
    {
        if ($isSource) {
            return $exists ? 'source' : 'empty';
        }
        if (! $exists || $done === 0) {
            return 'empty';
        }
        if ($mark !== null && $sourceMark !== null && $mark->lessThan($sourceMark)) {
            return 'stale';
        }

        return $done < $total ? 'partial' : 'saved';
    }

    /** A language's name in the admin's language ("Tiếng Anh"), else its own name ("English"). */
    public static function name(string $code): string
    {
        $key = 'entries.language_'.$code;

        return Lang::has($key) ? (string) __($key) : Locales::label($code);
    }

    /**
     * One item per enabled language, in the site's order.
     *
     * @param  array<string, array{done: int, mark: CarbonImmutable|null, live: bool}>  $rows  the stored versions, by language
     * @param  list<string>  $enabled  the site's enabled languages, in order
     * @param  list<string>  $public  the languages visitors can open
     * @param  Closure(string): string  $href  where a language's version opens
     * @return list<array{code: string, label: string, kind: string, done: int, total: int, href: string, current: bool, source: bool, public: bool, live: bool}>
     */
    public static function items(array $rows, array $enabled, string $default, array $public, string $current, int $total, Closure $href): array
    {
        $sourceMark = $rows[$default]['mark'] ?? null;
        $items = [];
        foreach ($enabled as $code) {
            $row = $rows[$code] ?? null;
            $done = $row['done'] ?? 0;
            $items[] = [
                'code' => $code,
                'label' => self::name($code),
                'kind' => self::kind($code === $default, $row !== null, $done, $total, $row['mark'] ?? null, $sourceMark),
                'done' => $done,
                'total' => $total,
                'href' => $href($code),
                'current' => $code === $current,
                'source' => $code === $default,
                'public' => in_array($code, $public, true),
                'live' => $row['live'] ?? false,
            ];
        }

        return $items;
    }
}
