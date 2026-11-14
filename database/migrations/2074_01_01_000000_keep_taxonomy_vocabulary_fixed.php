<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A category's vocabulary (post category, product category, facet term…) is set when it is created
 * and never changes afterwards: the database refuses any UPDATE that changes `taxonomies.vocabulary`.
 *
 * Why: a member's category limit (member_category_scopes, invitation_category_scopes) takes its axis
 * — posts or products — from the vocabulary of the category it names. Re-typing a category would move
 * a limit from one axis to the other: a member's last post limit turning into a product limit would
 * lift their post limit without anyone choosing it. No code path changes a vocabulary today; this
 * makes it hold whatever future code does. A deliberate re-typing becomes a reviewed migration that
 * drops this trigger for its own statement and handles the limits of that category.
 *
 * A row trigger, BEFORE UPDATE OF vocabulary, firing only WHEN the value really changes: an UPDATE
 * that names the column with the same value (the content-pack applier's save) still passes. The
 * function reads no table and needs no SECURITY DEFINER; it fires for app_user like any row trigger
 * (RLS only decides which rows an UPDATE reaches). No data changes, no index, no lock beyond the
 * short one CREATE TRIGGER takes. down() drops the trigger and its function.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.taxonomies_keep_vocabulary() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'the vocabulary of category % never changes (% to %)', OLD.id, OLD.vocabulary, NEW.vocabulary
                    USING ERRCODE = '23514';
            END;
            $fn$;

            DROP TRIGGER IF EXISTS taxonomies_keep_vocabulary ON public.taxonomies;
            CREATE TRIGGER taxonomies_keep_vocabulary
                BEFORE UPDATE OF vocabulary ON public.taxonomies
                FOR EACH ROW
                WHEN (OLD.vocabulary IS DISTINCT FROM NEW.vocabulary)
                EXECUTE FUNCTION public.taxonomies_keep_vocabulary();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS taxonomies_keep_vocabulary ON public.taxonomies;
            DROP FUNCTION IF EXISTS public.taxonomies_keep_vocabulary();
            SQL);
    }
};
