<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Support\LayoutSlotBlocks;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;

/**
 * What the block slots around a page need from the page: the blocks each slot holds for this page's
 * main category and language (LayoutSlotBlocks), and the data the slot-only blocks draw — the
 * newest posts of the post's root branch (read with the related posts, never separately), the
 * categories of that branch (two reads, only when a block asks for them) and the site's hotline and
 * email from its identity. Holds no state; every method reads only what it is handed or the
 * current site's rows.
 */
final readonly class SlotContext
{
    /** The slots of a post page: the side column and the band below the post. */
    public const array POST_SLOTS = ['post_aside', 'after_post'];

    /** The slots of a category list page (posts or products): the band above its list and the band below it. */
    public const array LIST_SLOTS = ['before_list', 'after_list'];

    /** The slots of a product page: before and after its order buttons, its shared parts, the band below it. */
    public const array PRODUCT_SLOTS = ['product_before_action', 'product_after_action', 'product_tabs', 'after_product'];

    /** The most categories the category list shows (a branch is a handful; the cap bounds a bad tree). */
    public const int CATEGORY_CAP = 50;

    public function __construct(
        private LayoutSlotBlocks $slots,
        private PathBuilder $paths,
    ) {}

    /**
     * The blocks of each named slot that holds any (a slot with none is left out).
     *
     * @param  list<string>  $slots
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function blocks(array $slots, string $locale, ?Taxonomy $category): array
    {
        $found = [];
        foreach ($slots as $slot) {
            $blocks = array_values(array_filter($this->slots->blocks($slot, $locale, $category), 'is_array'));
            if ($blocks !== []) {
                /** @var list<array<array-key, mixed>> $blocks */
                $found[$slot] = $blocks;
            }
        }

        return $found;
    }

    /**
     * The props of every category list the slots hold, with the source it draws and whether it
     * shows counts.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @return list<array{source: string, counts: bool}>
     */
    public static function lists(array $blocks): array
    {
        $found = [];
        foreach ($blocks as $list) {
            foreach ($list as $block) {
                if (($block['type'] ?? null) === 'category_list') {
                    $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                    $found[] = [
                        'source' => SectionProps::choice($props, 'source', SectionProps::CATEGORY_LIST_SOURCES),
                        'counts' => SectionProps::choice($props, 'counts', SectionProps::CATEGORY_LIST_COUNTS) === 'show',
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Whether any slot holds a block of $type.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     */
    public static function has(array $blocks, string $type): bool
    {
        foreach ($blocks as $list) {
            foreach ($list as $block) {
                if (($block['type'] ?? null) === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The data slot blocks draw. `recent` is handed in (PostDetail read it with the related posts);
     * the categories are read here only when a block asks for them.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @param  array<array-key, mixed>  $recent
     * @param  array<array-key, mixed>|null  $brand  the site identity (hotline, phone, email)
     * @param  int|null  $postId  the post being read (marked in the category's own post list)
     * @return array{recent: array<array-key, mixed>, categories: list<array{name: string, url: string, current: bool, count: int|null}>, posts: list<array{name: string, url: string, current: bool}>, contact: array{phone: string, call: string|null, email: string, mail: string|null}}
     */
    public function context(array $blocks, ?Taxonomy $category, string $locale, ?array $brand, array $recent = [], ?int $postId = null): array
    {
        $lists = self::lists($blocks);
        $wants = static fn (string $source): bool => array_filter($lists, static fn (array $list): bool => $list['source'] === $source) !== [];
        $counts = array_filter($lists, static fn (array $list): bool => $list['source'] === 'categories' && $list['counts']) !== [];

        return [
            'recent' => $recent,
            'categories' => $category !== null && $wants('categories') ? $this->categories($category, $locale, $counts) : [],
            // The same list block draws either kind: one that asks for posts reads the category's own posts.
            'posts' => $category !== null && $wants('posts') ? $this->categoryPosts($category, $locale, $postId) : [],
            'contact' => self::contact($brand),
        ];
    }

    /**
     * The hotline (else the main phone) and the email of the site, each with its safe link; a line the
     * settings leave empty (or that is no valid number or address) is empty here, and the block hides it.
     *
     * @param  array<array-key, mixed>|null  $brand
     * @return array{phone: string, call: string|null, email: string, mail: string|null}
     */
    public static function contact(?array $brand): array
    {
        $phone = '';
        $call = null;
        foreach (['hotline', 'phone'] as $field) {
            $value = Cast::string($brand[$field] ?? null);
            if (trim($value) !== '' && ($href = ButtonLink::tel($value)) !== null) {
                [$phone, $call] = [trim($value), $href];
                break;
            }
        }
        $email = trim(Cast::string($brand['email'] ?? null));
        $mail = SafeLink::mailto($email);

        return ['phone' => $phone, 'call' => $call, 'email' => $mail !== null ? $email : '', 'mail' => $mail];
    }

    /**
     * The categories below the root of $category's branch (the root itself when it has none), the
     * shallowest first, each with its address in the page's language, built from its slug the way
     * the breadcrumb builds it. Two reads (the branch, then the names and slugs of the language),
     * at most CATEGORY_CAP rows; a category without a name in the language is left out.
     *
     * With $counts, each carries the number of published posts filed under it as their main category.
     *
     * @return list<array{name: string, url: string, current: bool, count: int|null}>
     */
    private function categories(Taxonomy $category, string $locale, bool $counts = false): array
    {
        $first = explode('.', (string) $category->path)[0];
        $rootId = ctype_digit($first) ? (int) $first : $category->id;

        $rows = Taxonomy::query()
            ->select(['taxonomies.id', 'taxonomies.path', 'taxonomies.position'])
            ->where('vocabulary', $category->vocabulary)
            ->where(fn (Builder $branch) => $branch
                ->whereKey($rootId)
                ->orWhereRaw('taxonomies.path <@ (select root.path from taxonomies root where root.id = ?)', [$rootId]))
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)->select(['taxonomy_id', 'locale', 'name', 'slug'])])
            ->orderByRaw('nlevel(taxonomies.path), taxonomies.position, taxonomies.id')
            ->limit(self::CATEGORY_CAP + 1)
            ->get();
        if ($rows->count() > 1) {
            $rows = $rows->reject(fn (Taxonomy $row): bool => $row->id === $rootId);
        }

        $rows = $rows->take(self::CATEGORY_CAP);
        $totals = $counts ? $this->postCounts(array_values($rows->pluck('id')->all()), $locale) : [];

        $items = [];
        foreach ($rows as $row) {
            $translation = $row->translationFor($locale);
            if ($translation !== null && $translation->name !== '' && $translation->slug !== '') {
                $items[] = [
                    'name' => $translation->name,
                    'url' => $this->paths->publicUrl($this->paths->forTaxonomy($translation->slug), $locale),
                    'current' => $row->id === $category->id,
                    'count' => $counts ? ($totals[$row->id] ?? 0) : null,
                ];
            }
        }

        return $items;
    }

    /**
     * How many published posts (with a published text in the language) have each of these categories
     * as their main one: one grouped read over at most CATEGORY_CAP ids, on the pivot's
     * (site_id, taxonomy_id…) index.
     *
     * @param  list<mixed>  $ids
     * @return array<int, int>
     */
    private function postCounts(array $ids, string $locale): array
    {
        $ids = array_values(array_filter($ids, 'is_int'));
        if ($ids === []) {
            return [];
        }

        $totals = Entry::query()
            ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                ->where('entry_taxonomies.position', 0)
                ->whereIn('entry_taxonomies.taxonomy_id', $ids))
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'))
            ->groupBy('entry_taxonomies.taxonomy_id')
            ->selectRaw('entry_taxonomies.taxonomy_id as taxonomy_id, count(*) as total')
            ->pluck('total', 'taxonomy_id');

        $counts = [];
        foreach ($totals as $id => $total) {
            $counts[(int) $id] = is_numeric($total) ? (int) $total : 0;
        }

        return $counts;
    }

    /**
     * The published posts whose main category is $category and that have a published text in the
     * language, newest first (the order of the category's own list page), at most CATEGORY_CAP, each
     * with its stored address; the one being read is marked. Three reads (rows, texts, routes).
     *
     * @return list<array{name: string, url: string, current: bool}>
     */
    private function categoryPosts(Taxonomy $category, string $locale, ?int $postId): array
    {
        $rows = Entry::query()
            ->select(['entries.id', 'entries.site_id', 'entries.type', 'entries.published_at'])
            ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                ->where('entry_taxonomies.position', 0)
                ->where('entry_taxonomies.taxonomy_id', $category->id))
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'))
            ->with([
                'translations' => fn (Relation $query) => $query->where('locale', $locale),
                'routes' => fn (Relation $query) => $query->where('locale', $locale),
            ])
            ->orderByRaw('entries.published_at DESC NULLS LAST, entries.id DESC')
            ->limit(self::CATEGORY_CAP)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $translation = $row->translationFor($locale);
            if ($translation === null || $translation->title === '') {
                continue;
            }
            $route = $row->routes->first();
            $path = $route !== null ? $route->path : $this->paths->forEntry($row, $locale, trim((string) $translation->slug, '/'));
            $items[] = ['name' => $translation->title, 'url' => $this->paths->publicUrl($path, $locale), 'current' => $row->id === $postId];
        }

        return $items;
    }
}
