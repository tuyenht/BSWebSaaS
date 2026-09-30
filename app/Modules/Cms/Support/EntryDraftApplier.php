<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Writes a pending change's content onto its entry (design.md §8): the primary category first, so
 * a live post's new address is composed under it, then each language — in locale order, never the
 * order jsonb returns — through EntryContentWriter, which holds the editor's checks and side
 * effects (route + 301 on a live slug change, block media usage). A changed category moves every
 * live language's address.
 *
 * On a live entry every language the change carries goes live with it, one that was still a draft
 * included: what the reviewer approved is what visitors get, and a live entry offers no other way
 * to publish a language. Otherwise it never touches a status, records no revision and does not
 * delete the change: approving, applying and folding differ exactly there, so each caller decides. Runs in the caller's
 * transaction, after the caller locked the entry row.
 *
 * @phpstan-type AppliedChange array{locales: list<string>, all_locales: bool, translations: list<EntryTranslation>, dropped_media: int, skipped: list<string>}
 */
final readonly class EntryDraftApplier
{
    /** The shared fields a revision snapshot carries (the category is written separately). */
    private const array SNAPSHOT_SHARED = ['template_override', 'cover_media_id', 'meta'];

    public function __construct(
        private EntryContentWriter $writer,
        private EntryWorkflowResolver $workflows,
        private RouteSync $routes,
        private SiteLocale $siteLocales,
    ) {}

    /**
     * Apply the change. Strict (approving, applying): content that no longer passes the checks, or
     * a language the site has switched off since, refuses the whole change. Lenient (folding into
     * content that is leaving the website): such a language, or such a category, is skipped and
     * reported, so taking content offline never fails on it.
     *
     * `all_locales` is true when a field every language shares (template, cover, meta, category)
     * changed — the caller then records every language's revision.
     *
     * @return AppliedChange
     *
     * @throws EntryDraftRejectedException content_invalid|category_governed|locale_disabled (strict only)
     * @throws RouteConflictException a new address of live content belongs to other content
     */
    public function apply(Entry $entry, EntryDraft $change, bool $lenient = false): array
    {
        $shared = $change->content['shared'] ?? [];
        $locales = $change->content['locales'];
        ksort($locales, SORT_STRING);

        $categoryChanged = false;
        try {
            $categoryChanged = $this->applyCategory($entry, $shared['primary_category_id'] ?? null);
        } catch (EntryDraftRejectedException $refused) {
            if (! $lenient) {
                throw $refused;
            }
        }

        $snapshotShared = array_intersect_key($shared, array_flip(self::SNAPSHOT_SHARED));
        $sharedChanged = false;
        $written = [];
        $applied = [];
        $skipped = [];
        $dropped = 0;

        foreach ($locales as $locale => $text) {
            $locale = (string) $locale;

            if (! $this->siteLocales->isEnabled($locale)) {
                if (! $lenient) {
                    throw new EntryDraftRejectedException('locale_disabled');
                }
                $skipped[] = $locale;

                continue;
            }

            $content = $this->writer->read(
                ['v' => RecordEntryRevision::FORMAT] + $snapshotShared + array_intersect_key($text, array_flip(EntryDraft::LOCALE_FIELDS)),
            );

            if ($content === null) {
                if (! $lenient) {
                    throw new EntryDraftRejectedException('content_invalid');
                }
                $skipped[] = $locale;

                continue;
            }

            [$content, $droppedHere] = $this->writer->withoutMissingMedia($content);
            $result = $this->writer->write($entry, $locale, $content);

            if ($entry->status === 'published' && $result['translation']->status !== 'published') {
                $result['translation']->update(['status' => 'published']);
                $this->routes->sync($entry, $locale, $result['translation']->slug);
            }

            $dropped += $droppedHere;
            $sharedChanged = $sharedChanged || $result['shared_changed'];
            $written[] = $result['translation'];
            $applied[] = $locale;
        }

        // The post's address is composed under its primary category: a new category moves every
        // live language, including those the change did not edit (a no-op where the permalink
        // pattern does not use the category).
        if ($categoryChanged && $entry->status === 'published') {
            foreach ($entry->translations()->where('status', 'published')->orderBy('locale')->get() as $translation) {
                $this->routes->sync($entry, $translation->locale, $translation->slug);
            }
        }

        return [
            'locales' => $applied,
            'all_locales' => $sharedChanged || $categoryChanged,
            'translations' => $written,
            'dropped_media' => $dropped,
            'skipped' => $skipped,
        ];
    }

    /**
     * A post may be filed under any post category, but one an active workflow governs may not be
     * re-filed out of that workflow's section — the same rule the editor applies, since the section
     * decides which workflow approves the post.
     *
     * @throws EntryDraftRejectedException content_invalid (not a post category) · category_governed
     */
    public function assertCategory(Entry $entry, int $categoryId): void
    {
        $target = Taxonomy::query()->whereKey($categoryId)->where('vocabulary', 'post_cat')->first()
            ?? throw new EntryDraftRejectedException('content_invalid');

        $current = $this->workflows->forEntry($entry);
        if ($current !== null && $this->workflows->forCategory($target)?->id !== $current->id) {
            throw new EntryDraftRejectedException('category_governed');
        }
    }

    /**
     * File the post under the change's primary category when it differs; returns whether it did.
     * Categories are replaced as the editor replaces them (the primary one only).
     *
     * @throws EntryDraftRejectedException see assertCategory()
     */
    private function applyCategory(Entry $entry, mixed $categoryId): bool
    {
        if ($entry->type !== 'post' || ! is_int($categoryId) || $categoryId <= 0) {
            return false;
        }

        if ($entry->primaryCategory()?->id === $categoryId) {
            return false;
        }

        $this->assertCategory($entry, $categoryId);
        $entry->assignCategories([$categoryId]);
        $entry->unsetRelation('taxonomies');

        return true;
    }
}
