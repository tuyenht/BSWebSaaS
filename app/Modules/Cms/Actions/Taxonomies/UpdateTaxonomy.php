<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\CategoryChange;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\CategoryLimitGuard;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Support\Facades\DB;

/**
 * Change a category's name and slug per language and its two templates. A language left out keeps
 * what it has; a new slug moves the category page with a 301 from the old address, and — where the
 * site's permalink presets put categories in addresses — every post and product filed under the
 * category or below it follows, each with its own 301 (CategoryRelinker), in the same transaction.
 * The templates are set to exactly what the data says (null = the theme's default layout).
 *
 * Who may manage categories (`taxonomies.manage`) is the caller's check; a member limited to some
 * categories stays inside them (CategoryLimitGuard, outside_category_limits). Runs inside the tenant
 * context.
 */
final readonly class UpdateTaxonomy
{
    use EditsTaxonomyTree;

    public function __construct(
        private SiteLocale $siteLocales,
        private RouteSync $routes,
        private CategoryRelinker $relinker,
        private CurrentSite $site,
        private CategoryLimitGuard $limits,
    ) {}

    /**
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $taxonomy, TaxonomyData $data): Taxonomy
    {
        $listTemplate = $this->template($data->listTemplate);
        $detailTemplate = $this->template($data->detailTemplate);

        return DB::transaction(function () use ($taxonomy, $data, $listTemplate, $detailTemplate): Taxonomy {
            $this->lockTree($this->site->id());
            $taxonomy = $this->reload($taxonomy, 'taxonomy_invalid');
            $this->limits->change($taxonomy);

            /** @var array<string, TaxonomyTranslation> $existing */
            $existing = $taxonomy->translations()->get()->keyBy('locale')->all();
            $translations = $this->translations($data, $existing, $this->siteLocales->enabled());

            $changed = $this->saveTranslations($taxonomy, $translations, $this->routes);
            $taxonomy->forceFill(['list_template' => $listTemplate, 'detail_template' => $detailTemplate])->save();

            try {
                $routesMoved = $changed === []
                    ? 0
                    : $this->relinker->relink(array_keys(TaxonomyTree::branchOf($taxonomy->id)), CategoryChange::Renamed);
            } catch (RouteConflictException) {
                throw new TaxonomyChangeRejectedException('path_taken');
            }

            Audit::record('cms.taxonomy.updated', [
                'auditable_type' => Taxonomy::class,
                'auditable_id' => $taxonomy->id,
                'meta' => ['slugs_changed' => $changed, 'routes_moved' => $routesMoved],
            ]);

            return $taxonomy;
        });
    }
}
