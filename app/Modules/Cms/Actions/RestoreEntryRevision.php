<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Writes an earlier revision's content back onto its entry (design.md §8). Guarded like an edit
 * (EntryPolicy::update, own/any), and written through the editor's own checks and side effects:
 * the same title/slug/block rules (EntryContentRules), the route moved with a 301 when a live
 * entry's slug changes (RouteSync), and the block image usage index rebuilt
 * (EntryBlockMediaReconciler).
 *
 * The status is never touched: restoring content is not publishing. On live content the change is
 * visible at once, exactly like an editor save — an approval workflow governs the decision to
 * publish, not each edit (design.md §15.4) — so the audit line records the status and workflow
 * stage the restore landed on.
 *
 * Media that has left the library since the revision was taken is dropped rather than refused: a
 * missing cover / OG image becomes none, and a block image prop is cleared (the block and its text
 * stay, as when the editor's "remove image" is used). The count of dropped references is returned
 * so the screen can say so.
 *
 * @phpstan-type RestorableContent array{title: string, slug: string, blocks: list<array{type: string, props: array<array-key, mixed>}>, excerpt: string|null, seo_title: string|null, seo_description: string|null, og_image_id: int|null, template_override: string|null, cover_media_id: int|null, meta: array<array-key, mixed>}
 */
final readonly class RestoreEntryRevision
{
    public const string AUDIT_ACTION = 'entries.revision.restored';

    /** Length of the varchar columns a snapshot's optional strings are written into. */
    private const int COLUMN_LIMIT = 255;

    /** entry_translations_status_check. */
    private const array TRANSLATION_STATES = ['draft', 'pending', 'published', 'archived'];

    public function __construct(
        private EntryContentRules $rules,
        private ComponentRegistry $registry,
        private RouteSync $routes,
        private EntryBlockMediaReconciler $media,
        private RecordEntryRevision $revisions,
        private SiteLocale $locales,
    ) {}

    /**
     * @return int how many media references were dropped because the media no longer exists
     *
     * @throws AuthorizationException the actor may not edit this entry
     * @throws RevisionRestoreRejectedException one of its REASONS
     */
    public function handle(EntryRevision $revision, User $actor): int
    {
        // Trashed entries are looked up too, so the refusal can say why instead of a 404.
        $entry = Entry::withTrashed()->whereKey($revision->entry_id)->firstOrFail();

        if (! Gate::forUser($actor)->allows('update', $entry)) {
            throw new AuthorizationException(__('entries.err_update_forbidden'));
        }

        if ($entry->trashed()) {
            throw new RevisionRestoreRejectedException('entry_trashed');
        }

        if (! $this->locales->isEnabled($revision->locale)) {
            throw new RevisionRestoreRejectedException('locale_disabled');
        }

        [$content, $dropped] = $this->withoutMissingMedia($this->readSnapshot($revision->snapshot));

        try {
            DB::transaction(fn () => $this->write($entry, $revision->locale, $content, $actor));
        } catch (RouteConflictException) {
            throw new RevisionRestoreRejectedException('slug_taken');
        }

        Audit::record(self::AUDIT_ACTION, [
            'auditable_type' => Entry::class,
            'auditable_id' => $entry->getKey(),
            'actor_id' => $actor->getKey(),
            'actor_label' => $actor->email,
            'meta' => [
                'entry_ulid' => $entry->ulid,
                'revision_ulid' => $revision->ulid,
                'locale' => $revision->locale,
                'status' => $entry->status,
                'workflow_state' => $entry->workflow_state,
                'dropped_media' => $dropped,
            ],
        ]);

        return $dropped;
    }

    /**
     * @param  RestorableContent  $content
     */
    private function write(Entry $entry, string $locale, array $content, User $actor): void
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

        // The shared fields (template, cover, meta) belong to every language's page: when the
        // restore changed them, each language's current state is recorded so its own history still
        // shows what it looked like. Other languages' text is never touched.
        if ($sharedChanged) {
            $this->revisions->forAllLocales($entry, RevisionKind::Restore, $actor);
        } else {
            $this->revisions->forLocale($entry, $translation, RevisionKind::Restore, $actor);
        }
    }

    /**
     * The snapshot as content that may be written today, or snapshot_incompatible. Its format
     * version must be one this code reads, every field must have its type and fit its column, and
     * the title, slug and blocks must pass the rules an editor save passes (title/slug ≤ 200).
     *
     * @param  array<array-key, mixed>  $snapshot
     * @return RestorableContent
     */
    private function readSnapshot(array $snapshot): array
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
                throw new RevisionRestoreRejectedException('snapshot_incompatible');
            }
            $strings[$key] = $value;
        }

        foreach (['og_image_id', 'cover_media_id'] as $key) {
            $value = $snapshot[$key] ?? null;
            if ($value !== null && ! (is_int($value) && $value > 0)) {
                throw new RevisionRestoreRejectedException('snapshot_incompatible');
            }
            $ids[$key] = $value;
        }

        if (($snapshot['v'] ?? null) !== RecordEntryRevision::FORMAT
            || ! is_string($title) || ! is_string($slug) || ! is_array($blocks) || ! is_array($meta)
            || Validator::make(['title' => $title, 'slug' => $slug, 'blocks' => $blocks], $this->rules->rules())->fails()) {
            throw new RevisionRestoreRejectedException('snapshot_incompatible');
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
     * cover key in meta is removed, block image props are cleared. Two queries at most.
     *
     * @param  RestorableContent  $content
     * @return array{0: RestorableContent, 1: int}
     */
    private function withoutMissingMedia(array $content): array
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
}
