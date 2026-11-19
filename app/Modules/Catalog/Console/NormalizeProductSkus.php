<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\SkuCode;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the SKUs already stored the way the product editor now writes them (SkuCode: upper-case,
 * spaces turned into «-»), once, so the editor's duplicate check and the unique index see one spelling.
 *
 * Site by site inside each site's tenant context, in short transactions of CHUNK products walked by
 * id (the Trash too). A product is changed only when its normalised code differs and
 * - no other product of the site (the Trash too) already holds that exact code, and
 * - the code is not longer than SkuCode::MAX.
 * Those two kinds are left as they are and listed (site, product slug, old value) for a person to
 * decide; a product that clashes only with another row of this same run loses to the lower id.
 * The row is saved through the model (its search words follow) without touching its edit time.
 * Safe to re-run: a code already in its final spelling is not written, so a second run changes 0
 * (the listed ones are listed again). --dry-run counts and lists without writing.
 */
final class NormalizeProductSkus extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'catalog:normalize-skus {--dry-run : Count and list per site, write nothing}';

    #[\Override]
    protected $description = 'Upper-case product SKUs and turn spaces into «-»; clashes and over-long codes are listed, not changed (safe to re-run).';

    public function handle(Tenancy $tenancy): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $clashes = 0;
        $tooLong = 0;
        $sites = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $sites++;
            $done = 0;
            /** @var array<string, true> $planned new codes this run already gives to a lower id (a dry run writes none, so it remembers them) */
            $planned = [];
            try {
                $after = 0;
                do {
                    $result = $tenancy->enter($siteId, fn (): array => $this->step($after, $dry, $planned));
                    $after = $result['last'];
                    foreach ($result['log'] as $entry) {
                        // logged once the chunk has committed, so a rolled-back chunk leaves no line
                        Log::info('catalog:normalize-skus changed a SKU', ['site_id' => $siteId, ...$entry]);
                    }
                    $done += $result['changed'];
                    $changed += $result['changed'];
                    foreach ($result['clash'] as $line) {
                        $clashes++;
                        $this->line("site {$siteId}: clash, not changed: product {$line}");
                    }
                    foreach ($result['long'] as $line) {
                        $tooLong++;
                        $this->line("site {$siteId}: longer than ".SkuCode::MAX.', not changed: product '.$line);
                    }
                } while ($result['read'] === self::CHUNK);
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->line("site {$siteId}: failed after ".($dry ? 'counting' : 'changing')." {$done} SKU(s): ".$e->getMessage());
            }
        }

        $this->info(($dry ? 'Dry run: would change ' : 'Changed ')."{$changed} SKU(s) on {$sites} site(s); {$clashes} clash(es) and {$tooLong} over-long code(s) left as they are"
            .($failed > 0 ? ", {$failed} site(s) failed." : '.'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One chunk of the site (inside its context): products after $after in id order with a SKU.
     *
     * @param  array<string, true>  $planned
     * @return array{read: int, last: int, changed: int, clash: list<string>, long: list<string>, log: list<array{product_id: int, from: string, to: string|null}>}
     */
    private function step(int $after, bool $dry, array &$planned): array
    {
        $products = Product::query()->withTrashed()
            ->whereNotNull('sku')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->get();

        $changed = 0;
        $clash = [];
        $long = [];
        $log = [];
        foreach ($products as $listed) {
            // the row is read again under its lock: a save running meanwhile is never overwritten
            $product = Product::query()->withTrashed()->whereKey($listed->id)->when(! $dry, static fn ($query) => $query->lockForUpdate())->first();
            $old = $product?->sku;
            if (! $product instanceof Product || $old === null) {
                continue;
            }
            $new = SkuCode::normalize($old);
            if ($new === '') {
                // only spaces: the editor stores no code at all, never '' (it would enter the unique index)
                $new = null;
            }
            if (mb_strlen((string) $new) > SkuCode::MAX) {
                $long[] = $this->describe($product, $old);

                continue;
            }
            if ($new === $old) {
                continue;
            }
            $taken = $new !== null && (isset($planned[$new])
                || Product::query()->withTrashed()->where('sku', $new)->whereKeyNot($product->id)->exists());
            if ($taken) {
                $clash[] = $this->describe($product, $old);

                continue;
            }
            $changed++;
            if ($dry) {
                // a real run finds its own earlier writes in the table; only a dry run must remember them
                if ($new !== null) {
                    $planned[$new] = true;
                }

                continue;
            }
            // a savepoint: a save racing for the same code loses only this row, which is listed
            try {
                DB::transaction(function () use ($product, $new): void {
                    $product->timestamps = false;
                    $product->sku = $new;
                    $product->save();
                });
            } catch (UniqueConstraintViolationException) {
                $changed--;
                $clash[] = $this->describe($product, $old);

                continue;
            }
            $log[] = ['product_id' => Cast::int($product->id), 'from' => $old, 'to' => $new];
        }

        return [
            'read' => $products->count(),
            'last' => $products->isEmpty() ? $after : Cast::int($products->last()->id),
            'changed' => $changed,
            'clash' => $clash,
            'long' => $long,
            'log' => $log,
        ];
    }

    /** «slug (old value)»: the slug of the product's first translation, or its ulid when it has none. */
    private function describe(Product $product, string $old): string
    {
        $slug = $product->translations()->orderBy('id')->value('slug');

        return (is_string($slug) && $slug !== '' ? $slug : (string) $product->ulid)." (\"{$old}\")";
    }
}
