<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What differs between a live entry's pending change and what visitors see (boards 07 and 17 «So
 * sánh»): per language the six text fields (the body as plain text), then the picture and the main
 * category, shared by every language; and the languages the change would put live for the first time
 * (still a draft). One definition for the editor and the review inbox, so «N chỗ sửa» is the same
 * number on both.
 *
 * It reads nothing itself: the caller hands it what visitors see, so a list of changes answers from
 * rows it loaded once for all of them. Stateless (Octane).
 */
final readonly class PendingChangeDiff
{
    /** The per-language text fields compared, in board order (the body comes after them). */
    private const array TEXT_FIELDS = ['title', 'slug', 'excerpt', 'seo_title', 'seo_description'];

    /**
     * @param  Collection<string, EntryTranslation>  $live  the entry's translations visitors may see, keyed by locale
     * @param  int|null  $livePrimary  the post's main category as stored (null for a page)
     * @param  Closure(list<int>): array<array-key, string>  $categoryNames  category id => name, for the ids asked
     * @param  list<string>  $order  the website's languages in their order
     * @return array{rows: list<array{code: string|null, label: string, now: string, seen: string}>, drafts: list<string>}
     */
    public function between(Entry $entry, EntryDraft $change, Collection $live, ?int $livePrimary, Closure $categoryNames, array $order): array
    {
        $locales = array_map(strval(...), array_keys($change->content['locales']));
        $empty = Cast::string(__('entries.chg.diff_empty'));
        $clip = static fn (?string $text): string => $text === null || trim($text) === '' ? $empty : Str::limit($text, 160);
        $lines = app(EntryBodyLines::class);
        $rows = [];
        $drafts = [];
        usort($locales, static fn (string $a, string $b): int => (array_search($a, $order, true) ?: 0) <=> (array_search($b, $order, true) ?: 0));
        foreach ($locales as $code) {
            $text = $change->content['locales'][$code];
            $seen = $live->get($code);
            if (! $seen instanceof EntryTranslation || $seen->status !== 'published') {
                $drafts[] = $code;
            }
            foreach (self::TEXT_FIELDS as $field) {
                $now = Cast::stringOrNull($text[$field] ?? null);
                $was = $seen instanceof EntryTranslation ? Cast::stringOrNull($seen->getAttribute($field)) : null;
                if ((string) $now !== (string) $was) {
                    $rows[] = ['code' => $code, 'label' => Cast::string(__('entries.chg.f_'.$field)), 'now' => $clip($now), 'seen' => $clip($was)];
                }
            }
            $body = $text['body'] ?? null;
            $nowBlocks = is_array($body) && is_array($body['blocks'] ?? null) ? $body['blocks'] : [];
            $wasBlocks = $seen instanceof EntryTranslation ? ($seen->body['blocks'] ?? []) : [];
            if (self::keysSorted($nowBlocks) !== self::keysSorted($wasBlocks)) {
                $rows[] = ['code' => $code, 'label' => Cast::string(__('entries.chg.f_body')),
                    'now' => $clip($lines->plain($nowBlocks)), 'seen' => $clip($lines->plain($wasBlocks))];
            }
        }

        $shared = $change->content['shared'] ?? [];
        $cover = array_key_exists('meta', $shared) ? data_get($shared['meta'], 'image_url') : data_get($entry->meta, 'image_url');
        $liveCover = data_get($entry->meta, 'image_url');
        if ($cover !== $liveCover) {
            $rows[] = ['code' => null, 'label' => Cast::string(__('entries.chg.f_cover')),
                'now' => is_string($cover) ? Cast::string(__('entries.chg.cover_other')) : $empty, 'seen' => is_string($liveCover) ? Cast::string(__('entries.chg.cover_other')) : $empty];
        }
        $primary = $shared['primary_category_id'] ?? null;
        if ($entry->type === 'post' && is_int($primary) && $primary !== $livePrimary) {
            $names = $categoryNames($livePrimary !== null ? [$primary, $livePrimary] : [$primary]);
            $rows[] = ['code' => null, 'label' => Cast::string(__('entries.chg.f_category')),
                'now' => $clip($names[$primary] ?? null), 'seen' => $clip($livePrimary !== null ? ($names[$livePrimary] ?? null) : null)];
        }

        return ['rows' => $rows, 'drafts' => $drafts];
    }

    /** The main category a change files its post under, when it holds one. */
    public static function targetCategory(EntryDraft $change): ?int
    {
        $primary = $change->content['shared']['primary_category_id'] ?? null;

        return is_int($primary) ? $primary : null;
    }

    private static function keysSorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::keysSorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
