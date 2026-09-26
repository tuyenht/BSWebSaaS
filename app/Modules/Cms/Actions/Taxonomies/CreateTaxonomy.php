<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\DB;

/**
 * Add a category to a vocabulary (post_cat, product_cat…), at the top level or under a parent of the
 * same vocabulary, at the end of its siblings. It needs a name in the site's default language; each
 * language it is written in gets a slug (made from the name when none is given) and its category
 * page at `/{slug}`. The tree may not grow past TaxonomyRules::MAX_DEPTH levels, and the structural
 * `path` is written with the row.
 *
 * Who may manage categories (`taxonomies.manage`) is the caller's check. Runs inside the tenant
 * context.
 */
final readonly class CreateTaxonomy
{
    use EditsTaxonomyTree;

    public function __construct(
        private SiteLocale $siteLocales,
        private RouteSync $routes,
        private TaxonomyPaths $paths,
        private CurrentSite $site,
    ) {}

    /**
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(string $vocabulary, ?Taxonomy $parent, TaxonomyData $data): Taxonomy
    {
        if (preg_match(TaxonomyRules::VOCABULARY_PATTERN, $vocabulary) !== 1) {
            throw new TaxonomyChangeRejectedException('vocabulary_invalid');
        }
        $translations = $this->translations($data, [], $this->siteLocales->enabled());
        if (! isset($translations[$this->siteLocales->default()])) {
            throw new TaxonomyChangeRejectedException('name_invalid');
        }
        $listTemplate = $this->template($data->listTemplate);
        $detailTemplate = $this->template($data->detailTemplate);

        return DB::transaction(function () use ($vocabulary, $parent, $translations, $listTemplate, $detailTemplate): Taxonomy {
            $this->lockTree($this->site->id());

            $parentId = null;
            if ($parent !== null) {
                $parent = $this->reload($parent, 'parent_invalid');
                if ($parent->vocabulary !== $vocabulary) {
                    throw new TaxonomyChangeRejectedException('vocabulary_mismatch');
                }
                if (TaxonomyTree::levelOf($parent->id) + 1 > TaxonomyRules::MAX_DEPTH) {
                    throw new TaxonomyChangeRejectedException('depth_exceeded');
                }
                $parentId = $parent->id;
            }

            $last = Taxonomy::query()
                ->where('vocabulary', $vocabulary)
                ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
                ->max('position');

            $taxonomy = Taxonomy::query()->create([
                'vocabulary' => $vocabulary,
                'parent_id' => $parentId,
                'position' => $last === null ? 0 : Cast::int($last) + 1,
                'list_template' => $listTemplate,
                'detail_template' => $detailTemplate,
            ]);

            $this->paths->refreshBranch($taxonomy->id);
            $this->saveTranslations($taxonomy, $translations, $this->routes);

            Audit::record('cms.taxonomy.created', [
                'auditable_type' => Taxonomy::class,
                'auditable_id' => $taxonomy->id,
                'meta' => [
                    'vocabulary' => $vocabulary,
                    'parent' => $parentId,
                    'slugs' => array_map(static fn (array $translation): string => $translation['slug'], $translations),
                ],
            ]);

            return $taxonomy->refresh();
        });
    }
}
