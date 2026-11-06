<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;

/**
 * Serializes the writers of one site's shared settings rows: the site profile, its translations and
 * the settings rows (general.brand among them). Each such writer calls this as the first statement
 * of its transaction, so concurrent saves queue on one row that always exists (the site row, global
 * per D6, so no tenant scope is needed), in the same order, instead of locking the rows they touch in
 * different orders and deadlocking — or locking nothing when a row does not exist yet. Held until
 * the transaction ends.
 *
 * FOR NO KEY UPDATE, not FOR UPDATE: FOR UPDATE also blocks the FOR KEY SHARE every insert with a
 * site_id foreign key takes on this row, so orders, form submissions and audit rows of the site would
 * wait for the save, and a content-pack apply that inserts while holding settings rows would deadlock
 * with it (the same reason as the Planner's lock). Takers still queue behind one another.
 */
final class SiteWriteLock
{
    public static function take(): void
    {
        Site::query()->whereKey(app(CurrentSite::class)->id())->lock('for no key update')->value('id');
    }
}
