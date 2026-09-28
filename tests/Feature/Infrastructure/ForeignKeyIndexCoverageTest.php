<?php

use Illuminate\Support\Facades\DB;

/*
 * Deleting a parent row makes Postgres find its children with `WHERE <fk column> = $1`:
 * CASCADE deletes them, SET NULL updates them. That lookup carries no site_id and bypasses RLS,
 * so only an index LEADING with the foreign-key column serves it. With the column second, as in
 * our site_id-first `(site_id, fk)` indexes, it scans every site's rows inside the deleting
 * request's transaction (measured 28/09 on 5M rows: 316 ms per lookup, 0.06 ms with the index).
 * Data-driven like RlsCoverageTest, so every future foreign key is checked.
 *
 * An index counts when it is valid, its first column is one of the key's columns (a composite
 * `(site_id, x)` key led by site_id narrows the lookup to one site), and it is either not partial
 * or partial on exactly `<first column> IS NOT NULL`, which the lookup's `=` implies.
 */

it('gives every foreign key an index that its parent-delete lookup can use', function (): void {
    // Parents that are never hard-deleted, so Postgres never looks up their children: keys never
    // change, and only deleting the parent row triggers the lookup. Checked 28/09: no forceDelete,
    // delete() or DELETE FROM in app/ reaches these tables. Giving one of them a hard-delete path
    // means indexing its children and removing them here.
    $neverHardDeleted = [
        // Accounts are never deleted; an account-erasure flow must index these first.
        'users' => [
            'entries.assigned_to', 'entries.author_id', 'entry_revisions.created_by',
            'invitations.accepted_user_id', 'invitations.invited_by', 'site_notifications.user_id',
            'tenant_users.user_id', 'workflow_log.actor_id', 'workflow_log.assigned_to',
        ],
        // Soft-deleted only.
        'sites' => ['form_fields.site_id', 'mediables.site_id', 'site_profile_translations.site_id'],
        'tenants' => ['sites.tenant_id'],
        // Contact forms are synced by forms:sync-contact-fields, never deleted.
        'forms' => ['form_submissions.form_id'],
        // Media folders have no delete action yet.
        'media_folders' => ['media.folder_id'],
        // Reference catalogs: synced or imported, never deleted.
        'permissions' => ['model_has_permissions.permission_id'],
        'plans' => ['tenants.plan_id'],
        'provinces' => ['site_profiles.province_id'],
        'wards' => ['site_profiles.ward_id'],
        // InstantiateWorkflow replaces a workflow's states and transitions, never the workflow.
        'workflows' => ['workflow_log.workflow_id'],
    ];

    $expected = [];
    foreach ($neverHardDeleted as $parent => $children) {
        foreach ($children as $child) {
            $expected[] = "{$child} -> {$parent}";
        }
    }

    $uncovered = array_map(fn ($row) => $row->fk, DB::select(<<<'SQL'
        select c.relname || '.' || (
                   select string_agg(a.attname, ',' order by k.ord)
                   from unnest(con.conkey) with ordinality as k(attnum, ord)
                   join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.attnum
               ) || ' -> ' || p.relname as fk
        from pg_constraint con
        join pg_class c on c.oid = con.conrelid
        join pg_class p on p.oid = con.confrelid
        join pg_namespace n on n.oid = c.relnamespace
        where con.contype = 'f'
          and n.nspname = 'public'
          and not exists (
              select 1
              from pg_index i
              join pg_attribute lc on lc.attrelid = i.indrelid and lc.attnum = i.indkey[0]
              where i.indrelid = con.conrelid
                and i.indisvalid
                and i.indkey[0] = any (con.conkey)
                and (i.indpred is null
                     or pg_get_expr(i.indpred, i.indrelid) = format('(%s IS NOT NULL)', quote_ident(lc.attname)))
          )
    SQL));

    sort($expected);
    sort($uncovered);

    expect($uncovered)->toBe(
        $expected,
        'Index each new foreign key (lead with its column; partial IS NOT NULL when nullable), '
        .'or list it above if its parent is never hard-deleted.',
    );
});
