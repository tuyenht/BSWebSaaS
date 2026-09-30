<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A website's owners are told each time platform staff sign in as one of its members
 * (design.md v2.33): the security notice `security.support_sign_in`, listed in NotificationKinds.
 *
 * The kind CHECK (D13) is swapped for one listing the new kind: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table. site_notifications keeps
 * 90 days of rows, so validation is brief; the lock still gives up after 5s rather than queue the
 * bell behind it. Re-runnable after a mid-run failure (DROP IF EXISTS).
 *
 * down() deletes the notices of this kind (they would break the old CHECK) and restores the old list.
 */
return new class extends Migration
{
    private const array KINDS = [
        'workflow.assigned', 'workflow.returned', 'workflow.published', 'workflow.pending_review',
        'lead.new', 'security.new_device_login', 'security.two_factor_disabled', 'system.storage_near_limit',
    ];

    private const string ADDED = 'security.support_sign_in';

    public function up(): void
    {
        $this->listKinds([...self::KINDS, self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('site_notifications')->where('kind', self::ADDED)->delete();
        $this->listKinds(self::KINDS);
    }

    /** @param list<string> $kinds */
    private function listKinds(array $kinds): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn (string $kind): string => "'{$kind}'", $kinds));

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE site_notifications DROP CONSTRAINT IF EXISTS site_notifications_kind_check');
        DB::statement("ALTER TABLE site_notifications ADD CONSTRAINT site_notifications_kind_check CHECK (kind IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE site_notifications VALIDATE CONSTRAINT site_notifications_kind_check');
    }
};
