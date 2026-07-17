<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Workflow engine (design.md §15.4) — tier-2 editorial approval, DATA-DRIVEN so a site can
 * add/change stages without code. Content tier (2xxx). ALL tenant tables (site_id) → RLS +
 * FORCE + fail-closed, matching create_content_tables.
 *
 *   workflows            — one per (site, content type); template none|admin_approval|editorial.
 *                          is_active DEFAULTS FALSE: tier-1 (permission-driven publish) stays the
 *                          behaviour for every existing site until an operator opts in.
 *   workflow_states      — the stages; exactly one is_initial, and only an is_published state
 *                          may expose content publicly.
 *   workflow_transitions — the legal edges; required_permission IS the guard (design.md §15.4),
 *                          so authorization stays in RBAC rather than hard-coded in the engine.
 *   workflow_log         — append-only trail of applied transitions (who/when/note/assignment).
 *
 * SUBJECT = TYPED, not polymorphic (design.md v2.14 keeps entry_revisions typed and rejects a
 * polymorphic content_revisions; D3 reserves polymorphic for soft attachments). workflow_log
 * mirrors the proven `routes` shape: nullable typed FK + num_nonnulls(...) = 1, so adding a
 * second subject (e.g. product_id) later is a one-line CHECK swap, with real FK cascade
 * throughout — which a polymorphic subject_type/subject_id could never enforce.
 *
 * The definition tables carry NO subject: the engine is subject-agnostic and reusable; only the
 * log binds to what moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            // The content type this workflow governs — a content_sections.key (e.g. 'tin-tuc').
            // Soft reference: sections are per-site rows an operator can rename/remove, and a
            // dangling workflow must not block that (the engine treats an unknown type as "no
            // workflow" → tier-1 publish, the same fail-safe as is_active = false).
            $table->string('applies_to_type');
            $table->string('template')->default('none');
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();

            // One workflow per content type per site; key is the stable handle.
            $table->unique(['site_id', 'applies_to_type']);
            $table->unique(['site_id', 'key']);
        });

        Schema::create('workflow_states', function (Blueprint $table): void {
            $table->id();
            // Denormalized from the parent workflow so every tenant table can carry its own RLS
            // policy + site_id-leading index (CLAUDE.md §2.1/§3.1) instead of joining to check.
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->timestampsTz();

            // Also the target of workflow_transitions' composite FKs below.
            $table->unique(['workflow_id', 'key']);
            $table->index(['site_id', 'workflow_id']);
        });

        Schema::create('workflow_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('from_state');
            $table->string('to_state');
            // The permission a user must hold to apply this transition (e.g. entries.approve).
            $table->string('required_permission');
            // Forces the actor to explain — the "phản biện" note on a reject/request-changes edge.
            $table->boolean('requires_note')->default(false);
            $table->timestampsTz();

            $table->unique(['workflow_id', 'key']);
            $table->index(['site_id', 'workflow_id']);
        });

        Schema::create('workflow_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            // TYPED subject (see header). Nullable + single-target CHECK below, like `routes`.
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('transition_key');
            $table->string('from_state');
            $table->string('to_state');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->text('note')->nullable();
            // Append-only: a trail row is never edited, so it carries no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('entry_id')->references('id')->on('entries')->cascadeOnDelete();
            // Actor/assignee are soft references (D4): a departed user must not erase history.
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->index(['site_id', 'entry_id', 'created_at']);   // an entry's history
            $table->index(['site_id', 'assigned_to']);              // "waiting on me" inbox
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // D13: enum = varchar + CHECK (extensible, unlike native ENUM).
        DB::statement("ALTER TABLE workflows ADD CONSTRAINT workflows_template_check CHECK (template IN ('none','admin_approval','editorial'))");

        // A transition's endpoints must be states OF THE SAME workflow — enforced in the DB, so a
        // hand-edited row can never point an edge at a foreign workflow's state.
        DB::statement('ALTER TABLE workflow_transitions ADD CONSTRAINT workflow_transitions_from_state_foreign
            FOREIGN KEY (workflow_id, from_state) REFERENCES workflow_states (workflow_id, key) ON DELETE CASCADE');
        DB::statement('ALTER TABLE workflow_transitions ADD CONSTRAINT workflow_transitions_to_state_foreign
            FOREIGN KEY (workflow_id, to_state) REFERENCES workflow_states (workflow_id, key) ON DELETE CASCADE');

        // D3: a log row records EXACTLY one subject (mirrors routes_single_target).
        DB::statement('ALTER TABLE workflow_log ADD CONSTRAINT workflow_log_single_subject CHECK (num_nonnulls(entry_id) = 1)');

        foreach (['workflows', 'workflow_states', 'workflow_transitions', 'workflow_log'] as $table) {
            $this->enableRls($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_log');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('workflows');
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
