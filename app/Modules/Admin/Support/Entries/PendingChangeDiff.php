<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Carbon\CarbonImmutable;
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
     * @param  MediaRefs  $covers  the files the covers name by id (coverRefs, read once for a list of changes)
     * @return array{rows: list<array{code: string|null, label: string, now: string, seen: string}>, drafts: list<string>}
     */
    public function between(Entry $entry, EntryDraft $change, Collection $live, ?int $livePrimary, Closure $categoryNames, array $order, MediaRefs $covers): array
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
        // each cover as the file its cover_media_id names (from $covers), else the meta's key
        $changed = array_key_exists('meta', $shared) || MediaRefs::id($shared['cover_media_id'] ?? null) !== null;
        $cover = $changed
            ? EntryCover::resolve($shared['cover_media_id'] ?? null, $shared['meta'] ?? null, $covers)
            : EntryCover::value($entry, $covers);
        $liveCover = EntryCover::value($entry, $covers);
        if ($cover !== $liveCover) {
            $rows[] = ['code' => null, 'label' => Cast::string(__('entries.chg.f_cover')),
                'now' => $cover !== '' ? Cast::string(__('entries.chg.cover_other')) : $empty, 'seen' => $liveCover !== '' ? Cast::string(__('entries.chg.cover_other')) : $empty];
        }
        if ($entry->type === 'post') {
            // «Thuộc tính bài»: the post's own look and the day it shows as published
            $template = array_key_exists('template_override', $shared) ? Cast::stringOrNull($shared['template_override']) : $entry->template_override;
            if ($template !== $entry->template_override) {
                $rows[] = ['code' => null, 'label' => Cast::string(__('entries.rev.f_tpl')), 'now' => self::templateName($template), 'seen' => self::templateName($entry->template_override)];
            }
            $day = array_key_exists('meta', $shared) ? DisplayDate::of($shared['meta']) : DisplayDate::of($entry->meta);
            $liveDay = DisplayDate::of($entry->meta);
            if ($day !== $liveDay) {
                $rows[] = ['code' => null, 'label' => Cast::string(__('entries.ed.pd_label', ['doc' => Cast::string(__('entries.ed.doc_post'))])),
                    'now' => self::dayName($day), 'seen' => self::dayName($liveDay)];
            }
        }
        $primary = $shared['primary_category_id'] ?? null;
        if ($entry->type === 'post' && is_int($primary) && $primary !== $livePrimary) {
            $names = $categoryNames($livePrimary !== null ? [$primary, $livePrimary] : [$primary]);
            $rows[] = ['code' => null, 'label' => Cast::string(__('entries.chg.f_category')),
                'now' => $clip($names[$primary] ?? null), 'seen' => $clip($livePrimary !== null ? ($names[$livePrimary] ?? null) : null)];
        }

        return ['rows' => $rows, 'drafts' => $drafts];
    }

    /**
     * The files the covers of these changes and of their live entries name by id, in one read (none
     * while no cover is named by id), for between().
     *
     * @param  iterable<EntryDraft>  $changes  with their entry loaded
     */
    public static function coverRefs(iterable $changes): MediaRefs
    {
        $ids = [];
        foreach ($changes as $change) {
            $ids[] = $change->content['shared']['cover_media_id'] ?? null;
            $entry = $change->entry;
            if ($entry instanceof Entry) {
                $ids[] = $entry->cover_media_id;
            }
        }

        return MediaRefs::rows($ids);
    }

    /** A display day (Y-m-d) written as the website writes days, or «the real day» for none. */
    private static function dayName(string $day): string
    {
        $at = $day !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $day) : null;

        return $at instanceof CarbonImmutable
            ? SiteTime::formatDay($at, app(SiteTime::class)->dateFormat(), app()->getLocale())
            : Cast::string(__('entries.ed.pd_real'));
    }

    /** A post's template_override as «Mẫu hiển thị» names it. */
    private static function templateName(?string $template): string
    {
        return match ($template) {
            TemplateCatalog::VARIANT_HERO => Cast::string(__('entries.ed.tpl_hero', ['doc' => Cast::string(__('entries.ed.doc_post'))])),
            TemplateCatalog::VARIANT_WIDE => Cast::string(__('entries.ed.tpl_wide')),
            null, '' => Cast::string(__('entries.rev.tpl_default')),
            default => $template,
        };
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
