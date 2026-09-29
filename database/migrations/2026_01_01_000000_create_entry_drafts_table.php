<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pending changes to live content (design.md §8 `entry_drafts`). When a published entry must not
 * change for visitors until someone with the right to publish or approve says so, the edit is
 * kept here instead of in entries/entry_translations; the storefront never reads this table.
 * At most one open change per entry: the approval state, the trail and the review inbox are all
 * per entry, and shared fields (cover, meta, category) affect every language at once.
 *
 *  - No ulid: a change is never addressed from outside; the entry's ulid is its address (§2.4).
 *  - workflow_state: the change's stage in its entry's workflow; null = the first stage, or no
 *    workflow at all (an author without the publish right edits live content on a site whose
 *    approval workflow is off, and the change waits for someone who can publish).
 *  - content: {v, shared: {...}, locales: {<locale>: {...}}}, field names matching the revision
 *    snapshot so a change compares to a revision without mapping. A CHECK keeps the two
 *    containers objects; `v` versions the shape like entry_revisions.snapshot.
 *  - assigned_to, created_by, updated_by: soft references (D4, SET NULL).
 *  - entry_id CASCADE: purging an entry drops its pending change; a soft delete (Trash) keeps it.
 *    UNIQUE(entry_id) enforces one change per entry and serves the purge's child lookup.
 *
 * Rows live only while a change is open and are deleted once applied or discarded, so the table
 * stays small: no retention sweep, no partitioning. The queue index leads with site_id (ADR-18)
 * and also covers the site_id foreign key. site_id + RLS FORCE fail-closed.
 * down() drops the table: changes not yet applied are lost, live content is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('entry_id')->unique()->constrained('entries')->cascadeOnDelete();
            $table->string('workflow_state')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['site_id', 'workflow_state'], 'entry_drafts_queue');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // COALESCE: a missing `locales` key makes jsonb_typeof NULL, and a CHECK that evaluates to
        // NULL passes — the shape must be rejected, not waved through.
        DB::statement(<<<'SQL'
            ALTER TABLE entry_drafts ADD CONSTRAINT entry_drafts_content_shape
                CHECK (jsonb_typeof(content) = 'object'
                       AND COALESCE(jsonb_typeof(content -> 'locales'), 'missing') = 'object')
            SQL);

        DB::statement('ALTER TABLE entry_drafts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE entry_drafts FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY entry_drafts_site_isolation ON entry_drafts
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_drafts');
    }
};
