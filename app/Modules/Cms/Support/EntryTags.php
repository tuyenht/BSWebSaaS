<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * a tag needs nothing more. A tag made by a draft's save is not public: its page answers 404 until a
 * live post carries it (StorefrontController::renderTag). Every statement names the current site;
 * RLS is the net underneath.
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
     * The name to keep for a typed tag, or null when it is not one (not UTF-8, empty, too long, or no
     * letter or digit to make an address from). Control characters (NUL and the rest of C0 and C1:
     * PostgreSQL refuses a NUL) and every invisible format mark (a direction override, a zero-width
     * space: Unicode category Cf) are removed first, runs of spaces squeezed to one, and the checks
     * judge what is left: a name made only of such marks is no name.
     */
    public static function clean(string $name, string $locale): ?string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = PlainText::label($name);
        // PlainText::label keeps the joiners that hold emoji together; a tag's name and address need none
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/\p{Cf}+/u', '', $name) ?? '') ?? '');
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
     * What a form changed between the post's tags it loaded ($from) and its tags now ($to), told by
     * address: the names it added (slug => name, in $to's order) and the addresses it took out.
     * Names that are no tag's (clean() refuses them) count for nothing.
     *
     * @param  array<array-key, mixed>  $from
     * @param  array<array-key, mixed>  $to
     * @return array{added: array<string, string>, removed: list<string>}
     */
    public static function changes(array $from, array $to, string $locale): array
    {
        $before = self::bySlug($from, $locale);
        $after = self::bySlug($to, $locale);
        $added = [];
        foreach ($after as $slug => $name) {
            if (! array_key_exists($slug, $before)) {
                $added[(string) $slug] = $name;
            }
        }

        return ['added' => $added, 'removed' => array_map(strval(...), array_keys(array_diff_key($before, $after)))];
    }

    /**
     * File the post under exactly the tags $names in $locale (the ones named in other languages only
     * stay): the names it has now that $names lacks leave it, the new ones join after the others.
     * For a caller that writes the whole list (a seeder, a fixture); the editor files its own
     * changes with apply(). Returns the post's tags in $locale as stored.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function assign(Entry $entry, string $locale, array $names): array
    {
        return $this->apply($entry, $locale, $this->names($entry, $locale), $names)['tags'];
    }

    /**
     * File what a form changed since it loaded the post's tags in $locale ($from → $to, changes())
     * onto the tags stored now, so a save never undoes another one: the tags it took out leave the
     * post, the ones it added join after the stored ones, and every other tag stays as stored (one
     * another save added meanwhile, the ones named in other languages only). A name no tag has in
     * $locale makes a new tag, with its page at /the/{slug}.
     *
     * A name is not filed, and comes back in `refused` with why (`why`), when its address is another page's
     * (`taken`, checked here whatever the form said, so a list sent past the card's own check is held
     * to it too) or the post has its MAX_TAGS tags in $locale already (`full`); the rest is filed.
     * Runs in the caller's transaction, after the entry row is locked; a change marks the post's
     * content as changed (its page shows them).
     *
     * @param  array<array-key, mixed>  $from  the post's tags in $locale as the form loaded them
     * @param  array<array-key, mixed>  $to  the form's tags now
     * @return array{tags: list<string>, refused: list<array{name: string, why: 'taken'|'full'}>}
     */
    public function apply(Entry $entry, string $locale, array $from, array $to): array
    {
        ['added' => $added, 'removed' => $removed] = self::changes($from, $to, $locale);
        if ($added === [] && $removed === []) {
            return ['tags' => $this->names($entry, $locale), 'refused' => []];
        }

        $siteId = $this->site->id();
        $rows = $this->tagRows($entry->id)
            ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 't.id')
                ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
            ->get(['et.taxonomy_id', 'tt.slug', 'tt.name']);
        $stored = [];
        $final = [];
        $named = [];
        foreach ($rows as $row) {
            $id = Cast::int($row->taxonomy_id);
            $stored[] = $id;
            $slug = $row->slug === null ? null : mb_strtolower(Cast::string($row->slug));
            // the form told a tag by the address its name gives, which is the tag's own slug
            $said = $row->name === null ? null : mb_strtolower(self::slugOf(Cast::string($row->name), $locale));
            if (($slug !== null && in_array($slug, $removed, true)) || ($said !== null && in_array($said, $removed, true))) {
                continue;
            }
            $final[] = $id;
            if ($slug !== null) {
                $named[$slug] = true;
            }
        }

        $refused = [];
        if ($added !== []) {
            // one writer of a language's tags at a time: a tag two saves make at once is made once
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['tags:'.$siteId.':'.$locale]);
            foreach ($added as $slug => $name) {
                // a key of digits only comes back from the array as an int
                $slug = mb_strtolower((string) $slug);
                if (isset($named[$slug])) {
                    continue;   // another save filed it meanwhile
                }
                if (count($named) >= self::MAX_TAGS) {
                    $refused[] = ['name' => $name, 'why' => 'full'];

                    continue;
                }
                $id = $this->findOrCreate($locale, $slug, $name);
                if ($id === null) {
                    $refused[] = ['name' => $name, 'why' => 'taken'];

                    continue;
                }
                $final[] = $id;
                $named[$slug] = true;
            }
        }
        $final = array_values(array_unique($final));

        if ($final !== $stored) {
            $gone = array_values(array_diff($stored, $final));
            if ($gone !== []) {
                DB::table('entry_taxonomies')->where('site_id', $siteId)->where('entry_id', $entry->id)
                    ->whereIn('taxonomy_id', $gone)->delete();
            }
            $now = now();
            $pivot = [];
            foreach ($final as $i => $id) {
                $pivot[] = ['site_id' => $siteId, 'entry_id' => $entry->id, 'taxonomy_id' => $id,
                    'position' => Entry::FACET_POSITION_BASE + $i, 'created_at' => $now, 'updated_at' => $now];
            }
            if ($pivot !== []) {
                DB::table('entry_taxonomies')->upsert($pivot, ['entry_id', 'taxonomy_id'], ['position', 'updated_at']);
            }
            Entry::markContentChanged([$entry->id]);
        }

        return ['tags' => $this->names($entry, $locale), 'refused' => $refused];
    }

    /**
     * The tag named $slug in $locale, made with $name when there is none; null when a new tag's
     * address is another page's. The new tag is made in its own savepoint: an address taken between
     * the check and the write (RouteSync refuses it, or the routes' unique key does) leaves nothing
     * of it behind, and the caller's transaction usable.
     */
    private function findOrCreate(string $locale, string $slug, string $name): ?int
    {
        $siteId = $this->site->id();
        $found = DB::table('taxonomy_translations as tt')
            ->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'tt.taxonomy_id')
                ->where('t.site_id', '=', $siteId)->where('t.vocabulary', '=', Taxonomy::TAG))
            ->where('tt.site_id', $siteId)->where('tt.locale', $locale)
            // compared as text, so the (site_id, locale, (slug::text)) index serves it (see saveTranslations)
            ->whereRaw('tt.slug::text = ?', [$slug])
            ->value('t.id');
        if ($found !== null) {
            return Cast::int($found);
        }
        if (! $this->addressFree($name, $locale)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($locale, $slug, $name): int {
                $taxonomy = Taxonomy::query()->create(['vocabulary' => Taxonomy::TAG, 'parent_id' => null, 'position' => 0]);
                $this->taxonomyPaths->refreshBranch($taxonomy->id);
                $taxonomy->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $slug]);
                $this->routes->syncTaxonomy($taxonomy, $locale, $slug);
                Audit::record('cms.tag.created', [
                    'auditable_type' => Taxonomy::class,
                    'auditable_id' => $taxonomy->id,
                    'meta' => ['locale' => $locale, 'slug' => $slug],
                ]);

                return $taxonomy->id;
            });
        } catch (RouteConflictException|UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * The names given, each once by address (the first spelling kept), the ones clean() refuses left out.
     *
     * @param  array<array-key, mixed>  $names
     * @return array<string, string>
     */
    private static function bySlug(array $names, string $locale): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = is_string($name) ? self::clean($name, $locale) : null;
            if ($name !== null) {
                $out[mb_strtolower(self::slugOf($name, $locale))] ??= $name;
            }
        }

        return $out;
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
