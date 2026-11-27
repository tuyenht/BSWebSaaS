<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Admin\Support\Entries\SectionListViews;
use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductGallery;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\BlockUsageIndex;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Forms\Models\Form;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Provisioning\Support\FirstOwner;
use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Support\ThankYouPages;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\ContentSectionCategory;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Rendering\LayoutSlots;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Support\Carbon;

/**
 * Writes a plan's create / update / adopt items, in kind order (settings → profile → categories →
 * content sections → products → posts → pages → forms → menus → redirects), inside the caller's tenant transaction. It writes
 * exactly the plan's `desired` state through the platform's own writers: RouteSync for every
 * address (301 + chain collapse), CategoryRelinker after a category rename, assignCategories() for
 * filing, RecordEntryRevision before and after an entry write (staff can restore the earlier text
 * from the editor's history), the media reconciler after a body write. Every file the writes name
 * (a post's card image, a category's intro picture, a product's gallery, a download button's
 * document, a brand image) is read once for the whole load and stored by its library row: the cover
 * and gallery ids, the button's `media` ulid, the brand image on the site profile. Any exception rolls the
 * whole load back. The loader writes no person: actor null, no audit row per item, except the dates it
 * changes (publish time, end time, display day: the model records each, as the content pack's).
 */
final class Applier
{
    /** @var array<string, int> pack key → record id (matched or created in this run) */
    private array $ids = [];

    private string $locale = '';

    /** @var array<string, Media> storage key → this website's live row, for every file the plan's writes name */
    private array $media = [];

    public function __construct(
        private readonly RouteSync $routes,
        private readonly CategoryRelinker $relinker,
        private readonly TaxonomyPaths $paths,
        private readonly RecordEntryRevision $revisions,
        private readonly EntryBlockMediaReconciler $reconciler,
        private readonly StorefrontSettings $settings,
        private readonly PermalinkConfig $permalinks,
        private readonly SectionResolver $sections,
        private readonly BlockMediaUsage $blockUsage,
        private readonly BlockUsageIndex $blockPlaces,
        private readonly FirstOwner $owner,
    ) {}

    /** Returns how many items were written. */
    public function apply(Plan $plan, Planner $planner): int
    {
        $this->ids = [];
        $this->locale = $planner->defaultLocale();
        foreach ($plan->items as $planned) {
            $id = $planner->matchedId($planned->item->key);
            if ($id !== null) {
                $this->ids[$planned->item->key] = $id;
            }
        }

        $this->media = $this->mediaRows($plan, $planner);

        $written = 0;
        $settingsWritten = false;
        foreach ($plan->items as $planned) {
            if (! $planned->writes()) {
                continue;
            }
            if ($settingsWritten && $planned->item->kind !== 'setting') {
                $this->flushSettings();
                $settingsWritten = false;
            }

            match ($planned->item->kind) {
                'setting' => $this->setting($planned, $planner),
                'profile' => $this->profile($planned),
                'taxonomy' => $this->taxonomy($planned, $planner),
                'section' => $this->section($planned),
                'product' => $this->product($planned),
                'post', 'page' => $this->entry($planned, $planner),
                'form' => $this->form($planned, $planner),
                'menu' => $this->menu($planned, $planner),
                'redirect' => $this->redirect($planned),
                default => null,
            };
            $settingsWritten = $settingsWritten || $planned->item->kind === 'setting';
            $written++;
        }
        $this->flushSettings();

        return $written;
    }

    /**
     * Every file the plan's writes name by its storage key, read in ONE query for the whole load:
     * this website's rows outside the Trash (the Media scopes: site_id and deleted_at), FOR KEY SHARE
     * so a purge of any of them waits for the load's transaction. No query when none is named.
     *
     * @return array<string, Media> storage key → row
     */
    private function mediaRows(Plan $plan, Planner $planner): array
    {
        $keys = [];
        foreach ($plan->items as $planned) {
            if (! $planned->writes()) {
                continue;
            }
            $desired = $planned->desired;
            $kind = $planned->item->kind;
            if ($kind === 'post' || $kind === 'page') {
                $image = $desired['image'] ?? null;
                if (MediaRefs::looksLikeKey($image)) {
                    $keys[] = $image;   // a glyph or an outside address names no file
                }
                array_push($keys, ...self::fileKeys($desired['translations'] ?? null));
            } elseif ($kind === 'taxonomy') {
                $keys[] = $desired['cover'] ?? null;
            } elseif ($kind === 'product') {
                array_push($keys, ...Cast::strings($desired['images'] ?? []));
            } elseif ($planned->item->key === Planner::BRAND_ITEM) {
                foreach (Planner::BRAND_IMAGES as $slot) {
                    $keys[] = $planner->mintedKey($desired[$slot] ?? null);
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys, fn (mixed $key): bool => is_string($key) && $key !== '')));
        if ($keys === []) {
            return [];
        }

        $rows = [];
        foreach (Media::query()->whereIn('storage_key', $keys)->lock('for key share')->get(['id', 'ulid', 'storage_key', 'mime']) as $row) {
            $rows[$row->storage_key] = $row;
        }

        return $rows;
    }

    /**
     * The storage key of every download button's file in a value, in the form the plan writes
     * (`{"k": "file", "file": <storage key>}`).
     *
     * @return list<string>
     */
    private static function fileKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        if (($value['k'] ?? null) === 'file') {
            return is_string($value['file'] ?? null) ? [$value['file']] : [];
        }
        $keys = [];
        foreach ($value as $child) {
            array_push($keys, ...self::fileKeys($child));
        }

        return $keys;
    }

    /**
     * A body with every download button naming its document by its library row (`media`, the row's
     * ulid) in place of the storage key the plan holds. The plan read the document as one of this
     * website's; one that left the library while the load ran stops the load, never in silence.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function filesByRow(array $value, string $at): array
    {
        if (($value['k'] ?? null) === 'file' && is_string($value['file'] ?? null)) {
            $row = $this->media[$value['file']] ?? null;
            if ($row === null || ! in_array($row->mime, ButtonLink::DOCUMENT_MIMES, true)) {
                throw new PackRejected("Refused: plan changed, run the dry run again (a download file of {$at} left the library while the load ran; nothing was changed).");
            }
            unset($value['file']);
            $value['media'] = $row->ulid;

            return $value;
        }

        return array_map(fn (mixed $child): mixed => is_array($child) ? $this->filesByRow($child, $at) : $child, $value);
    }

    /**
     * The brand setting with each image the pack writes (Planner::BRAND_IMAGES) moved to the
     * site profile as its picture, the way the settings screen stores one (SetBrandImages): the
     * profile column names the row and the address leaves the setting. Only an address the plan
     * wrote for a picture of this website moves; any other value stays as it is. An image the item
     * owns (Planner::brandImagesOwned) that its value leaves out or states null ends with none: the
     * profile stops naming a picture there, as the address left the setting before brand images
     * moved to the profile (the picture itself stays in the library). An image the item does not
     * own is never touched.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $desired
     * @param  list<string>|null  $managed
     * @return array<array-key, mixed>
     */
    private function brandImages(array $value, array $desired, ?array $managed, Planner $planner): array
    {
        $ids = [];
        foreach (Planner::brandImagesOwned($managed) as $slot) {
            if (($desired[$slot] ?? null) === null) {
                $ids[SiteProfile::IMAGE_COLUMNS[$slot]] = null;

                continue;
            }
            $key = $planner->mintedKey($desired[$slot]);
            $row = $key === null ? null : ($this->media[$key] ?? null);
            if ($row !== null && str_starts_with($row->mime, 'image/')) {
                $ids[SiteProfile::IMAGE_COLUMNS[$slot]] = $row->id;
                unset($value[$slot]);
            }
        }
        if ($ids === []) {
            return $value;
        }
        $profile = SiteProfile::query()->first();
        if ($profile === null && array_filter($ids) !== []) {
            $profile = new SiteProfile;
        }
        $profile?->fill($ids)->save();

        return $value;
    }

    private function flushSettings(): void
    {
        $this->settings->forget();
        $this->permalinks->flush();
        $this->sections->flush();
    }

    private function id(string $key): int
    {
        return $this->ids[$key] ?? throw new PackRejected("Refused: {$key} is needed by another item but does not exist on the site.");
    }

    private function setting(PlanItem $planned, Planner $planner): void
    {
        [$group, $name] = explode('.', $planned->item->anchor(), 2);
        $row = SiteSetting::query()->where('group_name', $group)->where('key', $name)->first();
        $managed = $planned->item->managedKeys();
        $value = $managed !== null && $row !== null
            ? array_replace(Cast::arr($row->value), $planned->desired)
            : $planned->desired;
        if ($managed !== null && $row !== null) {
            foreach ($managed as $slot) {
                if (! array_key_exists($slot, $planned->desired)) {
                    unset($value[$slot]);
                }
            }
        }
        if ($planned->item->key === Planner::BRAND_ITEM) {
            $value = $this->brandImages($value, $planned->desired, $managed, $planner);
        }

        $saved = SiteSetting::query()->updateOrCreate(['group_name' => $group, 'key' => $name], ['value' => $value]);
        if ($planned->item->key === 'setting:storefront.layout_blocks') {
            $this->blockUsage->sync('site_setting', Cast::int($saved->getKey()), LayoutSlots::blockLists($saved->value));
            $this->blockPlaces->syncSiteSetting($saved, $saved->value);
        }
        if ($planned->item->key === 'setting:storefront.footer') {
            BranchList::syncUsage(Cast::int($saved->getKey()), Cast::arr($saved->value)['factories'] ?? null);
        }
        if ($planned->item->key === 'setting:storefront.floating_contact') {
            FloatingContactInput::syncIconUsage(Cast::int($saved->getKey()), $saved->value);
        }
        if ($planned->item->key === 'setting:theme.heading_decor') {
            HeadingDecorInput::syncMaskUsage(Cast::int($saved->getKey()), $saved->value);
        }
    }

    private function profile(PlanItem $planned): void
    {
        $profile = SiteProfile::query()->firstOrCreate([], []);
        $fields = $planned->desired;
        $translations = Cast::arr($fields['translations'] ?? null);
        unset($fields['translations']);
        $profile->fill($fields)->save();

        foreach ($translations as $locale => $row) {
            $row = array_filter(Cast::arr($row), fn (mixed $value): bool => $value !== null);
            if ($row !== []) {
                $profile->translations()->updateOrCreate(['locale' => (string) $locale], $row);
            }
        }
    }

    private function taxonomy(PlanItem $planned, Planner $planner): void
    {
        $desired = $planned->desired;
        $parentKey = Cast::stringOrNull($desired['parent'] ?? null);
        $attributes = [
            'vocabulary' => Cast::string($desired['vocabulary']),
            'parent_id' => $parentKey === null ? null : $this->id($parentKey),
            'position' => Cast::int($desired['position'] ?? null),
            'list_template' => Cast::stringOrNull($desired['list_template'] ?? null),
            'detail_template' => Cast::stringOrNull($desired['detail_template'] ?? null),
        ];
        // The intro picture: the media row holding the stored file (a pack that names none keeps the live one).
        $cover = is_string($desired['cover'] ?? null) ? ($this->media[$desired['cover']] ?? null) : null;
        if ($cover !== null) {
            $attributes['cover_media_id'] = $cover->id;
        }

        if ($planned->recordId === null) {
            $taxonomy = Taxonomy::query()->create($attributes);
        } else {
            $taxonomy = Taxonomy::query()->findOrFail($planned->recordId);
            $taxonomy->update($attributes);
        }
        $this->ids[$planned->item->key] = $taxonomy->id;

        foreach (Cast::arr($desired['translations'] ?? null) as $locale => $row) {
            $row = Cast::arr($row);
            if ($row === []) {
                continue;
            }
            // A pack that names the intro for this language sets it (an empty one clears it); a pack that does not keeps the live one.
            $stated = array_key_exists((string) $locale, Cast::arr($planned->item->data['description'] ?? null));
            // The card label the same way.
            $labelStated = array_key_exists((string) $locale, Cast::arr($planned->item->data['card_label'] ?? null));
            // The Google title and description the same way.
            $seo = [];
            foreach (['seo_title', 'seo_description'] as $field) {
                if (array_key_exists((string) $locale, Cast::arr($planned->item->data[$field] ?? null))) {
                    $seo[$field] = Cast::stringOrNull($row[$field] ?? null);
                } elseif (is_string($row[$field] ?? null)) {
                    $seo[$field] = $row[$field];
                }
            }
            $taxonomy->translations()->updateOrCreate(['locale' => (string) $locale], [
                'name' => Cast::string($row['name'] ?? null),
                'slug' => Cast::string($row['slug'] ?? null),
            ] + ($stated ? ['description' => Cast::stringOrNull($row['description'] ?? null)] : (is_string($row['description'] ?? null) ? ['description' => $row['description']] : []))
              + ($labelStated ? ['card_label' => Cast::stringOrNull($row['card_label'] ?? null)] : (is_string($row['card_label'] ?? null) ? ['card_label' => $row['card_label']] : []))
              + $seo);
            $this->routes->syncTaxonomy($taxonomy, (string) $locale, Cast::string($row['slug'] ?? null));
        }
        $this->paths->refreshBranch($taxonomy->id);

        // The category's own block slots: written to its meta (other keys stay) and listed in the
        // usage indexes (the pictures, and where shared blocks are placed), as the admin does when it saves them.
        $layout = Cast::arr($desired['layout_blocks'] ?? null);
        if ($layout !== []) {
            Taxonomy::query()->whereKey($taxonomy->id)->update(['meta->'.LayoutSlots::META_KEY => $layout]);
            $this->blockUsage->sync('taxonomy', $taxonomy->id, LayoutSlots::blockLists($layout));
            $this->blockPlaces->syncTaxonomy($taxonomy, $layout);
        }

        // A slug change in any language, a new parent, or both move the posts and products under the
        // branch: relinked with every change it went through, as the Planner predicted them. A post
        // or product filed there after the re-plan would move unplanned (no backup of its old address,
        // no address check), so the whole load stops instead.
        $changes = $planned->recordId === null ? [] : PlanItem::taxonomyChanges($planned->current, $desired);
        if ($changes !== []) {
            $this->sections->flush();
            $branch = array_keys(TaxonomyTree::branchOf($taxonomy->id));
            if ($planner->unplannedBranchRoutes($planned->item->key, $branch, ...$changes) !== []) {
                throw new PackRejected("Refused: plan changed, run the dry run again (a post or product was filed under {$planned->item->key} while the load ran; nothing was changed).");
            }
            $this->relinker->relink($branch, ...$changes);
        }
    }

    /**
     * A content section, as provisioning makes one: a post section on its category, with the entries
     * permissions every provisioned section uses (roles reach it through entries.*; there is no
     * per-section grant). An adopted section keeps its key, kind, category and permissions; only its
     * label, icon and place in the menu change. The section memo is dropped so the posts written next
     * are addressed under it.
     */
    private function section(PlanItem $planned): void
    {
        if ($planned->verdict === PlanItem::REMOVE) {
            $this->removeSection($planned);

            return;
        }
        $desired = $planned->desired;
        $attributes = [
            'label' => Cast::string($desired['label'] ?? null),
            'icon' => Cast::stringOrNull($desired['icon'] ?? null),
            'sort_order' => Cast::int($desired['sort_order'] ?? null),
        ];

        if ($planned->recordId === null) {
            $section = ContentSection::query()->create($attributes + [
                'key' => $planned->item->anchor(),
                'entry_type' => 'post',
                'taxonomy_id' => $this->id(Cast::string($desired['category'] ?? null)),
                'permission_prefix' => 'entries',
            ]);
        } else {
            $section = ContentSection::query()->findOrFail($planned->recordId);
            $section->update($attributes);
        }
        // The other categories it covers beside its first one (the saved hook keeps that one). Only
        // added: the plan refuses a pack that would take one off. A category the load's removed
        // section held is free by now: removals are applied first. One the section already covers is
        // left as it is (a rerun); one another section took meanwhile stops the load, never in silence.
        foreach (Cast::strings($desired['more_categories'] ?? []) as $more) {
            $categoryId = $this->id($more);
            ContentSectionCategory::query()->insertOrIgnore([
                'site_id' => Cast::int($section->site_id),
                'content_section_id' => $section->id,
                'taxonomy_id' => $categoryId,
            ]);
            $owner = ContentSectionCategory::query()->where('taxonomy_id', $categoryId)->value('content_section_id');
            if ($owner === null || Cast::int($owner) !== $section->id) {
                throw new PackRejected("Refused: plan changed, run the dry run again ({$more} was given to another section while the load ran; nothing was changed).");
            }
        }
        $this->ids[$planned->item->key] = $section->id;
        $this->sections->flush();
    }

    /**
     * A section the pack removes, as planned (the backup holds its row and the saved views): the row
     * goes, and with it every member's saved view of its post list. Its posts belong from now on to
     * the section above their category, which composes their addresses as it did (the plan's
     * condition), so no route changes. A workflow keyed to it stays as it is: switched off (the plan
     * refuses an active one), it governs nothing.
     */
    private function removeSection(PlanItem $planned): void
    {
        $section = ContentSection::query()->findOrFail($planned->recordId);
        SectionListViews::prune(Cast::int($section->site_id), $section->key);
        // Its permalink setting goes with it (backed up), so a later section under the key starts
        // from the default; its categories go with the row (content_section_categories cascades).
        SiteSetting::query()->where('group_name', 'permalinks')->where('key', $section->key)->delete();
        $section->delete();
        $this->permalinks->flush();
        $this->sections->flush();
    }

    /**
     * A post's cover and meta with what the pack manages (the card image, the short facts, the badge) set
     * or removed; every other key staff or the editor put there is kept. The card image is the post's one
     * cover (as EntryCover::stored writes it): a key of this website's picture is written as its row in
     * cover_media_id with no meta.image_url beside it, a glyph or a key with no live file stays in the
     * meta with no id, and an image the pack unsets leaves neither. The file comes from the load's one
     * read of its files (FOR KEY SHARE, so a purge of it waits).
     *
     * @param  array<array-key, mixed>  $meta
     * @param  array<string, mixed>  $desired
     * @return array{cover_media_id: int|null, meta: array<array-key, mixed>}
     */
    private function postCover(array $meta, array $desired): array
    {
        foreach (['image' => 'image_url', 'facts' => 'facts', 'badge' => 'badge'] as $from => $to) {
            $value = $desired[$from] ?? null;
            if ($from === 'image' ? is_string($value) : (is_array($value) && $value !== [])) {
                $meta[$to] = $value;
            } else {
                unset($meta[$to]);
            }
        }
        $key = EntryCover::metaValue($meta);
        $row = MediaRefs::looksLikeKey($key) ? ($this->media[$key] ?? null) : null;
        if ($row === null) {
            return ['cover_media_id' => null, 'meta' => $meta];
        }
        unset($meta['image_url']);

        return ['cover_media_id' => $row->id, 'meta' => $meta];
    }

    private function entry(PlanItem $planned, Planner $planner): void
    {
        $desired = $planned->desired;
        $status = Cast::string($desired['status'] ?? null);
        $isPost = $planned->item->kind === 'post';
        // A page whose pack item names a parent (or null) gets it; one that names none keeps its own. The
        // page tree's rules hold for a pack as for a move in the admin (PageRules): checked under the
        // site's page-tree lock, which the load command already took before planning (taking it again in
        // the same transaction is a no-op), so a move made meanwhile cannot slip past them.
        $parent = [];
        if (! $isPost && array_key_exists('parent', $desired)) {
            PageRules::lock(app(CurrentSite::class)->id());
            $parentId = $this->parentPage(Cast::stringOrNull($desired['parent']), $planner);
            $this->checkPageTree($planned, $parentId, Cast::stringOrNull($desired['parent']));
            $parent = ['parent_id' => $parentId];
        }

        if ($planned->recordId === null) {
            $entry = Entry::query()->create([
                'type' => $planned->item->kind,
                'author_id' => $this->owner->id(),   // a pack item has no typist: the website's first owner
                'status' => $status,
                'published_at' => $this->publishedAt($planned->item, $status),
            ] + ($isPost ? $this->postCover([], $desired) : ['meta' => []]) + $parent);
        } else {
            $entry = Entry::withTrashed()->findOrFail($planned->recordId);
            if ($entry->trashed()) {
                $entry->restore();   // a forced re-create of an item staff put in the trash
            }
            $this->recordRevisions($entry);
            // Past its end time («Gỡ khỏi website lúc») a reload never puts it back up: it keeps the status
            // it has (the sweep takes a still-live one down) and its dates, so no pack date crosses the end.
            $expired = $entry->expires_at !== null && $entry->expires_at <= now();
            if ($expired && $status === 'published') {
                $status = $entry->status;
            }
            $entry->fill(['status' => $status] + ($isPost ? $this->postCover(Cast::arr($entry->meta), $desired) : []) + $parent);
            if ($status === 'published' && ! $expired && $entry->published_at === null) {
                $entry->published_at = $this->publishedAt($planned->item, $status);
            }
            // a date the pack item states wins over the live one (an item without a date keeps it)
            if ($status === 'published' && ! $expired && is_string($desired['published_at'] ?? null)
                && $entry->published_at?->copy()->utc()->format('Y-m-d') !== $desired['published_at']) {
                $entry->published_at = $this->publishedAt($planned->item, $status);
            }
            $entry->save();
        }
        $this->ids[$planned->item->key] = $entry->id;

        if ($isPost) {
            $entry->assignCategories(array_map($this->id(...), Cast::strings($desired['categories'] ?? [])));
            $entry->unsetRelation('taxonomies');
        }

        foreach (Cast::arr($desired['translations'] ?? null) as $locale => $row) {
            $row = Cast::arr($row);
            if ($row === []) {
                continue;
            }
            $locale = (string) $locale;
            $existing = EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', $locale)->first();
            $slug = $isPost ? Cast::string($row['slug'] ?? null) : ($existing->slug ?? self::pageSlug($planned->item->anchor()));
            $entry->translations()->updateOrCreate(['locale' => $locale], [
                'title' => Cast::string($row['title'] ?? null),
                'slug' => $slug,
                'body' => $this->filesByRow(Cast::arr($row['body'] ?? null), $planned->item->key),
                'excerpt' => Cast::stringOrNull($row['excerpt'] ?? null),
                'seo_title' => Cast::stringOrNull($row['seo_title'] ?? null),
                'seo_description' => Cast::stringOrNull($row['seo_description'] ?? null),
                'status' => Cast::string($row['status'] ?? null, $status),
            ] + ($isPost ? ['author_name' => Cast::stringOrNull($row['author'] ?? null)] : []));

            $routed = SiteRoute::query()->where('entry_id', $entry->id)->where('locale', $locale)->exists();
            if (! $isPost || $status === 'published' || $routed) {
                $this->routes->sync($entry, $locale, $slug);
            }
        }

        $entry->unsetRelation('translations');
        $this->reconciler->reconcile($entry);
        $this->recordRevisions($entry);
    }

    /** The parent page's id: written earlier in this load, matched, or a live page outside the pack. */
    private function parentPage(?string $key, Planner $planner): ?int
    {
        if ($key === null) {
            return null;
        }

        return $this->ids[$key] ?? $planner->liveTarget($key)
            ?? throw new PackRejected("Refused: {$key} is needed as a parent page but does not exist on the site.");
    }

    /**
     * Refuse a pack page the page tree cannot hold: under the Home page (it has no sub-pages), the Home
     * page itself under a parent, under itself or a page below it, or deeper than PageRules::MAX_DEPTH
     * levels with the pages already under it (those in the Trash included).
     */
    private function checkPageTree(PlanItem $planned, ?int $parentId, ?string $parentKey): void
    {
        if ($parentId === null) {
            return;
        }
        $key = $planned->item->key;
        $existing = $planned->recordId === null ? null : Entry::withTrashed()->find($planned->recordId);
        // the page already sits there: re-applying a pack never refuses the tree it built (nor one that predates the rules)
        if ($existing instanceof Entry && $existing->parent_id === $parentId) {
            return;
        }
        // a new page is the Home page when its key names the root (`page:/`, or a home / empty slug)
        if ($existing instanceof Entry ? HomePages::is($existing) : in_array(trim($planned->item->anchor(), '/'), [PathBuilder::HOME_SLUG, ''], true)) {
            throw new PackRejected("Refused: {$key} is the Home page and cannot sit under {$parentKey}.");
        }
        $parent = Entry::withTrashed()->find($parentId);
        if (! $parent instanceof Entry) {
            throw new PackRejected("Refused: {$parentKey} is needed as a parent page but does not exist on the site.");
        }
        if (HomePages::is($parent)) {
            throw new PackRejected("Refused: {$key} cannot sit under {$parentKey}, the Home page (it has no sub-pages).");
        }
        if ($existing instanceof Entry && ($parent->id === $existing->id || in_array($existing->id, PageRules::ancestorsOf($parent), true))) {
            throw new PackRejected("Refused: {$key} cannot sit under {$parentKey}, which is itself or one of its own sub-pages.");
        }
        $height = $existing instanceof Entry ? PageRules::heightOf($existing) : 1;
        if ($height === null || PageRules::levelOf($parent) + $height > PageRules::MAX_DEPTH) {
            throw new PackRejected('Refused: '.$key.' under '.$parentKey.' would make the page tree deeper than '.PageRules::MAX_DEPTH.' levels.');
        }
    }

    private function recordRevisions(Entry $entry): void
    {
        foreach (EntryTranslation::query()->where('entry_id', $entry->id)->get() as $translation) {
            $this->revisions->forLocale($entry, $translation, RevisionKind::Edit, null);
        }
    }

    private function publishedAt(PackItem $item, string $status): ?Carbon
    {
        $date = Cast::stringOrNull($item->data['published_at'] ?? null);
        if ($date !== null) {
            return Carbon::createFromFormat('!Y-m-d', $date, 'UTC');
        }

        return $status === 'published' ? now() : null;
    }

    private static function pageSlug(string $path): string
    {
        $slug = trim($path, '/');

        return $slug === '' ? 'home' : $slug;
    }

    private function product(PlanItem $planned): void
    {
        $desired = $planned->desired;
        $status = Cast::string($desired['status'] ?? null);

        // the flag only when the pack item sets it: one that names none keeps the live flag
        $featured = is_bool($desired['featured'] ?? null) ? ['is_featured' => $desired['featured']] : [];

        if ($planned->recordId === null) {
            $product = Product::query()->create(['status' => $status, 'published_at' => $this->publishedAt($planned->item, $status)] + $featured);
        } else {
            $product = Product::query()->findOrFail($planned->recordId);
            // the product row before its translations, like every write of a name or a SKU
            ProductSearchWords::lock($product->site_id, $product->id);
            // Past its end time a reload never puts it back on sale: it keeps the status it has (the
            // sweep takes a still-live one down) and its dates, so no pack date crosses the end.
            $expired = $product->expires_at !== null && $product->expires_at <= now();
            if ($expired && $status === 'published') {
                $status = $product->status;
            }
            $product->fill(['status' => $status] + $featured);
            if ($status === 'published' && ! $expired && $product->published_at === null) {
                $product->published_at = $this->publishedAt($planned->item, $status);
            }
            // a date the pack item states wins over the live one (an item without a date keeps it)
            if ($status === 'published' && ! $expired && is_string($desired['published_at'] ?? null)
                && $product->published_at?->copy()->utc()->format('Y-m-d') !== $desired['published_at']) {
                $product->published_at = $this->publishedAt($planned->item, $status);
            }
            $product->save();
        }
        $this->ids[$planned->item->key] = $product->id;

        $product->assignCategories(array_map($this->id(...), Cast::strings($desired['categories'] ?? [])));
        $product->unsetRelation('taxonomies');

        $images = Cast::strings($desired['images'] ?? []);
        if ($images !== Cast::strings($planned->current['images'] ?? [])) {
            $ids = [];
            foreach ($images as $key) {
                if (isset($this->media[$key])) {
                    $ids[] = $this->media[$key]->id;
                }
            }
            app(ProductGallery::class)->sync($product, $ids);
        }

        foreach (Cast::arr($desired['translations'] ?? null) as $locale => $row) {
            $row = Cast::arr($row);
            if ($row === []) {
                continue;
            }
            $locale = (string) $locale;
            $slug = Cast::string($row['slug'] ?? null);
            $product->translations()->updateOrCreate(['locale' => $locale], [
                'name' => Cast::string($row['name'] ?? null),
                'slug' => $slug,
                'summary' => Cast::stringOrNull($row['summary'] ?? null),
                'description' => Cast::stringOrNull($row['description'] ?? null),
                'seo_title' => Cast::stringOrNull($row['seo_title'] ?? null),
                'seo_description' => Cast::stringOrNull($row['seo_description'] ?? null),
            ]);

            $routed = SiteRoute::query()->where('product_id', $product->id)->where('locale', $locale)->exists();
            if ($status === 'published' || $routed) {
                $this->routes->syncProduct($product->id, $product->primaryCategory(), $locale, $slug);
            }
        }
    }

    /**
     * The form's fields as planned — each named field placed, a platform field's example and a
     * field the pack adds written whole, the field's other options kept (a checkbox drops the
     * example it had as another type: it shows none) — and the thank-you page stored by its ulid
     * next to the form's other settings. Fields the pack does not name stay. A platform field or
     * consent box the form lacks is never created here (the plan refuses it as a conflict).
     */
    private function form(PlanItem $planned, Planner $planner): void
    {
        $form = Form::query()->findOrFail($planned->recordId);
        $live = $form->fields()->get()->keyBy('key');

        foreach (Cast::arr($planned->desired['fields'] ?? null) as $key => $state) {
            $state = Cast::arr($state);
            $row = $live->get((string) $key);
            $options = Cast::arr($row?->getAttribute('options'));
            $attributes = ['sort_order' => Cast::int($state['sort_order'] ?? null)];
            if (array_key_exists('type', $state)) {
                $attributes += [
                    'type' => Cast::string($state['type']),
                    'label' => Cast::arr($state['label'] ?? null),
                    'is_required' => ($state['is_required'] ?? false) === true,
                ];
                $choices = Cast::arr($state['choices'] ?? null);
                if ($choices === []) {
                    unset($options['choices']);
                } else {
                    $options['choices'] = $choices;
                }
                if ($attributes['type'] === 'checkbox') {
                    unset($options['placeholder']);
                }
            } elseif ($row === null) {
                throw new PackRejected("Refused: {$planned->item->key} places field {$key}, which the site's form lacks; the loader never creates a platform field or the consent box.");
            }
            if (array_key_exists('placeholder', $state)) {
                $options['placeholder'] = Cast::arr($state['placeholder']);
            }
            $attributes['options'] = $options;

            if ($row === null) {
                $form->fields()->create(['key' => (string) $key] + $attributes);
            } else {
                $row->update($attributes);
            }
        }

        if (array_key_exists('redirect_entry', $planned->desired)) {
            $page = Cast::string($planned->desired['redirect_entry']);
            $id = $this->ids[$page] ?? $planner->liveTarget($page);
            $ulid = $id === null ? null : Entry::query()->whereKey($id)->where('type', 'page')->value('ulid');
            if (! is_string($ulid)) {
                throw new PackRejected("Refused: {$planned->item->key} opens {$page} after a send, which does not exist on the site.");
            }
            $form->update(['settings' => array_replace(Cast::arr($form->settings), ['redirect_entry' => $ulid])]);
            ThankYouPages::forget($form->site_id);
        }
    }

    private function menu(PlanItem $planned, Planner $planner): void
    {
        $region = $planned->item->anchor();
        if ($planned->recordId === null) {
            $handle = "{$region}-{$this->locale}";
            $taken = Menu::query()->where('handle', 'like', $handle.'%')->pluck('handle')->all();
            for ($n = 2; in_array($handle, $taken, true); $n++) {
                $handle = "{$region}-{$this->locale}-{$n}";
            }
            $menu = Menu::query()->create(['handle' => $handle, 'name' => Cast::string($planned->item->data['name'] ?? null, $region), 'locales' => null]);
            // The region shows the pack's menu now, not another region's (one target per row).
            MenuAssignment::query()->updateOrCreate(['region_key' => $region, 'locale' => $this->locale], ['menu_id' => $menu->id, 'same_as_region' => null]);
        } else {
            $menu = Menu::query()->findOrFail($planned->recordId);
            // The pack's tree replaces the whole menu, its trash included: a soft delete would fill the
            // trash on every re-apply. forceDelete() skips the model's scopes, hence the explicit site.
            MenuItem::query()->where('site_id', $menu->site_id)->where('menu_id', $menu->id)->forceDelete();
        }

        $this->menuNodes($menu, Cast::arr($planned->desired['items'] ?? null), null, $planner);
    }

    /** @param array<array-key, mixed> $nodes */
    private function menuNodes(Menu $menu, array $nodes, ?int $parentId, Planner $planner): void
    {
        foreach (array_values($nodes) as $position => $node) {
            $node = Cast::arr($node);
            $link = Cast::string($node['link'] ?? null);
            $attributes = ['menu_id' => $menu->id, 'parent_id' => $parentId, 'position' => $position, 'routable_type' => null, 'routable_id' => null, 'url' => null, 'dynamic_key' => null];

            if (str_starts_with($link, 'url:')) {
                $attributes += ['link_type' => 'url'];
                $attributes['url'] = substr($link, 4);
            } elseif (str_starts_with($link, 'dynamic:')) {
                $attributes += ['link_type' => 'dynamic'];
                $attributes['dynamic_key'] = substr($link, 8);
            } else {
                [$type, $id] = $this->target(substr($link, 7), $planner);
                $attributes += ['link_type' => $type];
                $attributes['routable_type'] = $type;
                $attributes['routable_id'] = $id;
            }

            $icon = Cast::string($node['icon'] ?? null);
            if ($icon !== '') {
                $attributes['meta'] = ['icon' => $icon];
            }

            $item = MenuItem::query()->create($attributes);
            foreach (Cast::arr($node['label'] ?? null) as $locale => $label) {
                $item->translations()->create(['locale' => (string) $locale, 'label' => Cast::string($label)]);
            }
            $this->menuNodes($menu, Cast::arr($node['children'] ?? null), $item->id, $planner);
        }
    }

    /** @return array{0: string, 1: int} */
    private function target(string $key, Planner $planner): array
    {
        $type = match (true) {
            str_starts_with($key, 'tax:') => 'taxonomy',
            str_starts_with($key, 'product:') => 'product',
            default => 'entry',
        };
        $id = $this->ids[$key] ?? $planner->liveTarget($key);
        if ($id === null) {
            throw new PackRejected("Refused: the menu links to {$key}, which does not exist on the site.");
        }

        return [$type, $id];
    }

    /**
     * A member's redirect to another website is never overwritten (Redirect::writeSystemRow): one
     * saved at this address after the plan was made refuses the load, as a conflict the dry run
     * would now show.
     */
    private function redirect(PlanItem $planned): void
    {
        [$locale, $from] = explode(':', $planned->item->anchor(), 2);
        $written = Redirect::writeSystemRow($locale, $from,
            ['to_path' => Cast::string($planned->desired['to'] ?? null), 'status_code' => Cast::int($planned->desired['status'] ?? 301, 301)]);
        if (! $written) {
            throw new PackRejected("Refused: plan changed, run the dry run again ({$from} now leads to another website; only a member changes it, on the redirects screen; nothing was changed).");
        }
    }
}
