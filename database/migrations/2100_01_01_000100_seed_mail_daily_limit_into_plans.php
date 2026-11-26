<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the new per-website daily limit of mails through the platform's shared outbox
 * (`mail.platform_daily`, FeatureRegistry) into every plan, at the registry default of 30, so the
 * plan screens show the value each plan carries instead of a blank. A website's mails of the
 * ordinary kinds are held back to the next day only past ten times its limit; security mails and
 * contact-form notices are never held, and mail through a website's own outbox is never limited.
 *
 * A website that needs more is raised by staff with a website override (SiteFeatures), as for the
 * other limits. Running it again changes nothing (ON CONFLICT DO NOTHING keeps a value staff set).
 *
 * down() leaves the rows: code from before this release never reads `mail.platform_daily`, and
 * removing them would lose values staff may have set since.
 */
return new class extends Migration
{
    /** The FeatureRegistry default for `mail.platform_daily`, as it stood when this ran. */
    private const int DEFAULT = 30;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'insert into plan_features (plan_id, feature_key, value)
            select p.id, ?, to_jsonb(?::int) from plans p
            on conflict (plan_id, feature_key) do nothing',
            ['mail.platform_daily', self::DEFAULT],
        );
    }

    public function down(): void
    {
        // The rows stay: see the docblock.
    }
};
