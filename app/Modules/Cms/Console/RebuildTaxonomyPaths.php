<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Recompute every category's structural path (the ltree `taxonomies.path`) from its parent chain,
 * site by site inside each site's tenant context. Categories created before the category write path
 * kept this mirror current have none; the write path keeps it current from then on. Only rows whose
 * path is missing or wrong are written, so running it again is harmless and reports 0.
 */
final class RebuildTaxonomyPaths extends Command
{
    #[\Override]
    protected $signature = 'cms:rebuild-taxonomy-paths';

    #[\Override]
    protected $description = 'Recompute every category\'s structural path from its parent chain (safe to re-run)';

    public function handle(Tenancy $tenancy, TaxonomyPaths $paths): int
    {
        $written = 0;
        $sites = $tenancy->eachSite(function () use ($paths, &$written): void {
            $written += $paths->rebuild();
        });

        $this->info("Checked {$sites} site(s); {$written} category path(s) written.");

        return self::SUCCESS;
    }
}
