<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;

/**
 * What a post page shows around its body: the band's dates, the cover photo with its caption, the
 * previous and next posts of its main category and up to three related posts from that category.
 *
 * "Previous" is the next older post, "next" the next newer one, by publish time with the id as
 * tie-break, the same order as the category list. The three are one read, a union of keyset
 * reads on the category's posts (one row, one row, three rows), selecting only the columns a link
 * or card needs, then one batched load of their titles and one of their addresses in the page's
 * language. When the side column asks for it, a fourth part of the same read brings the newest posts of
 * the whole root branch of the category (leaving out the post and the related ones). Every
 * read runs under the site's row-level security and the models' site scope, so another site's
 * posts never appear.
 *
 * @phpstan-type Link array{title: string, url: string}
 * @phpstan-type Cover array{src: string, srcset: string|null, alt: string, caption: string, width: int, height: int}
 */
final readonly class PostDetail
{
    public const int RELATED = 3;

    /** The posts the side column's "recent posts" block lists. */
    public const int RECENT = 4;

    /** The widths the cover photo offers through srcset (the band shows it up to the page width). */
    private const array COVER_SRCSET_WIDTHS = [640, 960, 1280, 1920];

    private const int COVER_WIDTH = 1280;

    public function __construct(
        private PathBuilder $paths,
        private MediaUrlResolver $media,
        private ListPage $lists,
        private SiteTime $time,
    ) {}

    /**
     * `author` is this language's own byline (a team or department), '' when it has none: the page
     * then names the site's business, never another language's text.
     *
     * @return array{date: string, datetime: string, updated: string, updatedDatetime: string, excerpt: string, author: string, cover: Cover|null}
     */
    public function band(Entry $post, EntryTranslation $translation, string $locale): array
    {
        return $this->dates($post, $translation, $locale) + [
            'excerpt' => trim((string) $translation->excerpt),
            'author' => trim((string) $translation->author_name),
            'cover' => $this->cover($post, $locale),
        ];
    }

    /**
     * The day an entry went out and, when its content changed on a later day, the day it changed,
     * in the site's zone and day format. No query beyond the site's (memoized) time settings.
     *
     * @return array{date: string, datetime: string, updated: string, updatedDatetime: string}
     */
    public function dates(Entry $post, EntryTranslation $translation, string $locale): array
    {
        $published = $this->time->forDisplay($post->published_at);
        // The same content date as the Article's dateModified (contentDate()).
        $edited = $this->time->forDisplay($translation->contentDate($post->published_at));
        $format = $this->time->dateFormat();
        // "Updated" shows only when the content changed on a later day than it went out.
        $later = $published !== null && $edited !== null && $edited->format('Y-m-d') > $published->format('Y-m-d');

        return [
            'date' => $published !== null ? SiteTime::formatDay($published, $format, $locale) : '',
            'datetime' => $published?->format('Y-m-d') ?? '',
            'updated' => $later ? SiteTime::formatDay($edited, $format, $locale) : '',
            'updatedDatetime' => $later ? $edited->format('Y-m-d') : '',
        ];
    }

    /**
     * The neighbours and related posts of a post in its main category.
     *
     * @param  bool  $withRecent  also read the newest posts of the category's root branch (one more part of the same read)
     * @param  bool  $fillRelated  when the category has fewer than RELATED other posts, fill the row with the newest posts of its root branch (same-category posts stay first)
     * @return array{previous: Link|null, next: Link|null, related: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function siblings(Entry $post, Taxonomy $category, string $locale, bool $withDates, bool $withRecent = false, bool $fillRelated = false): array
    {
        // One read for all three: the related posts, then (when the post has a publish time) the
        // one just older and the one just newer, each part ordered and limited on its own.
        $query = $this->inCategory($post, $category, $locale, 'related')
            ->orderByRaw('entries.published_at DESC NULLS LAST, entries.id DESC')
            ->limit(self::RELATED);
        // Publishing always stamps published_at (PublishEntry), so a published post has one; a post
        // without it (a draft preview) simply has no previous/next and shows related posts only.
        $publishedAt = $post->getRawOriginal('published_at');
        if ($publishedAt !== null) {
            $query->unionAll($this->inCategory($post, $category, $locale, 'previous')
                ->whereRaw('(entries.published_at, entries.id) < (?::timestamptz, ?)', [$publishedAt, $post->id])
                ->orderByDesc('entries.published_at')->orderByDesc('entries.id')
                ->limit(1));
            $query->unionAll($this->inCategory($post, $category, $locale, 'next')
                ->whereRaw('(entries.published_at, entries.id) > (?::timestamptz, ?)', [$publishedAt, $post->id])
                ->orderBy('entries.published_at')->orderBy('entries.id')
                ->limit(1));
        }
        if ($withRecent) {
            // The newest of the whole root branch: fetch the related count extra, then leave those out.
            // The seam for posts past their end date goes here when that column exists.
            $query->unionAll($this->inRootBranch($post, $category, $locale)
                ->orderByRaw('entries.published_at DESC NULLS LAST, entries.id DESC')
                ->limit(self::RECENT + self::RELATED));
        }
        if ($fillRelated) {
            // The row's top-up: the newest of the root branch, used only for the places the category's own posts leave empty.
            $query->unionAll($this->inRootBranch($post, $category, $locale, 'fill')
                ->orderByRaw('entries.published_at DESC NULLS LAST, entries.id DESC')
                ->limit(self::RELATED + 1));
        }
        $rows = $query->get();
        $rows->load([
            'translations' => fn (Relation $query) => $query->where('locale', $locale),
            'routes' => fn (Relation $query) => $query->where('locale', $locale),
        ]);

        $previous = $rows->first(fn (Entry $row): bool => $row->getAttribute('slot') === 'previous');
        $next = $rows->first(fn (Entry $row): bool => $row->getAttribute('slot') === 'next');
        // A union keeps no order across its parts: put the related posts back newest first.
        $related = $rows->filter(fn (Entry $row): bool => $row->getAttribute('slot') === 'related')
            ->sortBy([
                fn (Entry $a, Entry $b): int => ($b->published_at?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->published_at?->getTimestamp() ?? PHP_INT_MIN),
                fn (Entry $a, Entry $b): int => $b->id <=> $a->id,
            ])
            ->values();

        if ($fillRelated && $related->count() < self::RELATED) {
            $own = $related->pluck('id')->all();
            $related = $related->concat(
                $rows->filter(fn (Entry $row): bool => $row->getAttribute('slot') === 'fill' && ! in_array($row->id, $own, true))
                    ->sortBy([
                        fn (Entry $a, Entry $b): int => ($b->published_at?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->published_at?->getTimestamp() ?? PHP_INT_MIN),
                        fn (Entry $a, Entry $b): int => $b->id <=> $a->id,
                    ])
                    ->take(self::RELATED - $related->count())
            )->values();
        }

        $relatedIds = $related->pluck('id')->all();
        $recent = $rows->filter(fn (Entry $row): bool => $row->getAttribute('slot') === 'recent' && ! in_array($row->id, $relatedIds, true))
            ->sortBy([
                fn (Entry $a, Entry $b): int => ($b->published_at?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->published_at?->getTimestamp() ?? PHP_INT_MIN),
                fn (Entry $a, Entry $b): int => $b->id <=> $a->id,
            ])
            ->take(self::RECENT)
            ->values();

        return [
            'previous' => $previous !== null ? $this->link($previous, $locale) : null,
            'next' => $next !== null ? $this->link($next, $locale) : null,
            'related' => $this->lists->postCards($related, $locale, [], $withDates),
            'recent' => $this->lists->postCards($recent, $locale, [], $withDates),
        ];
    }

    /**
     * The other published posts whose main category is this one and that are published in this
     * language — only the columns a card or a link reads.
     *
     * @param  'related'|'previous'|'next'  $slot
     * @return Builder<Entry>
     */
    private function inCategory(Entry $post, Taxonomy $category, string $locale, string $slot): Builder
    {
        return $this->published($post, $locale, $slot)
            ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                ->where('entry_taxonomies.position', 0)
                ->where('entry_taxonomies.taxonomy_id', $category->id));
    }

    /**
     * The other published posts whose main category is this category's root or any category below
     * it (filtered on the category tree), published in this language.
     *
     * @param  'recent'|'fill'  $slot
     * @return Builder<Entry>
     */
    private function inRootBranch(Entry $post, Taxonomy $category, string $locale, string $slot = 'recent'): Builder
    {
        // The ltree path holds the ids from the root down; a category whose path was never written is its own root.
        $first = explode('.', (string) $category->path)[0];
        $rootId = ctype_digit($first) ? (int) $first : $category->id;

        return $this->published($post, $locale, $slot)
            ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                ->where('entry_taxonomies.position', 0))
            ->whereIn('entry_taxonomies.taxonomy_id', Taxonomy::query()
                ->select('taxonomies.id')
                ->where(fn (Builder $ids) => $ids
                    ->whereKey($rootId)
                    ->orWhereRaw('taxonomies.path <@ (select root.path from taxonomies root where root.id = ?)', [$rootId])));
    }

    /**
     * The published posts other than $post with a published translation in $locale — only the
     * columns a card or a link reads, tagged with the part of the union they belong to.
     *
     * @param  'related'|'previous'|'next'|'recent'|'fill'  $slot
     * @return Builder<Entry>
     */
    private function published(Entry $post, string $locale, string $slot): Builder
    {
        return Entry::query()
            ->select(['entries.id', 'entries.site_id', 'entries.type', 'entries.published_at'])
            // Cards read only the image link from meta; do not carry the rest of the document.
            ->selectRaw("jsonb_build_object('image_url', entries.meta->'image_url') as meta")
            // Which part of the union a row came from; $slot is one of this class's four literals.
            ->selectRaw("'{$slot}'::text as slot")
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->where('entries.id', '!=', $post->id)
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'));
    }

    /** @return Link|null */
    private function link(Entry $post, string $locale): ?array
    {
        $translation = $post->translationFor($locale);
        if ($translation === null) {
            return null;
        }
        $route = $post->routes->first();
        $path = $route !== null ? $route->path : $this->paths->forEntry($post, $locale, trim((string) $translation->slug, '/'));

        return ['title' => $translation->title, 'url' => $this->paths->publicUrl($path, $locale)];
    }

    /**
     * The post's cover photo (its featured image): a library photo gets a srcset, its alt text and
     * its caption (the photo's title in the library) from one read; a stored full link is shown as
     * it is; a glyph name or nothing means no cover.
     *
     * @return Cover|null
     */
    private function cover(Entry $post, string $locale): ?array
    {
        $value = $post->meta['image_url'] ?? null;
        if (! is_string($value) || ! str_contains($value, '/')) {
            return null;
        }
        if (str_contains($value, '://')) {
            return ['src' => $value, 'srcset' => null, 'alt' => '', 'caption' => '', 'width' => 1600, 'height' => 900];
        }

        $media = Media::query()->where('storage_key', $value)->first(['id', 'storage_key', 'width', 'height', 'alt', 'title']);
        $width = $media?->width !== null && $media->width > 0 ? $media->width : 1600;
        $height = $media?->height !== null && $media->height > 0 ? $media->height : 900;

        return [
            'src' => $this->media->url($value, self::COVER_WIDTH),
            'srcset' => $this->media->srcset($value, self::COVER_SRCSET_WIDTHS, $media?->width),
            'alt' => $media !== null ? trim((string) ($media->alt[$locale] ?? '')) : '',
            'caption' => $media !== null ? trim((string) ($media->title[$locale] ?? '')) : '',
            'width' => $width,
            'height' => $height,
        ];
    }
}
