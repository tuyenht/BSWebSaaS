<?php

use Illuminate\Support\Facades\DB;

/*
 * Gate #1 — self-maintaining RLS guardrail (design.md §5 / D6). The invariant: ANY
 * table with a site_id column IS a tenant table and therefore MUST have RLS enabled
 * + FORCE + at least one policy. This is data-driven, so every future content/commerce
 * table is covered automatically — a forgotten RLS on a new tenant table fails CI here.
 */

it('enforces RLS + FORCE on every table that has a site_id column', function () {
    $unprotected = DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind = 'r'
          -- GLOBAL tables that legitimately carry a site_id column but are NOT
          -- RLS-protected (documented exceptions):
          --   • domains — the host resolver reads it BEFORE any tenant context exists.
          --   • roles / model_has_roles / model_has_permissions — RBAC authz metadata
          --     resolved during auth (outside any tenant tx), scoped IN-APP by the
          --     spatie team key (= site_id); platform rows use sentinel site_id 0.
          --   • audit_logs — append-only platform trail; best-effort writes may have
          --     no tenant context, operators need cross-site visibility.
          and c.relname not in ('domains', 'roles', 'model_has_roles', 'model_has_permissions', 'audit_logs')
          and exists (
            select 1 from information_schema.columns col
            where col.table_schema = 'public'
              and col.table_name = c.relname
              and col.column_name = 'site_id'
          )
          and (c.relrowsecurity = false or c.relforcerowsecurity = false)
        order by c.relname
    SQL);

    expect(array_map(fn ($r) => $r->relname, $unprotected))->toBe([]);
});

it('has at least one isolation policy on every tenant table', function () {
    $withoutPolicy = DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind = 'r'
          -- GLOBAL tables that legitimately carry a site_id column but are NOT
          -- RLS-protected (documented exceptions):
          --   • domains — the host resolver reads it BEFORE any tenant context exists.
          --   • roles / model_has_roles / model_has_permissions — RBAC authz metadata
          --     resolved during auth (outside any tenant tx), scoped IN-APP by the
          --     spatie team key (= site_id); platform rows use sentinel site_id 0.
          --   • audit_logs — append-only platform trail; best-effort writes may have
          --     no tenant context, operators need cross-site visibility.
          and c.relname not in ('domains', 'roles', 'model_has_roles', 'model_has_permissions', 'audit_logs')
          and exists (
            select 1 from information_schema.columns col
            where col.table_schema = 'public'
              and col.table_name = c.relname
              and col.column_name = 'site_id'
          )
          and not exists (select 1 from pg_policy p where p.polrelid = c.oid)
        order by c.relname
    SQL);

    expect(array_map(fn ($r) => $r->relname, $withoutPolicy))->toBe([]);
});
