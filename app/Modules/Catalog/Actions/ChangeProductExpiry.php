<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Actions\Concerns\AuthorizesCatalogChange;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Validation\ExpiryTime;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets, moves or clears a product's end time («Gỡ khỏi website lúc», expires_at), for the product
 * editor and any other screen: the signed-in member must be allowed to change the product
 * (CatalogPolicy), the time must still be to come (ExpiryTime; the database's CHECK keeps it after
 * published_at), and the change writes its audit row through the model in the same transaction
 * (DateChangeAudit). Judged on the locked row.
 */
final readonly class ChangeProductExpiry
{
    use AuthorizesCatalogChange;

    public function __construct(private SiteTime $time) {}

    /**
     * @throws AuthorizationException the member may not change this product
     * @throws ValidationException expiresAt: the time is past
     * @throws ModelNotFoundException the product left meanwhile (sent to the Trash or purged)
     */
    public function handle(Product $product, ?DateTimeInterface $expiresAt): void
    {
        $this->authorizeCatalog('update', $product);

        DB::transaction(function () use ($product, $expiresAt): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Product::class, [$product->id]);
            $product->setRawAttributes($locked->getAttributes(), true);
            $this->authorizeCatalog('update', $product);   // again on the row as it is now (its categories may have moved)

            $at = $expiresAt !== null ? CarbonImmutable::instance($expiresAt)->utc() : null; // stored in UTC: a write prints the time without its zone
            $publishAt = $product->published_at !== null && $product->published_at > now() ? $product->published_at : null;
            $error = ExpiryTime::error($at, $publishAt, $this->time->zone());
            if ($error !== null) {
                throw ValidationException::withMessages(['expiresAt' => $error]);
            }

            $product->forceFill(['expires_at' => $at])->save();
        });
    }
}
