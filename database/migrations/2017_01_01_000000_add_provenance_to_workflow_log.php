<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Makes an approval-trail row self-describing.
 *
 * A site runs MANY workflows — one per content type (UNIQUE site_id + applies_to_type) — but a
 * trail row only carried the state/transition KEYS, so nothing said which flow it came from.
 *
 * `workflow_id` answers that. It is a soft reference (nullable, SET NULL) like actor_id: a deleted
 * workflow must never erase the history of what people actually did. The engine always writes it.
 *
 * `labels` keeps the row READABLE forever. Applying a template rebuilds states/transitions and a
 * template swap reuses the same workflow row, so a key recorded today may name nothing tomorrow —
 * resolving labels through a live join would then print raw keys ("pending") into a Vietnamese
 * timeline. Display text is therefore snapshotted at write time: {transition, from, to}. It is
 * never filtered or sorted, so jsonb is the right home for it (§3 "tốt nghiệp" rule).
 *
 * Expand-only, no index added (measure-first, §3.1): reads go through the existing
 * (site_id, entry_id, created_at) trail index, and workflows are a handful of rows per site.
 * Safe as a plain ALTER: the table is empty in every environment (dev + prod verified 0 rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('workflow_id')->nullable()->after('site_id');
            $table->jsonb('labels')->default('{}')->after('to_state');

            $table->foreign('workflow_id')->references('id')->on('workflows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_log', function (Blueprint $table): void {
            $table->dropForeign(['workflow_id']);
            $table->dropColumn(['workflow_id', 'labels']);
        });
    }
};
