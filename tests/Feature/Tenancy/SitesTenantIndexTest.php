<?php

use Illuminate\Support\Facades\DB;

/*
 * The `sites.max` count (`WHERE tenant_id = ? AND deleted_at IS NULL`, ProvisionSite) has an index
 * of its own shape, so it does not read every customer's websites.
 */

it('indexes the live websites of a customer for the sites.max count', function (): void {
    $index = DB::selectOne(<<<'SQL'
        select pg_get_indexdef(i.indexrelid) as def, i.indisvalid as valid
        from pg_index i
        where i.indexrelid = to_regclass('sites_tenant_id_live_index')
    SQL);

    expect($index)->not->toBeNull()
        ->and($index->valid)->toBeTrue()
        ->and($index->def)->toContain('ON public.sites USING btree (tenant_id) WHERE (deleted_at IS NULL)');
});
