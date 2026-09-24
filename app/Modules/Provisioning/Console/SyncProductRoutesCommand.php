<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gives every published product the public address its slug and the site's permalink preset call
 * for. Until the product editor saved routes, a product created (or renamed) in the admin had no
 * `routes` row at its path: its page answered 404 and the catalog listing linked to nowhere.
 *
 * For each published translation it compares the stored route with the composed path: a missing
 * route is created, one at a stale path is moved — RouteSync leaves a 301 from the old path — and a
 * path another page or category owns is reported for a person to settle. Without --apply nothing is
 * written. Sites are walked by id in keyset chunks, each inside its own tenant context.
 */
final class SyncProductRoutesCommand extends Command
{
    #[\Override]
    protected $signature = 'catalog:sync-routes {--apply : Create missing routes and move stale ones (default: report only)}';

    #[\Override]
    protected $description = 'Report — and with --apply fix — published products whose public address is missing or stale.';

    public function handle(Tenancy $tenancy, PathBuilder $paths, RouteSync $routes): int
    {
        $apply = (bool) $this->option('apply');
        $lines = [];

        $tenancy->eachSite(function (int $siteId) use ($paths, $routes, $apply, &$lines): void {
            Product::query()
                ->where('status', 'published')
                ->with(['translations', 'taxonomies'])
                ->chunkById(200, function (Collection $products) use ($siteId, $paths, $routes, $apply, &$lines): void {
                    $stored = SiteRoute::query()
                        ->whereIn('product_id', $products->modelKeys())
                        ->get(['product_id', 'locale', 'path'])
                        ->keyBy(fn (SiteRoute $route): string => $route->product_id.'|'.$route->locale);

                    foreach ($products as $product) {
                        /** @var Product $product */
                        $primary = $product->primaryCategory();

                        foreach ($product->translations as $translation) {
                            /** @var ProductTranslation $translation */
                            $path = $paths->forProduct($primary, $translation->slug, $translation->locale);
                            $current = $stored->get($product->id.'|'.$translation->locale)?->path;

                            if ($current === $path) {
                                continue;
                            }

                            $action = $current === null ? 'create' : 'move';
                            if ($apply) {
                                try {
                                    $routes->syncProduct($product->id, $primary, $translation->locale, $translation->slug);
                                    $action = $current === null ? 'created' : 'moved (301 from the old path)';
                                } catch (RouteConflictException) {
                                    $action = 'conflict — the path belongs to another page or category';
                                }
                            }

                            $lines[] = [$siteId, $translation->name, $translation->locale, $current ?? '—', $path, $action];
                        }
                    }
                });
        });

        if ($lines === []) {
            $this->info('Every published product already has its address — nothing to do.');

            return self::SUCCESS;
        }

        $names = Site::query()->whereIn('id', array_unique(array_column($lines, 0)))->pluck('name', 'id');
        foreach ($lines as $i => $line) {
            $lines[$i][0] = $names[$line[0]] ?? (string) $line[0];
        }

        $this->table(['Site', 'Product', 'Locale', 'Stored path', 'Address', 'Action'], $lines);
        $this->line($apply
            ? 'Done. Conflicts were left as they are — rename the product or the other page, then run this again.'
            : 'Report only (nothing written). Run with --apply to create and move these routes.');

        return self::SUCCESS;
    }
}
