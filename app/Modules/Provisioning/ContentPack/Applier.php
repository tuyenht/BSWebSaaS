<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
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
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Support\Carbon;

/**
 * Writes a plan's create / update / adopt items, in kind order (settings → profile → categories →
 * products → posts → pages → menus → redirects), inside the caller's tenant transaction. It writes
 * exactly the plan's `desired` state through the platform's own writers: RouteSync for every
 * address (301 + chain collapse), CategoryRelinker after a category rename, assignCategories() for
 * filing, RecordEntryRevision before and after an entry write (staff can restore the earlier text
 * from the editor's history), the media reconciler after a body write. Any exception rolls the
 * whole load back. The loader writes no person: actor null, no audit row per item.
 */
final class Applier
{
    /** @var array<string, int> pack key → record id (matched or created in this run) */
    private array $ids = [];

    private string $locale = '';

    public function __construct(
        private readonly RouteSync $routes,
        private readonly CategoryRelinker $relinker,
        private readonly TaxonomyPaths $paths,
        private readonly RecordEntryRevision $revisions,
        private readonly EntryBlockMediaReconciler $reconciler,
        private readonly StorefrontSettings $settings,
        private readonly PermalinkConfig $permalinks,
        private readonly SectionResolver $sections,
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
                'setting' => $this->setting($planned),
                'profile' => $this->profile($planned),
                'taxonomy' => $this->taxonomy($planned, $planner),
                'product' => $this->product($planned),
                'post', 'page' => $this->entry($planned),
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

    private function setting(PlanItem $planned): void
    {
        [$group, $name] = explode('.', $planned->item->anchor(), 2);
        $row = SiteSetting::query()->where('group_name', $group)->where('key', $name)->first();
        $value = isset($planned->item->data['managed_keys']) && $row !== null
            ? array_replace(Cast::arr($row->value), $planned->desired)
            : $planned->desired;
        if (isset($planned->item->data['managed_keys']) && $row !== null) {
            foreach (Cast::strings($planned->item->data['managed_keys']) as $managed) {
                if (! array_key_exists($managed, $planned->desired)) {
                    unset($value[$managed]);
                }
            }
        }

        SiteSetting::query()->updateOrCreate(['group_name' => $group, 'key' => $name], ['value' => $value]);
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
        ];

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
            $taxonomy->translations()->updateOrCreate(['locale' => (string) $locale], ['name' => Cast::string($row['name'] ?? null), 'slug' => Cast::string($row['slug'] ?? null)]);
            $this->routes->syncTaxonomy($taxonomy, (string) $locale, Cast::string($row['slug'] ?? null));
        }
        $this->paths->refreshBranch($taxonomy->id);

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

    private function entry(PlanItem $planned): void
    {
        $desired = $planned->desired;
        $status = Cast::string($desired['status'] ?? null);
        $isPost = $planned->item->kind === 'post';

        if ($planned->recordId === null) {
            $entry = Entry::query()->create([
                'type' => $planned->item->kind,
                'status' => $status,
                'published_at' => $this->publishedAt($planned->item, $status),
                'meta' => $isPost && is_string($desired['image'] ?? null) ? ['image_url' => $desired['image']] : [],
            ]);
        } else {
            $entry = Entry::withTrashed()->findOrFail($planned->recordId);
            if ($entry->trashed()) {
                $entry->restore();   // a forced re-create of an item staff put in the trash
            }
            $this->recordRevisions($entry);
            $meta = $entry->meta;
            if ($isPost) {
                if (is_string($desired['image'] ?? null)) {
                    $meta['image_url'] = $desired['image'];
                } else {
                    unset($meta['image_url']);
                }
            }
            $entry->fill(['status' => $status, 'meta' => $meta]);
            if ($status === 'published' && $entry->published_at === null) {
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
                'body' => Cast::arr($row['body'] ?? null),
                'excerpt' => Cast::stringOrNull($row['excerpt'] ?? null),
                'seo_title' => Cast::stringOrNull($row['seo_title'] ?? null),
                'seo_description' => Cast::stringOrNull($row['seo_description'] ?? null),
                'status' => Cast::string($row['status'] ?? null, $status),
            ]);

            $routed = SiteRoute::query()->where('entry_id', $entry->id)->where('locale', $locale)->exists();
            if (! $isPost || $status === 'published' || $routed) {
                $this->routes->sync($entry, $locale, $slug);
            }
        }

        $entry->unsetRelation('translations');
        $this->reconciler->reconcile($entry);
        $this->recordRevisions($entry);
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

        if ($planned->recordId === null) {
            $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
        } else {
            $product = Product::query()->findOrFail($planned->recordId);
            $product->status = $status;
            if ($status === 'published' && $product->published_at === null) {
                $product->published_at = now();
            }
            $product->save();
        }
        $this->ids[$planned->item->key] = $product->id;

        $product->assignCategories(array_map($this->id(...), Cast::strings($desired['categories'] ?? [])));
        $product->unsetRelation('taxonomies');

        $images = Cast::strings($desired['images'] ?? []);
        if ($images !== Cast::strings($planned->current['images'] ?? [])) {
            ProductImage::query()->where('product_id', $product->id)->delete();
            $media = Media::query()->whereIn('storage_key', $images)->pluck('id', 'storage_key');
            foreach ($images as $position => $key) {
                if (isset($media[$key])) {
                    ProductImage::query()->create(['product_id' => $product->id, 'media_id' => Cast::int($media[$key]), 'position' => $position]);
                }
            }
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

    private function redirect(PlanItem $planned): void
    {
        [$locale, $from] = explode(':', $planned->item->anchor(), 2);
        Redirect::query()->updateOrCreate(
            ['locale' => $locale, 'from_path' => $from],
            ['to_path' => Cast::string($planned->desired['to'] ?? null), 'status_code' => Cast::int($planned->desired['status'] ?? 301, 301)],
        );
    }
}
