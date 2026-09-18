<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\ContentSection;

/**
 * Resolves which permalink SECTION a post belongs to — the (key, type) pair PermalinkConfig is
 * keyed on. A post carries no stored section (there is no content_section_id yet); membership is
 * derived from its PRIMARY category (pivot position 0) matched to a ContentSection's bound
 * `taxonomy_id`, walking the category's parent chain so a post filed under a sub-category still
 * resolves to its section. Returns null when the post has no category or none binds it — the caller
 * then falls back to the flat default.
 *
 * `key` = ContentSection.key (stable, English, locale-invariant — the same identifier pages use);
 * `type` = the section's content type mapped to the permalink vocabulary (page → pages, else posts).
 *
 * Memoized per-site by primary-category id for the request (negative-cached) so bulk RouteSync of a
 * category's entries costs one section lookup, not one per entry. Registered scoped
 * (CmsServiceProvider) so the memo is flushed between Octane operations and never leaks across
 * requests.
 *
 * This is the single seam for section membership: if a stored `content_section_id` FK later lands,
 * only this class changes — PathBuilder and the config stay put.
 */
final class SectionResolver
{
    /** @var array<string, array{key: string, type: string}|null> memo per "{siteId}:{primaryTaxonomyId}" */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * The (key, type) of the section a post belongs to, or null when none can be derived.
     *
     * @return array{key: string, type: string}|null
     */
    public function forEntry(Entry $entry): ?array
    {
        $primary = $entry->relationLoaded('taxonomies')
            ? $entry->taxonomies->first()
            : $entry->taxonomies()->first();

        if (! $primary instanceof Taxonomy) {
            return null;
        }

        return $this->forCategory($primary);
    }

    /**
     * The (key, type) of the section a primary category files content under — the same
     * derivation as forEntry, exposed for content that has no Entry row yet (an unsaved post
     * whose picked category already decides which section, and so which workflow, it will join).
     *
     * @return array{key: string, type: string}|null
     */
    public function forCategory(Taxonomy $primary): ?array
    {
        if (! $this->current->hasId()) {
            return $this->resolve($primary);
        }

        $key = $this->current->id().':'.$primary->id;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->resolve($primary);
        }

        return $this->memo[$key];
    }

    /**
     * Walk the category's ancestor chain (self first) to the nearest taxonomy a ContentSection
     * binds; a visited-set guards against a parent cycle.
     *
     * @return array{key: string, type: string}|null
     */
    private function resolve(Taxonomy $primary): ?array
    {
        $taxonomy = $primary;
        $seen = [];

        while ($taxonomy instanceof Taxonomy && ! isset($seen[$taxonomy->id])) {
            $seen[$taxonomy->id] = true;

            $section = ContentSection::query()
                ->whereNotNull('taxonomy_id')
                ->where('taxonomy_id', $taxonomy->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($section instanceof ContentSection) {
                return [
                    'key' => $section->key,
                    'type' => $section->entry_type === 'page' ? 'pages' : 'posts',
                ];
            }

            $taxonomy = $taxonomy->parent_id !== null
                ? Taxonomy::query()->whereKey($taxonomy->parent_id)->first()
                : null;
        }

        return null;
    }
}
