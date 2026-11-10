<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Admin\Support\TreeRows;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The tree the Chuyên mục screen draws for one section (CategorySections): the section's root and
 * the post categories under it, or every product category. Each row is keyed by its ulid (never a
 * raw id) and carries what the row and the editor drawer show — names, slugs, descriptions and
 * Google fields per language, the "Thuộc …" parent and the trail above it, the templates, the
 * picture, the item count (items_count) — plus what the viewer may do with it:
 *  - locked: a section's root, which always stays at level 1 and is never moved, merged or deleted;
 *  - editable / movable: TaxonomyPolicy update / move, so a member limited to some categories sees
 *    which rows are theirs (and which one their limits name).
 *
 * A fixed number of queries whatever the size of the tree: the categories, their translations,
 * the pictures (when there are any), the sections and the tree version. Rows made before keys
 * existed (no ulid yet) are left out; their sub-categories show at the outer level.
 */
final readonly class CategoryScreen
{
    public function __construct(
        private CategorySections $sections,
        private SiteLocale $locales,
        private MediaUrlResolver $urls,
    ) {}

    /**
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return array<string, mixed>
     */
    public function load(array $section, User $viewer): array
    {
        $forest = $this->forest($section);
        $ids = $this->shown($section, $forest);
        $main = $this->locales->default();
        $translations = $this->translations($ids);
        $covers = $this->covers($ids, $forest);
        $shownSet = array_flip($ids);

        $nodes = [];
        foreach ($ids as $id) {
            $row = $forest->get($id);
            if (! $row instanceof Taxonomy || $row->ulid === null) {
                continue;
            }
            $parent = $id === $section['root'] ? null : $forest->parentOf($id);
            $parentRow = $parent === null ? null : $forest->get($parent);
            $own = $translations[$id] ?? [];
            $trail = [];
            foreach ($forest->ancestors($id) as $above) {
                if (isset($shownSet[$above])) {
                    $trail[] = $this->nameOf($translations[$above] ?? [], $main);
                }
            }
            $locked = $forest->isSectionRoot($id);

            $nodes[] = [
                'key' => $row->ulid,
                'parent' => $parentRow?->ulid,
                'name' => $this->nameOf($own, $main),
                'names' => array_map(static fn (array $t): string => $t['name'], $own),
                'slugs' => array_map(static fn (array $t): string => $t['slug'], $own),
                'descriptions' => array_filter(array_map(static fn (array $t): string => $t['description'] ?? '', $own), static fn (string $v): bool => $v !== ''),
                'seoTitles' => array_filter(array_map(static fn (array $t): string => $t['seo_title'] ?? '', $own), static fn (string $v): bool => $v !== ''),
                'seoDescriptions' => array_filter(array_map(static fn (array $t): string => $t['seo_description'] ?? '', $own), static fn (string $v): bool => $v !== ''),
                'of' => $parentRow === null ? null : $this->nameOf($translations[$parentRow->id] ?? [], $main),
                'trail' => $trail,
                'listTemplate' => $row->list_template,
                'detailTemplate' => $row->detail_template,
                'cover' => $covers[$id] ?? null,
                'items' => $row->items_count,
                'locked' => $locked,
                'editable' => Gate::forUser($viewer)->allows('update', $row),
                'movable' => ! $locked && Gate::forUser($viewer)->allows('move', $row),
            ];
        }

        return [
            'section' => ['key' => $section['key'], 'label' => $section['label'], 'vocabulary' => $section['vocabulary']],
            'main' => $main,
            'locales' => $this->locales->enabled(),
            'version' => TaxonomyTree::version($section['vocabulary']),
            'limits' => [
                'depth' => TaxonomyRules::MAX_DEPTH, 'name' => TaxonomyRules::NAME_MAX, 'slug' => TaxonomyRules::SLUG_MAX,
                'description' => TaxonomyRules::DESCRIPTION_MAX, 'seo' => TaxonomyRules::SEO_MAX,
            ],
            'rows' => TreeRows::build($nodes),
        ];
    }

    /**
     * The keys of the rows whose name (in any language), slug or "Thuộc …" trail holds $query,
     * ignoring case and Vietnamese marks ("ban tin" finds "Bản tin"), in drawing order.
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return list<string>
     */
    public function search(array $section, string $query): array
    {
        $needle = self::fold($query);
        if ($needle === '') {
            return [];
        }

        $forest = $this->forest($section);
        $ids = $this->shown($section, $forest);
        $translations = $this->translations($ids);
        $order = array_flip($this->drawingOrder($section, $forest));

        $hits = [];
        foreach ($ids as $id) {
            $row = $forest->get($id);
            if (! $row instanceof Taxonomy || $row->ulid === null) {
                continue;
            }
            $haystack = [];
            foreach ([$id, ...$forest->ancestors($id)] as $at) {
                foreach ($translations[$at] ?? [] as $translation) {
                    $haystack[] = $translation['name'];
                    if ($at === $id) {
                        $haystack[] = $translation['slug'];
                    }
                }
            }
            if (str_contains(self::fold(implode("\n", $haystack)), $needle)) {
                $hits[$order[$id] ?? PHP_INT_MAX] = $row->ulid;
            }
        }
        ksort($hits);

        return array_values($hits);
    }

    /**
     * The vocabulary's categories, with every post section's root marked.
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     */
    public function forest(array $section): CategoryForest
    {
        $roots = [];
        if ($section['vocabulary'] === CategorySections::POST_VOCABULARY) {
            foreach ($this->sections->postSections() as $post) {
                $roots[$post['root']] = $post['key'];
            }
        }

        return CategoryForest::load($section['vocabulary'], $roots);
    }

    /**
     * The categories the section shows: the root and its branch, or the whole vocabulary.
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return list<int>
     */
    public function shown(array $section, CategoryForest $forest): array
    {
        if ($section['root'] === null) {
            return $this->drawingOrder($section, $forest);
        }

        return $forest->has($section['root']) ? $forest->branch($section['root']) : [];
    }

    /**
     * Each category's translations, by id then locale, in ONE query.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array{name: string, slug: string, description: string|null, seo_title: string|null, seo_description: string|null}>>
     */
    public function translations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = TaxonomyTranslation::query()->whereIn('taxonomy_id', $ids)->orderBy('taxonomy_id')->orderBy('locale')
            ->get(['taxonomy_id', 'locale', 'name', 'slug', 'description', 'seo_title', 'seo_description']);
        foreach ($rows as $row) {
            $out[$row->taxonomy_id][$row->locale] = [
                'name' => $row->name, 'slug' => (string) $row->slug,
                'description' => $row->description, 'seo_title' => $row->seo_title, 'seo_description' => $row->seo_description,
            ];
        }

        return $out;
    }

    /**
     * The name in the main language, else the first one the category has.
     *
     * @param  array<string, array{name: string, ...}>  $translations
     */
    public function nameOf(array $translations, string $main): string
    {
        return $translations[$main]['name'] ?? (($first = reset($translations)) !== false ? $first['name'] : '');
    }

    /**
     * The categories a category could be put under or merged into, grouped: per post section the
     * viewer may see (the one on screen first, then the others in display order) its root and
     * branch; for product categories one group, after the outer level (null) when $outerLevel.
     * The categories in $excluded (id → anything) are left out.
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @param  array<int, mixed>  $excluded
     * @return list<array{section: string|null, label: string, ids: list<int|null>}>
     */
    public function candidates(array $section, CategoryForest $forest, array $excluded, User $viewer, bool $outerLevel): array
    {
        if ($section['root'] === null) {
            $ids = $outerLevel ? [null] : [];
            foreach ($this->drawingOrder($section, $forest) as $id) {
                if (! isset($excluded[$id])) {
                    $ids[] = $id;
                }
            }

            return [['section' => null, 'label' => '', 'ids' => $ids]];
        }

        $all = $this->sections->postSections();
        usort($all, static fn (array $a, array $b): int => ($b['key'] === $section['key']) <=> ($a['key'] === $section['key']));

        $groups = [];
        foreach ($all as $post) {
            if (! $forest->has($post['root']) || ($post['key'] !== $section['key'] && ! Gate::forUser($viewer)->allows($post['prefix'].'.view'))) {
                continue;
            }
            $ids = array_values(array_filter($forest->branch($post['root']), static fn (int $id): bool => ! isset($excluded[$id])));
            $groups[] = ['section' => $post['key'], 'label' => $post['label'], 'ids' => $ids];
        }

        return $groups;
    }

    /** Lower case, Vietnamese marks and đ dropped, runs of space as one. */
    public static function fold(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))) ?? '');
    }

    /**
     * Every category of the vocabulary, a parent before its children.
     *
     * @param  array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}  $section
     * @return list<int>
     */
    private function drawingOrder(array $section, CategoryForest $forest): array
    {
        if ($section['root'] !== null && $forest->has($section['root'])) {
            return $forest->branch($section['root']);
        }

        $out = [];
        foreach ($forest->children(null) as $top) {
            array_push($out, ...$forest->branch($top));
        }

        return $out;
    }

    /**
     * The picture of each category that has one, as its media key and a small image address.
     *
     * @param  list<int>  $ids
     * @return array<int, array{key: string, url: string}>
     */
    private function covers(array $ids, CategoryForest $forest): array
    {
        $byMedia = [];
        foreach ($ids as $id) {
            $media = $forest->get($id)?->cover_media_id;
            if ($media !== null) {
                $byMedia[$media][] = $id;
            }
        }
        if ($byMedia === []) {
            return [];
        }

        $out = [];
        foreach (Media::query()->whereIn('id', array_keys($byMedia))->get(['id', 'storage_key']) as $media) {
            foreach ($byMedia[$media->id] as $id) {
                $out[$id] = ['key' => $media->storage_key, 'url' => $this->urls->url($media->storage_key, 400)];
            }
        }

        return $out;
    }
}
