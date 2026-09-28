<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a revision is written (design.md §8). Every road that puts an entry live
 * (PublishEntry, PublishScheduledEntry, ApplyEntryWorkflowTransition), a save while it is live,
 * and a restore call it — an arch test holds the publishing roads to it.
 *
 * What a snapshot holds: the locale's title, slug, body, excerpt, SEO fields and OG image, plus
 * the entry's shared template_override, cover_media_id and meta. NOT status / workflow_state /
 * published_at (restoring content is not a change of status), categories (a category can move
 * the entry into another section and so under another approval workflow — that stays the job of
 * the category write path), author or position. `v` is the snapshot format, so a reader can tell
 * an older shape apart once the format grows.
 *
 * A snapshot identical to the newest revision of the same (entry, locale) is not written again:
 * a save that changed nothing, or a publish right after a save, leaves one row. The comparison is
 * jsonb equality in the database (key order and number spelling do not matter), in the same query
 * that finds the newest row. It runs in the caller's transaction, so a publish or save that rolls
 * back leaves no revision behind; site_id is written explicitly, never inferred.
 */
final readonly class RecordEntryRevision
{
    public const int FORMAT = 1;

    /** Record every locale the entry has (a publish makes the whole entry public); returns how many rows were written. */
    public function forAllLocales(Entry $entry, RevisionKind $kind, ?User $actor): int
    {
        $written = 0;

        foreach ($entry->translations()->orderBy('locale')->get() as $translation) {
            $written += $this->forLocale($entry, $translation, $kind, $actor) ? 1 : 0;
        }

        return $written;
    }

    /** Record one locale; false when it matches the newest revision and nothing was written. */
    public function forLocale(Entry $entry, EntryTranslation $translation, RevisionKind $kind, ?User $actor): bool
    {
        $snapshot = self::snapshot($entry, $translation);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $newest = DB::table('entry_revisions')
            ->where('site_id', $entry->site_id)
            ->where('entry_id', $entry->id)
            ->where('locale', $translation->locale)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1)
            ->selectRaw('snapshot = ?::jsonb as same', [$encoded])
            ->first();

        if ($newest !== null && (bool) ($newest->same ?? false)) {
            return false;
        }

        EntryRevision::query()->create([
            'site_id' => $entry->site_id,
            'entry_id' => $entry->id,
            'locale' => $translation->locale,
            'kind' => $kind,
            'snapshot' => $snapshot,
            'created_by' => $actor?->id,
        ]);

        return true;
    }

    /**
     * The content of one locale as it stands now.
     *
     * @return array{v: int, title: string, slug: string, body: array<array-key, mixed>, excerpt: string|null, seo_title: string|null, seo_description: string|null, og_image_id: int|null, template_override: string|null, cover_media_id: int|null, meta: array<array-key, mixed>}
     */
    public static function snapshot(Entry $entry, EntryTranslation $translation): array
    {
        $body = $translation->getAttribute('body');
        $meta = $entry->getAttribute('meta');

        return [
            'v' => self::FORMAT,
            'title' => (string) $translation->title,
            'slug' => (string) $translation->slug,
            'body' => is_array($body) ? $body : [],
            'excerpt' => self::stringOrNull($translation->getAttribute('excerpt')),
            'seo_title' => self::stringOrNull($translation->getAttribute('seo_title')),
            'seo_description' => self::stringOrNull($translation->getAttribute('seo_description')),
            'og_image_id' => self::intOrNull($translation->getAttribute('og_image_id')),
            'template_override' => self::stringOrNull($entry->getAttribute('template_override')),
            'cover_media_id' => self::intOrNull($entry->getAttribute('cover_media_id')),
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
