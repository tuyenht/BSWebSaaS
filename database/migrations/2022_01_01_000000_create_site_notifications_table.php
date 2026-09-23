<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (design.md §15, decision 24/09 Q5): the bell and the Notifications page.
 * Laravel's stock `notifications` table (uuid key, no site_id, morph notifiable) breaks D2 and
 * would mix every website's notifications in one unscoped table — and the name is taken by the
 * `$user->notifications()` relation of the Notifiable trait — hence `site_notifications`.
 *
 *  - kind: varchar + CHECK (D13). Kinds with no sender yet (new-device sign-in, storage almost
 *    full) are listed so their senders can arrive without a migration.
 *  - data: the facts the sentence is built from (who, which entry, the note) — rendered in the
 *    READER's language when shown, so a later change of language or wording applies to history.
 *  - Indexes: the feed (newest first, keyset by id), the unread badge (partial), and the 90-day
 *    retention sweep (site_id, created_at).
 * site_id + RLS FORCE fail-closed; each person reads only their own rows (user_id, app layer).
 * down() drops the table — notification history is lost, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->jsonb('data')->default('{}');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['site_id', 'created_at']);   // retention sweep
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE site_notifications ADD CONSTRAINT site_notifications_kind_check CHECK (kind IN (
                'workflow.assigned', 'workflow.returned', 'workflow.published', 'workflow.pending_review',
                'lead.new', 'security.new_device_login', 'security.two_factor_disabled', 'system.storage_near_limit'))
            SQL);
        DB::statement('CREATE INDEX site_notifications_feed ON site_notifications (site_id, user_id, id DESC)');
        DB::statement('CREATE INDEX site_notifications_unread ON site_notifications (site_id, user_id) WHERE read_at IS NULL');

        DB::statement('ALTER TABLE site_notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE site_notifications FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY site_notifications_site_isolation ON site_notifications
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_notifications');
    }
};
