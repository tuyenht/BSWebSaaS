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
 * language. Every
 * read runs under the site's row-level security and the models' site scope, so another site's
 * posts never appear.
 *
 * @phpstan-type Link array{title: string, url: string}
 * @phpstan-type Cover array{src: string, srcset: string|null, alt: string, caption: string, width: int, height: int}
 */
final readonly class PostDetail
{
    public const int RELATED = 3;

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
     * @return array{date: string, datetime: string, updated: string, updatedDatetime: string, excerpt: string, cover: Cover|null}
     */
    public function band(Entry $post, EntryTranslation $translation, string $locale): array
    {
        $published = $this->time->forDisplay($post->published_at);
        $edited = $this->time->forDisplay($translation->updated_at);
        $format = $this->time->dateFormat();
        // "Updated" shows only when the text changed on a later day than it went out.
        $later = $published !== null && $edited !== null && $edited->format('Y-m-d') > $published->format('Y-m-d');

        return [
            'date' => $published !== null ? SiteTime::formatDay($published, $format, $locale) : '',
            'datetime' => $published?->format('Y-m-d') ?? '',
            'updated' => $later ? SiteTime::formatDay($edited, $format, $locale) : '',
            'updatedDatetime' => $later ? $edited->format('Y-m-d') : '',
            'excerpt' => trim((string) $translation->excerpt),
            'cover' => $this->cover($post, $locale),
        ];
    }

    /**
     * The neighbours and related posts of a post in its main category.
     *
     * @return array{previous: Link|null, next: Link|null, related: list<array<string, mixed>>}
     */
    public function siblings(Entry $post, Taxonomy $category, string $locale, bool $withDates): array
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

        return [
            'previous' => $previous !== null ? $this->link($previous, $locale) : null,
            'next' => $next !== null ? $this->link($next, $locale) : null,
            'related' => $this->lists->postCards($related, $locale, [], $withDates),
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
        return Entry::query()
            ->select(['entries.id', 'entries.site_id', 'entries.type', 'entries.published_at'])
            // Cards read only the image link from meta; do not carry the rest of the document.
            ->selectRaw("jsonb_build_object('image_url', entries.meta->'image_url') as meta")
            // Which part of the union a row came from; $slot is one of this class's three literals.
            ->selectRaw("'{$slot}'::text as slot")
            ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')
                ->where('entry_taxonomies.position', 0)
                ->where('entry_taxonomies.taxonomy_id', $category->id))
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
