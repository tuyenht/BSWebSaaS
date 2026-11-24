<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * A post's tags («Thẻ», editor board 07): taxonomies of vocabulary Taxonomy::TAG filed on the entry
 * as facet terms, at pivot positions from Entry::FACET_POSITION_BASE up, so no category reader takes
 * one for a category and category writes leave them alone (HasCategoryTaxonomies).
 *
 * A tag's name is per language: a tag made from the editor in language L has a name, a slug and a
 * route (`/the/slug`, PathBuilder::forTag) in L only. The post's tags in L are the ones named in L;
 * a save in L changes only those and keeps the tags named in other languages only. Two names with
 * one slug in a language are one tag: the route's unique (site, language, path) keeps it so, and
 * the advisory lock below makes a second writer of the same new tag find the first one's.
 *
 * Who may write: the caller (the entry editor's save) has authorized the update of the entry; making
 * a tag needs nothing more. Every statement names the current site; RLS is the net underneath.
 */
final readonly class EntryTags
{
    /** The tags one post may carry. */
    public const int MAX_TAGS = 20;

    /** A tag's longest name. */
    public const int NAME_MAX = 60;

    public function __construct(
        private CurrentSite $site,
        private RouteSync $routes,
        private PathBuilder $paths,
        private TaxonomyPaths $taxonomyPaths,
    ) {}

    /**
     * The name to keep for a typed tag, or null when it is not one (empty, too long, or no letter or
     * digit to make an address from).
     */
    public static function clean(string $name, string $locale): ?string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || TaxonomyRules::name($name) === null) {
            return null;
        }

        return TaxonomyRules::slug(TaxonomyRules::slugFrom($name, $locale)) !== null ? $name : null;
    }

    /** The address part a tag name gives in $locale (clean() names only). */
    public static function slugOf(string $name, string $locale): string
    {
        return TaxonomyRules::slugFrom($name, $locale);
    }

    /**
     * The post's tags named in $locale, in their order.
     *
     * @return list<string>
     */
    public function names(Entry $entry, string $locale): array
    {
        return array_values(array_map(Cast::string(...), $this->inLocale($entry->id, $locale)->pluck('tt.name')->all()));
    }

    /**
     * The post's tags named in $locale, each with its page in that language, for the chips under the
     * post. A tag with no route in the language is left out.
     *
     * @return list<array{name: string, url: string}>
     */
    public function links(Entry $entry, string $locale): array
    {
        $rows = $this->inLocale($entry->id, $locale)
            ->join('routes as r', fn (JoinClause $join) => $join->on('r.taxonomy_id', '=', 't.id')
                ->where('r.site_id', '=', $this->site->id())->where('r.locale', '=', $locale))
            ->get(['tt.name', 'r.path']);

        $links = [];
        foreach ($rows as $row) {
            $links[] = ['name' => Cast::string($row->name), 'url' => $this->paths->publicUrl(Cast::string($row->path), $locale)];
        }

        return $links;
    }

    /**
     * Whether a NEW tag named $name in $locale could have its page: its address is free, or already a
     * tag's (which is then reused).
     */
    public function addressFree(string $name, string $locale): bool
    {
        $owner = DB::table('routes')
            ->where('site_id', $this->site->id())->where('locale', $locale)
            ->where('path', $this->paths->forTag(self::slugOf($name, $locale)))
            ->first(['taxonomy_id']);
        if ($owner === null) {
            return true;
        }
        $taxonomy = $owner->taxonomy_id ?? null;

        return is_numeric($taxonomy) && DB::table('taxonomies')->where('site_id', $this->site->id())
            ->where('id', (int) $taxonomy)->where('vocabulary', Taxonomy::TAG)->exists();
    }

    /**
     * File the post under the tags $names in $locale, in that order (the ones named in other
     * languages only stay, before them). A name no tag has in $locale makes a new tag. Runs in the
     * caller's transaction, after the entry row is locked. Returns the post's tags in $locale as
     * stored; a change marks the post's content as changed (its page shows them).
     *
     * @param  list<string>  $names  clean() names
     * @return list<string>
     */
    public function assign(Entry $entry, string $locale, array $names): array
    {
        $siteId = $this->site->id();
        $wanted = [];
        foreach ($names as $name) {
            $name = self::clean($name, $locale);
            if ($name !== null) {
                $wanted[self::slugOf($name, $locale)] ??= $name;
            }
        }
        $wanted = array_slice($wanted, 0, self::MAX_TAGS, true);

        $stored = array_values(array_map(Cast::int(...), $this->tagRows($entry->id)->pluck('et.taxonomy_id')->all()));
        $named = $stored === [] ? [] : array_map(Cast::int(...), DB::table('taxonomy_translations')
            ->where('site_id', $siteId)->where('locale', $locale)->whereIn('taxonomy_id', $stored)->pluck('taxonomy_id')->all());
        $kept = array_values(array_diff($stored, $named));

        $ids = [];
        if ($wanted !== []) {
            // one writer of a language's tags at a time: a tag two saves make at once is made once
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['tags:'.$siteId.':'.$locale]);
            foreach ($wanted as $slug => $name) {
                $ids[] = $this->findOrCreate($locale, (string) $slug, $name);
            }
        }
        $final = array_values(array_unique([...$kept, ...$ids]));

        if ($final !== $stored) {
            $gone = array_values(array_diff($stored, $final));
            if ($gone !== []) {
                DB::table('entry_taxonomies')->where('site_id', $siteId)->where('entry_id', $entry->id)
                    ->whereIn('taxonomy_id', $gone)->delete();
            }
            $now = now();
            $rows = [];
            foreach ($final as $i => $id) {
                $rows[] = ['site_id' => $siteId, 'entry_id' => $entry->id, 'taxonomy_id' => $id,
                    'position' => Entry::FACET_POSITION_BASE + $i, 'created_at' => $now, 'updated_at' => $now];
            }
            if ($rows !== []) {
                DB::table('entry_taxonomies')->upsert($rows, ['entry_id', 'taxonomy_id'], ['position', 'updated_at']);
            }
            Entry::markContentChanged([$entry->id]);
        }

        return $this->names($entry, $locale);
    }

    /** The tag named $slug in $locale, made with $name when there is none. */
    private function findOrCreate(string $locale, string $slug, string $name): int
    {
        $siteId = $this->site->id();
        $found = DB::table('taxonomy_translations as tt')
            ->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'tt.taxonomy_id')
                ->where('t.site_id', '=', $siteId)->where('t.vocabulary', '=', Taxonomy::TAG))
            ->where('tt.site_id', $siteId)->where('tt.locale', $locale)
            // compared as text, so the (site_id, locale, (slug::text)) index serves it (see saveTranslations)
            ->whereRaw('tt.slug::text = ?', [mb_strtolower($slug)])
            ->value('t.id');
        if ($found !== null) {
            return Cast::int($found);
        }

        $taxonomy = Taxonomy::query()->create(['vocabulary' => Taxonomy::TAG, 'parent_id' => null, 'position' => 0]);
        $this->taxonomyPaths->refreshBranch($taxonomy->id);
        $taxonomy->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug]);
        // a path another page holds stops the save (RouteConflictException): addressFree() said so when the tag was typed
        $this->routes->syncTaxonomy($taxonomy, $locale, $slug);
        Audit::record('cms.tag.created', [
            'auditable_type' => Taxonomy::class,
            'auditable_id' => $taxonomy->id,
            'meta' => ['locale' => $locale, 'slug' => $slug],
        ]);

        return $taxonomy->id;
    }

    /** The post's tag rows (pivot), in their order. */
    private function tagRows(int $entryId): Builder
    {
        $siteId = $this->site->id();

        return DB::table('entry_taxonomies as et')
            ->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'et.taxonomy_id')
                ->where('t.site_id', '=', $siteId)->where('t.vocabulary', '=', Taxonomy::TAG))
            ->where('et.site_id', $siteId)->where('et.entry_id', $entryId)
            ->where('et.position', '>=', Entry::FACET_POSITION_BASE)
            ->orderBy('et.position')->orderBy('et.taxonomy_id');
    }

    /** The post's tags that have a name in $locale. */
    private function inLocale(int $entryId, string $locale): Builder
    {
        return $this->tagRows($entryId)
            ->join('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 't.id')
                ->where('tt.site_id', '=', $this->site->id())->where('tt.locale', '=', $locale));
    }
}
