<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Media\Models\Media;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use Illuminate\Support\Facades\Validator;

/**
 * Writes one language's content, in the shape RecordEntryRevision::snapshot takes it, onto an entry
 * through the editor's own checks and side effects (design.md §8): the same title/slug/block rules
 * (EntryContentRules), the route moved with a 301 when a live entry's slug changes (RouteSync), and
 * the block image usage index rebuilt (EntryBlockMediaReconciler). Every road that puts stored
 * content back onto an entry — a revision restore today — writes through here, so they cannot drift.
 *
 * Three steps, called in order: read() checks a snapshot can be written today, withoutMissingMedia()
 * drops references to media that has left the library, write() stores it. The status is never
 * touched and no revision is recorded: which revision kind (and for which languages) is the caller's
 * decision, made from what write() returns. write() runs in the caller's transaction and takes no
 * lock of its own.
 *
 * @phpstan-type EntryContent array{title: string, slug: string, blocks: list<array{type: string, props: array<array-key, mixed>}>, excerpt: string|null, seo_title: string|null, seo_description: string|null, og_image_id: int|null, template_override: string|null, cover_media_id: int|null, meta: array<array-key, mixed>}
 */
final readonly class EntryContentWriter
{
    /** Length of the varchar columns a snapshot's optional strings are written into. */
    private const int COLUMN_LIMIT = 255;

    /** entry_translations_status_check. */
    private const array TRANSLATION_STATES = ['draft', 'pending', 'published', 'archived'];

    public function __construct(
        private EntryContentRules $rules,
        private ComponentRegistry $registry,
        private RouteSync $routes,
        private EntryBlockMediaReconciler $media,
    ) {}

    /**
     * The snapshot as content that may be written today, or null when it cannot be. Its format
     * version must be one this code reads, every field must have its type and fit its column, and
     * the title, slug and blocks must pass the rules an editor save passes (title/slug ≤ 200).
     *
     * @param  array<array-key, mixed>  $snapshot
     * @return EntryContent|null
     */
    public function read(array $snapshot): ?array
    {
        $body = $snapshot['body'] ?? null;
        $blocks = is_array($body) ? ($body['blocks'] ?? []) : null;
        $meta = $snapshot['meta'] ?? [];
        $title = $snapshot['title'] ?? null;
        $slug = $snapshot['slug'] ?? null;
        $strings = [];
        $ids = [];

        foreach (['excerpt', 'seo_title', 'seo_description', 'template_override'] as $key) {
            $value = $snapshot[$key] ?? null;
            // varchar(255) columns: a longer value would fail the write with a database error.
            if ($value !== null && (! is_string($value) || mb_strlen($value) > self::COLUMN_LIMIT)) {
                return null;
            }
            $strings[$key] = $value;
        }

        foreach (['og_image_id', 'cover_media_id'] as $key) {
            $value = $snapshot[$key] ?? null;
            if ($value !== null && ! (is_int($value) && $value > 0)) {
                return null;
            }
            $ids[$key] = $value;
        }

        if (($snapshot['v'] ?? null) !== RecordEntryRevision::FORMAT
            || ! is_string($title) || ! is_string($slug) || ! is_array($blocks) || ! is_array($meta)
            || Validator::make(['title' => $title, 'slug' => $slug, 'blocks' => $blocks], $this->rules->rules())->fails()) {
            return null;
        }

        // Stored exactly as the editor stores a block: its type and its props, nothing else.
        $normalized = [];
        foreach ($blocks as $block) {
            if (is_array($block) && is_string($block['type'] ?? null)) {
                $normalized[] = ['type' => $block['type'], 'props' => is_array($block['props'] ?? null) ? $block['props'] : []];
            }
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'blocks' => $normalized,
            'excerpt' => $strings['excerpt'],
            'seo_title' => $strings['seo_title'],
            'seo_description' => $strings['seo_description'],
            'og_image_id' => $ids['og_image_id'],
            'template_override' => $strings['template_override'],
            'cover_media_id' => $ids['cover_media_id'],
            'meta' => $meta,
        ];
    }

    /**
     * Drop every reference to media no longer in this site's library (RLS-scoped, Trash excluded —
     * the same test the editor's pickers apply): the OG image and the cover id become null, the
     * cover key in meta is removed, block image props are cleared (the block and its text stay).
     * Returns the content and how many references were dropped. Two queries at most.
     *
     * @param  EntryContent  $content
     * @return array{0: EntryContent, 1: int}
     */
    public function withoutMissingMedia(array $content): array
    {
        $blockIds = [];
        foreach ($content['blocks'] as $i => $block) {
            $blockIds[$i] = SchemaImages::ids($block['props'], $this->registry->get($block['type'])->schema());
        }

        $referenced = array_merge([], ...array_values($blockIds));
        foreach ([$content['og_image_id'], $content['cover_media_id']] as $id) {
            if ($id !== null) {
                $referenced[] = $id;
            }
        }
        $referenced = array_values(array_unique($referenced));

        $existing = [];
        if ($referenced !== []) {
            foreach (Media::query()->whereIn('id', $referenced)->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $existing[] = (int) $id;
                }
            }
        }

        $dropped = 0;

        foreach ($content['blocks'] as $i => $block) {
            $missing = array_diff($blockIds[$i], $existing);
            if ($missing !== []) {
                $dropped += count($missing);
                $content['blocks'][$i]['props'] = SchemaImages::retain($block['props'], $this->registry->get($block['type'])->schema(), $existing);
            }
        }

        foreach (['og_image_id', 'cover_media_id'] as $key) {
            if ($content[$key] !== null && ! in_array($content[$key], $existing, true)) {
                $content[$key] = null;
                $dropped++;
            }
        }

        // The cover the storefront shows is the media's storage key in meta.image_url.
        $coverKey = $content['meta']['image_url'] ?? null;
        if ($coverKey !== null && (! is_string($coverKey) || ! Media::query()->where('storage_key', $coverKey)->exists())) {
            unset($content['meta']['image_url']);
            $dropped++;
        }

        return [$content, $dropped];
    }

    /**
     * Store the content: the shared fields (template, cover, meta) on the entry, the rest on the
     * locale's translation — created when the entry has lost that language since. Other languages'
     * text is never touched. Returns the saved translation and whether the shared fields changed
     * (they belong to every language's page, so the caller then records every language).
     *
     * @param  EntryContent  $content
     * @return array{translation: EntryTranslation, shared_changed: bool}
     *
     * @throws RouteConflictException the new address of live content belongs to other content
     */
    public function write(Entry $entry, string $locale, array $content): array
    {
        $entry->fill([
            'template_override' => $content['template_override'],
            'cover_media_id' => $content['cover_media_id'],
            'meta' => $content['meta'],
        ]);
        $sharedChanged = $entry->isDirty();
        $entry->save();

        $translation = $entry->translations()->firstOrNew(['locale' => $locale]);
        $previousSlug = $translation->exists ? $translation->slug : null;

        $translation->fill([
            'title' => $content['title'],
            'slug' => $content['slug'],
            'body' => ['blocks' => $content['blocks']],
            'excerpt' => $content['excerpt'],
            'seo_title' => $content['seo_title'],
            'seo_description' => $content['seo_description'],
            'og_image_id' => $content['og_image_id'],
        ]);
        if (! $translation->exists) {
            // A language the entry lost since: it comes back in the entry's status, as an editor save
            // creates it — within the states a translation can hold (no scheduled/private there).
            $translation->status = in_array($entry->status, self::TRANSLATION_STATES, true) ? $entry->status : 'draft';
        }
        $translation->save();

        // Live content at a new address: move the route and leave a 301, children included. Not
        // live = no route to move; the next publish checks the path.
        if ($entry->status === 'published' && $translation->status === 'published' && $previousSlug !== $translation->slug) {
            $this->routes->sync($entry, $locale, $translation->slug);
            $this->routes->syncDescendants($entry, $locale);
        }

        $this->media->reconcile($entry);

        return ['translation' => $translation, 'shared_changed' => $sharedChanged];
    }
}
