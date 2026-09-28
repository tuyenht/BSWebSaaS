<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content revision history (design.md §8 `entry_revisions`). An entry's translations are
 * overwritten in place, so without this table a published page that is edited and saved keeps
 * no earlier version to look at or go back to. One row = one locale's content at one moment:
 * a translated entry is published and edited per language, and restoring one language must never
 * roll another back.
 *
 *  - ulid: the only id that leaves the server (D2/§2.4); the bigint stays internal.
 *  - kind: varchar + CHECK (D13) — baseline (first snapshot of content live before history
 *    existed) · publish (went public, by any road) · edit (saved while public) · restore.
 *  - snapshot: the content itself, versioned by its own `v` key so old rows stay readable after
 *    the shape grows. jsonb is TOASTed; lists never select it.
 *  - created_by: soft reference (SET NULL); null when the scheduler published.
 *  - entry_id CASCADE: purging an entry drops its history; a soft delete (Trash) keeps it.
 *  - No updated_at: a revision is never edited.
 *
 * One index serves the per-(entry, locale) list keyset, the "newest revision" lookup the
 * duplicate check makes, and the retention sweep's ranking — it leads with site_id (ADR-18).
 * site_id + RLS FORCE fail-closed. down() drops the table — revision history is lost, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('kind', 16);
            $table->jsonb('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE entry_revisions ADD CONSTRAINT entry_revisions_kind_check CHECK (kind IN ('baseline', 'publish', 'edit', 'restore'))");
        DB::statement('CREATE INDEX entry_revisions_history ON entry_revisions (site_id, entry_id, locale, created_at DESC, id DESC)');

        DB::statement('ALTER TABLE entry_revisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE entry_revisions FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY entry_revisions_site_isolation ON entry_revisions
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_revisions');
    }
};
