<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member invitations (design.md §15.3). "Add member" used to look an email up across the WHOLE
 * platform and answer "no account with this email" — any site admin could probe which addresses
 * hold an account anywhere, and an existing account was pulled into a site without being asked.
 * An invitation replaces that: the admin always gets the same reply, the mail goes to the address,
 * and only its holder decides — signing in to accept, or creating the account right there.
 *
 *  - invitations: one row per (site, email) while pending (partial UNIQUE); inviting the same
 *    address again refreshes that row instead of stacking a second one. The mailed token is
 *    never stored — only its SHA-256 (token_hash). State is read off the timestamps: pending =
 *    neither accepted nor revoked and not expired. CHECKs forbid "accepted AND revoked" and an
 *    accepted_user_id without accepted_at. `sent_at` is the last send, which the daily cap on
 *    invitation mail per site counts (index site_id + sent_at).
 *  - invitation_roles: the roles the invitation grants. A tenant pivot keeps its own site_id +
 *    RLS (like product_taxonomies), and the composite FK (site_id, invitation_id) pins every row
 *    to its invitation's site.
 *
 * Both tables: site_id + RLS FORCE fail-closed (D1/§2). Timestamps are timestamptz (D11), email
 * is citext (D12). down() drops both — pending invitations are lost, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->string('message', 500)->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['site_id', 'id']);                         // composite-FK target (invitation_roles)
            $table->index(['site_id', 'created_at', 'id']);            // keyset list, newest first
            $table->index(['site_id', 'sent_at']);                     // daily send cap
        });

        Schema::create('invitation_roles', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('invitation_id');
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['invitation_id', 'role_id']);
            $table->foreign(['site_id', 'invitation_id'])->references(['site_id', 'id'])->on('invitations')->cascadeOnDelete();
            $table->index(['site_id', 'role_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invitations ALTER COLUMN email TYPE citext');
        DB::statement(
            'CREATE UNIQUE INDEX invitations_pending_unique ON invitations (site_id, email)
             WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_single_outcome_check CHECK (accepted_at IS NULL OR revoked_at IS NULL)');
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_accepted_user_check CHECK (accepted_user_id IS NULL OR accepted_at IS NOT NULL)');

        foreach (['invitations', 'invitation_roles'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_roles');
        Schema::dropIfExists('invitations');
    }

    private function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_site_isolation ON {$table}
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }
};
