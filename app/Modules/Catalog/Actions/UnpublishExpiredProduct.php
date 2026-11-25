<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Support\DateChangeAudit;
use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Support\CategoryPublicCounts;
use Illuminate\Support\Facades\DB;

/**
 * «Gỡ khỏi website lúc» for a product: takes a live product off the website when its end time comes,
 * driven by products:unpublish-due. It becomes `archived` («Gỡ khỏi website», kept), which every
 * product list, the sitemap, search and the category counts already leave out; the write purges the
 * edge cache. Its address stays, so it comes back at the same place if it is published again.
 *
 * The row is locked and re-read first: only a product STILL live, out of the Trash and due is taken
 * down (an editor may have moved the time, taken it down or trashed it since the sweep read it). The
 * status line is written in the same transaction, actor «scheduler»: no take-down without it.
 * It asks the policy first like every catalog action; the sweep runs with nobody signed in.
 */
final readonly class UnpublishExpiredProduct
{
    use AuthorizesCatalogChange;

    /** The audit action of a product's status change made by the system. */
    public const string STATUS_CHANGED = 'catalog.product.status_changed';

    public function __construct(private CategoryPublicCounts $publicCounts, private DateChangeAudit $dates) {}

    /** @return bool whether this call took the product down */
    public function handle(Product $product): bool
    {
        $this->authorizeCatalog('update', $product);

        return $this->dates->during(DateChangeAudit::VIA_SCHEDULER, fn (): bool => DB::transaction(
            fn (): bool => $this->publicCounts->track('product_taxonomies', [$product->id], fn (): bool => $this->unpublishLocked($product)),
        ));
    }

    private function unpublishLocked(Product $product): bool
    {
        if (Product::withTrashed()->whereKey($product->id)->lockForUpdate()->first() === null) {
            return false;
        }
        $product->refresh();

        $expiresAt = $product->expires_at;
        if ($product->status !== 'published' || $product->trashed() || $expiresAt === null || $expiresAt > now()) {
            return false;
        }

        $product->forceFill(['status' => 'archived'])->save();

        Audit::recordOrFail(self::STATUS_CHANGED, [
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
            'actor_label' => DateChangeAudit::VIA_SCHEDULER,
            'meta' => [
                'from' => 'published', 'to' => 'archived', 'via' => DateChangeAudit::VIA_SCHEDULER,
                'reason' => 'expired', 'expires_at' => $expiresAt->format(DATE_ATOM), 'product_ulid' => $product->ulid,
            ],
        ]);

        return true;
    }
}
