<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

/**
 * The paging, filtering and card data of a category list page (the controller stays small).
 *
 * Paging never counts and never reads deep: a page reads twelve rows plus one (to know whether
 * another page follows), ordered newest first with the id as tie-break. Pages 1–50 are numbered
 * (`?page=N`, an offset of at most 588 rows on an index walk); page 50's "next" continues by
 * keyset from the last row's public ulid (`?sau=<ulid>`), forward only. A page number outside
 * 1–50 or a malformed, unknown or other-site ulid is a 404.
 *
 * The child-category chips are links to the children's own pages (flat URLs, one canonical each).
 * Every read runs under the site's row-level security and the models' explicit site scope, so a
 * category, row or anchor of another site resolves to nothing.
 */
final readonly class ListPage
{
    public const int PER_PAGE = 12;

    public const int MAX_PAGE = 50;

    /** The widths a card photo offers through srcset. */
    private const array CARD_SRCSET_WIDTHS = [320, 640, 960, 1280];

    /** A card photo's default width (cards show about 408px wide). */
    private const int CARD_WIDTH = 640;

    public function __construct(
        private PathBuilder $paths,
        private MediaUrlResolver $media,
    ) {}

    /**
     * Where the visitor is: a numbered page, or the rows after an anchor ulid. A value that is not
     * a whole number reads as page 1, as before; a number outside 1–50 or a malformed ulid is a 404
     * before any query.
     *
     * @return array{page: int, after: string|null}
     */
    public function position(Request $request): array
    {
        $after = $request->query('sau');
        if ($after !== null) {
            abort_unless(is_string($after) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $after) === 1, 404);

            return ['page' => self::MAX_PAGE + 1, 'after' => strtoupper($after)];
        }

        $page = $request->query('page');
        $number = is_string($page) ? filter_var($page, FILTER_VALIDATE_INT) : false;
        if ($number === false) {
            return ['page' => 1, 'after' => null];
        }
        abort_unless($number >= 1 && $number <= self::MAX_PAGE, 404);

        return ['page' => $number, 'after' => null];
    }

    /**
     * Order the list newest first (id tie-break), window it to the position and read one row more
     * than a page.
     *
     * @template TModel of Entry|Product
     *
     * @param  Builder<TModel>|BelongsToMany<TModel, Taxonomy>  $query
     * @param  class-string<TModel>  $model  the anchor's model (its ulid is looked up under RLS)
     * @param  array{page: int, after: string|null}  $position
     * @return array{rows: Collection<int, TModel>, more: bool}
     */
    public function window(Builder|BelongsToMany $query, string $model, array $position): array
    {
        $table = (new $model)->getTable();
        $query->orderByDesc($table.'.published_at')->orderByDesc($table.'.id');

        if ($position['after'] !== null) {
            $anchor = $model::query()->where('ulid', $position['after'])->first(['id', 'published_at']);
            abort_if($anchor === null, 404);
            $publishedAt = $anchor->getRawOriginal('published_at');
            if ($publishedAt === null) {
                // Rows without a date sort first (DESC); after such an anchor come the older ids
                // without a date, then every dated row.
                $query->where(fn ($rows) => $rows->whereNotNull($table.'.published_at')->orWhere($table.'.id', '<', $anchor->id));
            } else {
                $query->whereRaw($table === 'products'
                    ? '(products.published_at, products.id) < (?::timestamptz, ?)'
                    : '(entries.published_at, entries.id) < (?::timestamptz, ?)', [$publishedAt, $anchor->id]);
            }
        } else {
            $query->offset(($position['page'] - 1) * self::PER_PAGE);
        }

        /** @var Collection<int, TModel> $rows */
        $rows = $query->limit(self::PER_PAGE + 1)->get();

        return ['rows' => $rows->take(self::PER_PAGE)->values(), 'more' => $rows->count() > self::PER_PAGE];
    }

    /**
     * The prev / next (and, on a keyset page, "back to the first page") links of a list page.
     *
     * @param  array{page: int, after: string|null}  $position
     * @return array{prevUrl: string|null, nextUrl: string|null, firstUrl?: string}
     */
    public function links(string $base, array $position, bool $more, ?string $lastUlid): array
    {
        $keyset = $more && $lastUlid !== null ? $base.'?sau='.$lastUlid : null;
        if ($position['after'] !== null) {
            return ['prevUrl' => null, 'nextUrl' => $keyset, 'firstUrl' => $base];
        }

        $page = $position['page'];
        $next = $more ? ($page < self::MAX_PAGE ? $base.'?page='.($page + 1) : $keyset) : null;

        return ['prevUrl' => $page > 1 ? $base.'?page='.($page - 1) : null, 'nextUrl' => $next];
    }

    /**
     * Whether a category sits under the catalog root (its ltree path holds the root's id, or its
     * parent is the root). Pure: the path labels are ids.
     */
    public function isUnder(Taxonomy $category, int $rootId): bool
    {
        if ($category->id === $rootId) {
            return false;
        }

        return $category->parent_id === $rootId
            || in_array((string) $rootId, explode('.', (string) $category->path), true);
    }

    /**
     * The ids of a category and every category below it, as a subquery (no read of its own). A
     * category whose path was never written still matches itself.
     *
     * @return Builder<Taxonomy>
     */
    public function subtree(Taxonomy $category): Builder
    {
        return Taxonomy::query()
            ->select('taxonomies.id')
            ->where(fn (Builder $ids) => $ids
                ->whereKey($category->id)
                ->orWhereRaw('taxonomies.path <@ (select parent.path from taxonomies parent where parent.id = ?)', [$category->id]));
    }

    /**
     * The child-category chips and the names of the categories read for them (the card labels).
     * The chip parent is the category itself when it has children, else its parent. One read for
     * the family, one for their names, one for their routes; a child without a route in the
     * locale gets no chip, and no child with a route means no chips at all.
     *
     * @return array{chips: list<array{name: string, url: string, current: bool, all: bool}>, labels: array<int, string>}
     */
    public function chips(Taxonomy $current, string $locale): array
    {
        $parentId = $current->parent_id;
        $family = Taxonomy::query()
            ->where(function (Builder $family) use ($current, $parentId): void {
                $family->whereKey($current->id)->orWhere('parent_id', $current->id);
                if ($parentId !== null) {
                    $family->orWhere('taxonomies.id', $parentId)->orWhere('parent_id', $parentId);
                }
            })
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $labels = [];
        foreach ($family as $category) {
            $name = $category->translationFor($locale)?->name;
            if (is_string($name) && $name !== '') {
                $labels[$category->id] = $name;
            }
        }

        $hasChildren = $family->contains(fn (Taxonomy $category): bool => $category->parent_id === $current->id);
        $chipParentId = $hasChildren || $parentId === null ? $current->id : $parentId;
        $children = $family->filter(fn (Taxonomy $category): bool => $category->parent_id === $chipParentId)->values();
        if ($children->isEmpty()) {
            return ['chips' => [], 'labels' => $labels];
        }

        $routes = SiteRoute::query()
            ->whereIn('taxonomy_id', [$chipParentId, ...$children->pluck('id')->all()])
            ->where('locale', $locale)
            ->pluck('path', 'taxonomy_id');

        $chips = [];
        foreach ($children as $child) {
            $path = $routes->get($child->id);
            if (is_string($path) && isset($labels[$child->id])) {
                $chips[] = ['name' => $labels[$child->id], 'url' => $this->paths->publicUrl($path, $locale), 'current' => $child->id === $current->id, 'all' => false];
            }
        }
        if ($chips === []) {
            return ['chips' => [], 'labels' => $labels];
        }

        $allPath = $routes->get($chipParentId);
        if (is_string($allPath)) {
            array_unshift($chips, ['name' => __('storefront.all'), 'url' => $this->paths->publicUrl($allPath, $locale), 'current' => $chipParentId === $current->id, 'all' => true]);
        }

        return ['chips' => $chips, 'labels' => $labels];
    }

    /**
     * Post cards. The label is the post's primary category (read with the rows as
     * `primary_taxonomy_id`); dates, when asked, are the site's local day (one settings read).
     *
     * @param  iterable<Entry>  $posts
     * @param  array<int, string>  $labels
     * @return list<array{title: string, url: string, image: string, srcset: string|null, icon: string, excerpt: string, date: string, datetime: string, label: string}>
     */
    public function postCards(iterable $posts, string $locale, array $labels, bool $withDates): array
    {
        $time = $withDates ? app(SiteTime::class) : null;
        $cards = [];
        foreach ($posts as $post) {
            $translation = $post->translationFor($locale);
            if (! $translation instanceof EntryTranslation) {
                continue;
            }
            $route = $post->routes->firstWhere('locale', $locale);
            $unprefixed = $route !== null ? $route->path : $this->paths->forEntry($post, $locale, trim((string) $translation->slug, '/'));
            $image = is_string($post->meta['image_url'] ?? null) ? $post->meta['image_url'] : '';
            $primary = $post->getAttribute('primary_taxonomy_id');
            $published = $time?->forDisplay($post->published_at);

            $cards[] = [
                'title' => $translation->title,
                'url' => $this->paths->publicUrl($unprefixed, $locale),
                ...$this->photo($image, null),
                'icon' => '',
                'excerpt' => $translation->excerpt ?? '',
                'date' => $published?->format('d/m/Y') ?? '',
                'datetime' => $published?->format('Y-m-d') ?? '',
                'label' => is_numeric($primary) ? ($labels[(int) $primary] ?? '') : '',
            ];
        }

        return $cards;
    }

    /**
     * Product cards: the first photo (with its master width for srcset) and the primary category's
     * name as the label.
     *
     * @param  iterable<Product>  $products
     * @return list<array{title: string, url: string, image: string, srcset: string|null, icon: string, excerpt: string, date: string, datetime: string, label: string}>
     */
    public function productCards(iterable $products, string $locale): array
    {
        $cards = [];
        foreach ($products as $product) {
            $translation = $product->translationFor($locale);
            if ($translation === null) {
                continue;
            }
            $category = $product->primaryCategory();
            $media = $product->images->first()?->media;
            $key = $media?->storage_key;

            $cards[] = [
                'title' => $translation->name,
                'url' => $this->paths->publicUrl($this->paths->forProduct($category, (string) $translation->slug, $locale), $locale),
                ...$this->photo(is_string($key) ? $key : '', $media?->width),
                'icon' => '',
                'excerpt' => $translation->summary ?? '',
                'date' => '',
                'datetime' => '',
                'label' => $category?->translationFor($locale)->name ?? '',
            ];
        }

        return $cards;
    }

    /**
     * A card photo from a stored value: a storage key (no scheme, has a slash) becomes a sized
     * media URL with a srcset; a full URL stays as it is (the view still checks it); anything else
     * (empty, a glyph name) is no photo.
     *
     * @return array{image: string, srcset: string|null}
     */
    private function photo(string $value, ?int $masterWidth): array
    {
        if ($value === '' || ! str_contains($value, '/')) {
            return ['image' => '', 'srcset' => null];
        }
        if (str_contains($value, '://')) {
            return ['image' => $value, 'srcset' => null];
        }

        return [
            'image' => $this->media->url($value, self::CARD_WIDTH),
            'srcset' => $this->media->srcset($value, self::CARD_SRCSET_WIDTHS, $masterWidth),
        ];
    }

    /** A model row's public ulid, or null. */
    public static function ulidOf(?Model $row): ?string
    {
        $ulid = $row?->getAttribute('ulid');

        return is_string($ulid) && $ulid !== '' ? $ulid : null;
    }
}
