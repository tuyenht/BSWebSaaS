<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two notices about a member's access, both listed in NotificationKinds:
 *  - `member.access_requested`: a member pressed "Nhờ cấp quyền" on the admin's no-permission page;
 *    the website's member managers above them are told (the site owner when there is none);
 *  - `member.role_changed`: someone changed the member's roles on Thành viên (one member, several
 *    at once, or "Hoàn tác").
 *
 * The kind CHECK (D13) is swapped for one listing the new kinds: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table. Both run in the migration's
 * one transaction, so the table stays locked through the validating scan; site_notifications keeps
 * 90 days of rows, so that scan is short, and taking the lock gives up after 5s rather than queue
 * the bell behind it. Re-runnable after a mid-run failure (DROP IF EXISTS). No row is rewritten.
 *
 * down() refuses while notices of these kinds exist (it says how many): they would break the old
 * CHECK, and they are deleted on purpose before a rollback, never by the rollback itself.
 */
return new class extends Migration
{
    private const array KINDS = [
        'workflow.assigned', 'workflow.returned', 'workflow.published', 'workflow.pending_review',
        'lead.new', 'security.new_device_login', 'security.two_factor_disabled', 'system.storage_near_limit',
        'security.support_sign_in', 'security.domain_taken_over', 'system.domain_problem',
    ];

    private const array ADDED = ['member.access_requested', 'member.role_changed'];

    public function up(): void
    {
        $this->listKinds([...self::KINDS, ...self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $left = DB::table('site_notifications')->whereIn('kind', self::ADDED)->count();
        if ($left > 0) {
            throw new RuntimeException("{$left} notices of the kinds ".implode(', ', self::ADDED)
                .' still exist: delete them on purpose first, then roll back.');
        }
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
