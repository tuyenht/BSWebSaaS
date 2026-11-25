<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public read surface over the GLOBAL site registry (sites + domains — D6, no RLS): by host for
 * callers that run outside a tenant context and so cannot lean on CurrentSite — e.g. an error
 * page, rendered after Tenancy::enter has already cleared the context — and for the current site
 * inside one. Feature modules use this instead of reaching into Tenancy\Models
 * (app/Modules/README.md). Read-only, and it hands back names, never ids.
 */
final readonly class SiteDirectory
{
    public function __construct(private SiteResolver $sites) {}

    /**
     * The display name of the site that owns $host, or null when the host is not a site domain.
     */
    public function nameForHost(string $host): ?string
    {
        $resolved = $this->sites->resolve($host);

        if ($resolved === null) {
            return null;
        }

        $name = Site::query()->whereKey($resolved->siteId)->value('name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * The stored name of the current site, exactly as saved ('' when unset) — the staging folder of
     * a presigned upload still nests it, so it is never trimmed or replaced by a display name. Takes
     * the context rather than an id: it reads the current site's own row.
     */
    public function nameOfCurrent(CurrentSite $current): string
    {
        return Cast::string(Site::query()->whereKey($current->id())->value('name'));
    }

    /**
     * The current site's permanent code (sites.code), the first folder of every media key it
     * writes ('' only when the row is gone). Read from the database each time: the model created in
     * this request does not carry it, because the database issues it on insert.
     */
    public function codeOfCurrent(CurrentSite $current): string
    {
        return Cast::string(Site::query()->withTrashed()->whereKey($current->id())->value('code'));
    }

    /**
     * Whether $segment is the code of a website other than the current one: a live, trashed or
     * removed website (a removed website's code stays reserved and is never issued again).
     */
    public function isCodeOfAnotherSite(CurrentSite $current, string $segment): bool
    {
        return Site::query()->withTrashed()->where('code', $segment)->whereKeyNot($current->id())->exists()
            || DB::table('reserved_site_codes')->where('code', $segment)->where('reason', 'deleted_site')->exists();
    }

    /**
     * Every host name of a site (its primary, its platform subdomain and the spellings that only
     * redirect), for a caller outside any tenant context — e.g. a queued purge of the edge cache.
     *
     * @return list<string>
     */
    public function hostsOf(int $siteId): array
    {
        return Cast::strings(Domain::query()->where('site_id', $siteId)->orderBy('id')->pluck('hostname')->all());
    }

    /**
     * Every host name of the site that $host resolves to (SiteResolver: a live name, also one that
     * only redirects), or [] when it resolves to none.
     *
     * @return list<string>
     */
    public function hostsOfSiteWith(string $host): array
    {
        $resolved = $this->sites->resolve($host);

        return $resolved === null ? [] : $this->hostsOf($resolved->siteId);
    }

    /**
     * Every host name of the platform — or only of the websites whose customer is on $planId — handed
     * to $each $size at a time, walked by id (never the whole table at once).
     *
     * @param  Closure(list<string>): void  $each
     */
    public function eachHostChunk(int $size, Closure $each, ?int $planId = null): void
    {
        $query = Domain::query()->select(['id', 'hostname']);
        if ($planId !== null) {
            $query->whereIn('site_id', Site::query()->select('sites.id')
                ->join('tenants', 'tenants.id', '=', 'sites.tenant_id')
                ->where('tenants.plan_id', $planId));
        }

        $query->chunkById($size, static function (Collection $rows) use ($each): void {
            $each(Cast::strings($rows->pluck('hostname')->all()));
        });
    }
}
