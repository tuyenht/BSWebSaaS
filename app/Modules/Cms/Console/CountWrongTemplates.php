<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only: counts, site by site, the categories, posts and pages whose stored layout is of the
 * wrong role or kind for where it sits, or that the theme no longer has. Those render on the
 * theme's default layout now; run it before a deploy to know which pages change. Nothing is written.
 */
final class CountWrongTemplates extends Command
{
    #[\Override]
    protected $signature = 'cms:count-wrong-templates';

    #[\Override]
    protected $description = 'Count the categories, posts and pages whose stored layout has the wrong role (changes nothing)';

    public function handle(Tenancy $tenancy): int
    {
        $total = ['categories' => 0, 'posts' => 0, 'pages' => 0];
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                $found = $tenancy->enter($siteId, fn (): array => $this->countSite());
            } catch (Throwable $e) {
                $failed++;
                $this->error("Site {$siteId}: {$e->getMessage()}");

                continue;
            }
            foreach ($found as $kind => $count) {
                $total[$kind] += $count;
            }
            if (array_sum($found) > 0) {
                $this->line("Site {$siteId}: {$found['categories']} categor(ies), {$found['posts']} post(s), {$found['pages']} page(s).");
            }
        }

        $this->info("Wrong layout in total: {$total['categories']} categor(ies), {$total['posts']} post(s), {$total['pages']} page(s). Nothing was changed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{categories: int, posts: int, pages: int}
     */
    private function countSite(): array
    {
        $categories = 0;
        foreach (Taxonomy::query()->where(fn ($q) => $q->whereNotNull('list_template')->orWhereNotNull('detail_template'))->get(['id', 'vocabulary', 'list_template', 'detail_template']) as $category) {
            $list = $category->list_template;
            $detail = $category->detail_template;
            $listWrong = $list !== null && $list !== TemplateCatalog::PARENT && ! TaxonomyRules::listTemplate($list, $category->vocabulary);
            $detailWrong = $detail !== null && $detail !== TemplateCatalog::PARENT
                && ! ($category->vocabulary === 'product_cat' ? TaxonomyRules::template($detail) : TaxonomyRules::postDetailTemplate($detail));
            if ($listWrong || $detailWrong) {
                $categories++;
            }
        }

        $entries = ['post' => 0, 'page' => 0];
        $rows = Entry::query()->whereNotNull('template_override')->whereIn('type', ['post', 'page'])
            ->selectRaw('type, template_override, count(*) as total')->groupBy('type', 'template_override')->get();
        foreach ($rows as $row) {
            $role = TemplateCatalog::roleOfEntry($row->type);
            $layout = Cast::string($row->getAttribute('template_override'));
            if ($role !== null && ! TemplateCatalog::fits($layout, $role)) {
                $entries[$row->type] += Cast::int($row->getAttribute('total'));
            }
        }

        return ['categories' => $categories, 'posts' => $entries['post'], 'pages' => $entries['page']];
    }
}
