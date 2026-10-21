<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Provisioning\Exceptions\TenantSelectionException;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Find an EXISTING customer from what an operator typed: its ulid, or its exact name. Never
 * creates one and never picks among several — `tenants.name` is not unique, and a near miss is
 * reported (with the ulids to retry with) rather than resolved, so a typo cannot attach a website
 * to the wrong customer or hand it a fresh plan quota. Soft-deleted customers are not found.
 */
final readonly class FindTenant
{
    /** How many candidates an ambiguity message lists. */
    private const int CANDIDATES = 10;

    public function handle(string $reference): Tenant
    {
        $reference = trim($reference);

        if (Str::isUlid($reference)) {
            return Tenant::query()->where('ulid', strtoupper($reference))->first()
                ?? throw TenantSelectionException::notFound($reference);
        }

        $exact = $this->candidates(Tenant::query()->where('name', $reference));
        if ($exact->count() === 1) {
            return $exact->firstOrFail();
        }
        if ($exact->isNotEmpty()) {
            throw TenantSelectionException::ambiguous($reference, $this->describe($exact));
        }

        // Only on a miss, and only to explain it: same name but for letter case.
        $similar = $this->candidates(Tenant::query()->whereRaw('lower(name) = lower(?)', [$reference]));
        if ($similar->isNotEmpty()) {
            throw TenantSelectionException::didYouMean($reference, $this->describe($similar));
        }

        throw TenantSelectionException::notFound($reference);
    }

    /**
     * Customers already carrying $name in any letter case, as "ULID \"name\"" ('' when none): a new
     * customer under the same name is usually the same customer typed twice, not a second one.
     */
    public function namesakes(string $name): string
    {
        $same = $this->candidates(Tenant::query()->whereRaw('lower(name) = lower(?)', [trim($name)]));

        return $same->isEmpty() ? '' : $this->describe($same);
    }

    /**
     * @param  Builder<Tenant>  $query
     * @return Collection<int, Tenant>
     */
    private function candidates(Builder $query): Collection
    {
        return $query->orderBy('id')->limit(self::CANDIDATES)->get(['id', 'ulid', 'name']);
    }

    /** @param  Collection<int, Tenant>  $tenants */
    private function describe(Collection $tenants): string
    {
        return $tenants
            ->map(fn (Tenant $t): string => sprintf('%s "%s"', $t->ulid, $t->name))
            ->implode(', ');
    }
}
