<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * `sites.max` judges how many websites a customer has, so ProvisionSite must take the customer's
 * row lock BEFORE counting them and reading the plan's limit. Count first, lock second, and two
 * provisions racing for the last slot both see room and both create a website. As in
 * EntryPublishLockOrderTest, the query order is asserted rather than a real two-connection race,
 * which would be flaky.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function slProvision(string $host): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Lock Co', siteName: $host, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
}

it('locks the customer before counting its websites and reading its limit', function (): void {
    slProvision('lock1.test');

    /** @var list<string> $queries */
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    try {
        slProvision('lock2.test');
    } catch (SiteLimitReachedException) {
        // Expected at the default limit of one: the order up to the refusal is what matters.
    }

    $at = function (Closure $matches) use ($queries): int {
        foreach ($queries as $i => $sql) {
            if ($matches($sql)) {
                return $i;
            }
        }

        return -1;
    };

    $lock = $at(fn (string $sql): bool => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for update'));
    $count = $at(fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, 'from "sites"'));
    $limit = $at(fn (string $sql): bool => str_contains($sql, 'from "plan_features"')
        || (str_contains($sql, '"plan_id"') && str_contains($sql, 'from "tenants"') && ! str_contains($sql, 'for update')));

    // The hostname is looked up again under the lock, so a run that finished meanwhile is a re-run.
    $recheck = collect($queries)->keys()->first(fn (int $i): bool => $i > $lock
        && str_contains($queries[$i], 'from "domains"') && str_contains($queries[$i], '"hostname"'));

    expect($lock)->toBeGreaterThanOrEqual(0)
        ->and($recheck)->toBeInt()
        ->and($count)->toBeGreaterThan($recheck)
        ->and($limit)->toBeGreaterThan($lock);
});
