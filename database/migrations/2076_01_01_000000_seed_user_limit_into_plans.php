<?php

use App\Modules\Rbac\Support\SiteHeadcount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the new per-website people limit (`users.max`, FeatureRegistry) so that no website is held
 * back on the day the check ships: inviting and accepting are refused once a website's people fill
 * it, and with nothing written every website would fall to the registry default of 10.
 *
 * The count is SiteHeadcount::perSiteSql(), the same definition the runtime check uses: members (a
 * role in the website's team) plus invitations still waiting (not accepted, not withdrawn, not
 * expired, to an address that is not a member). Websites in the trash are left out.
 *
 *  - every plan gets `users.max` = the larger of 10 and the largest count of a website on it (a
 *    plan nobody is on gets 10);
 *  - a website of a customer with no plan (they get the default, 10) whose count is above 10 gets a
 *    website override (site_features, as OverrideSiteFeature writes it) of exactly its count, with a
 *    `feature_overridden` row in its customer's history (no actor: the system), saying why. That
 *    override keeps winning over a plan the customer is later moved to, until staff remove it.
 *
 * One pass over the role and invitation tables, run as pgsql_admin (it reads every website's rows,
 * which RLS would hide from app_user). Running it again changes nothing: a value already there is
 * kept (ON CONFLICT DO NOTHING), and a history row is written only with an override it added.
 *
 * down() leaves the rows: code from before this release never reads `users.max`, so they change
 * nothing there, and removing them would lose values staff may have set since. No data is lost
 * either way.
 */
return new class extends Migration
{
    /** The FeatureRegistry default for `users.max`: also the level of a customer with no plan. */
    private const int DEFAULT = 10;

    private const string REASON = 'Giữ số người đang có khi bật giới hạn số người dùng mỗi website';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        [$counts, $countBindings] = SiteHeadcount::perSiteSql(null, now());
        $key = SiteHeadcount::FEATURE;

        DB::statement(
            "insert into plan_features (plan_id, feature_key, value)
            select p.id, ?, to_jsonb(greatest(?, coalesce(max(h.members + h.pending), 0)))
            from plans p
            left join tenants t on t.plan_id = p.id
            left join sites s on s.tenant_id = t.id and s.deleted_at is null
            left join ({$counts}) h on h.site_id = s.id
            group by p.id
            on conflict (plan_id, feature_key) do nothing",
            [$key, self::DEFAULT, ...$countBindings],
        );

        DB::statement(
            "with counted as (
                select h.site_id, h.members + h.pending as n
                from ({$counts}) h
                join sites s on s.id = h.site_id and s.deleted_at is null
                join tenants t on t.id = s.tenant_id and t.plan_id is null
                where h.members + h.pending > ?
            ), written as (
                insert into site_features (site_id, feature_key, value, created_at, updated_at)
                select site_id, ?, to_jsonb(n), now(), now() from counted
                on conflict (site_id, feature_key) do nothing
                returning site_id, value
            )
            insert into tenant_history (tenant_id, kind, reason, meta, created_at)
            select s.tenant_id, 'feature_overridden', ?,
                jsonb_build_object('site', s.ulid, 'site_name', s.name, 'feature', ?::text, 'value', w.value), now()
            from written w
            join sites s on s.id = w.site_id",
            [...$countBindings, self::DEFAULT, $key, self::REASON, $key],
        );
    }

    public function down(): void
    {
        // The rows stay: see the docblock.
    }
};
