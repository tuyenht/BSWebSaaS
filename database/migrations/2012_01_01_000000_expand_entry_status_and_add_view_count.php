<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages-admin fidelity (design_handoff_pages_admin §3a). The design exposes four page
 * lifecycle states — published, draft, scheduled, private. draft/published already exist;
 * this widens entries_status_check to also allow:
 *   - `scheduled`: content ready, auto-published at published_at by `entries:publish-due`.
 *   - `private`  : finished but deliberately non-public. RouteResolver already serves only
 *                  `published`, so private is non-public without a visibility engine.
 * It also adds a denormalized `view_count` (the list's "lượt xem") — fed by a Valkey-buffered
 * counter and flushed by `entries:flush-views`, never written on a storefront GET (§3.1
 * READ-ONLY-GET). entry_translations keeps its original set: a translation is only ever
 * draft→published; the new states live on the entry. Expand-only + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE entries DROP CONSTRAINT entries_status_check');
        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_status_check CHECK (status IN ('draft','pending','published','scheduled','private','archived'))");

        Schema::table('entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('view_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table): void {
            $table->dropColumn('view_count');
        });

        // Rows in the new states would violate the narrower CHECK — reset them first (dev-only down).
        DB::statement("UPDATE entries SET status = 'draft' WHERE status IN ('scheduled', 'private')");
        DB::statement('ALTER TABLE entries DROP CONSTRAINT entries_status_check');
        DB::statement("ALTER TABLE entries ADD CONSTRAINT entries_status_check CHECK (status IN ('draft','pending','published','archived'))");
    }
};
