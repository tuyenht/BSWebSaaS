<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A website's domain managers are told when one of its own domains needs them (design.md §28):
 * `system.domain_problem` (failing, a week before it stops being served, stopped, back, an expired
 * wait) and the security notice `security.domain_taken_over` (another site proved it owns the
 * name). Both are listed in NotificationKinds.
 *
 * The kind CHECK (D13) is swapped for one listing the new kinds: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table. site_notifications keeps
 * 90 days of rows, so validation is brief; the lock still gives up after 5s rather than queue the
 * bell behind it. Re-runnable after a mid-run failure (DROP IF EXISTS).
 *
 * down() deletes the notices of these kinds (they would break the old CHECK) and restores the old list.
 */
return new class extends Migration
{
    private const array KINDS = [
        'workflow.assigned', 'workflow.returned', 'workflow.published', 'workflow.pending_review',
        'lead.new', 'security.new_device_login', 'security.two_factor_disabled', 'system.storage_near_limit',
        'security.support_sign_in',
    ];

    private const array ADDED = ['security.domain_taken_over', 'system.domain_problem'];

    public function up(): void
    {
        $this->listKinds([...self::KINDS, ...self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('site_notifications')->whereIn('kind', self::ADDED)->delete();
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
