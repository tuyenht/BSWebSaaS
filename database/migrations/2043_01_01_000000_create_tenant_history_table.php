<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_history — what happened to a customer, as the platform console shows it on the
 * customer's page. A GLOBAL table (D6: no site_id, no RLS, no ulid — rows never leave the
 * console): only platform staff write or read it, through the platform gate; no website code
 * path touches it. Rows are only ever added.
 *
 *   - kind (D13: varchar + CHECK) lists 'plan_changed' only; a new kind widens the CHECK in its
 *     own migration (pattern: 2033_01_01_000000_add_domain_notification_kinds.php).
 *   - from_plan_id / to_plan_id: the plan before and after; NULL is "no plan". ON DELETE RESTRICT:
 *     plans are never deleted, and a plan a customer's history names must stay to be shown.
 *   - reason: why, as staff typed it (the callers refuse more than 500 characters).
 *   - actor_user_id: who; SET NULL if the account is ever removed, the row stays. NULL also for
 *     a change made from the console (platform:plan).
 *   - created_at only (D11 timestamptz): a history row is never edited, so it has no updated_at.
 *
 * The customer page reads one customer's rows newest first, keyset-paged by (created_at, id):
 * the index (tenant_id, created_at DESC, id DESC) serves that read in order, and its tenant_id
 * lead serves the CASCADE lookup when a customer is removed for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE tenant_history ADD CONSTRAINT tenant_history_kind_check CHECK (kind IN ('plan_changed'))");
        DB::statement('CREATE INDEX tenant_history_tenant_id_created_at_id_index ON tenant_history (tenant_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_history');
    }
};
