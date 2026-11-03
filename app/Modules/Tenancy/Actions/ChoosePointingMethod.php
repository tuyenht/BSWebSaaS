<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record how the owner points a name (design.md §28): by name (Alias) or, for the registrars that
 * cannot point `@` at a name, by the server's address (ARecord) — the names to warn before the
 * server moves. Both spellings of the name carry it, as AttachDomain stores it. It changes nothing
 * about where the name answers.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class ChoosePointingMethod
{
    public function __construct(private CurrentSite $current) {}

    /**
     * @return bool whether anything changed
     *
     * @throws DomainChangeRejectedException invalid · not_found
     */
    public function handle(string $input, PointingMethod $method): bool
    {
        $host = DomainName::parse($input);

        $rows = Domain::query()
            ->where('site_id', $this->current->id())->where('kind', 'custom')
            ->whereIn('hostname', [$host, DomainName::sibling($host)]);

        if (! (clone $rows)->where('hostname', $host)->exists()) {
            throw new DomainChangeRejectedException('not_found');
        }

        return $rows->where(fn (Builder $q): Builder => $q->whereNull('pointing_method')->orWhere('pointing_method', '!=', $method->value))
            ->update(['pointing_method' => $method->value]) > 0;
    }
}
