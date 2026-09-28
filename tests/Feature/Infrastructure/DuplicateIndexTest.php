<?php

use Illuminate\Support\Facades\DB;

/*
 * An index whose key columns repeat the leading columns of another btree index on the same table
 * (same operator classes, collations and sort options; neither partial nor on expressions; no
 * INCLUDE columns) serves no lookup the longer index cannot, while every insert and non-HOT
 * update still maintains it (ADR-18: each index is a write cost). Unique indexes are exempt: they
 * enforce a constraint. Data-driven, so a duplicate added by any future migration fails here.
 */

it('has no index that repeats the leading columns of another index', function (): void {
    $duplicates = array_map(fn ($row) => $row->duplicate, DB::select(<<<'SQL'
        select a.indexrelid::regclass::text || ' (covered by ' || b.indexrelid::regclass::text || ')' as duplicate
        from pg_index a
        join pg_index b on b.indrelid = a.indrelid and b.indexrelid <> a.indexrelid
        join pg_class ia on ia.oid = a.indexrelid
        join pg_class ib on ib.oid = b.indexrelid
        join pg_class t on t.oid = a.indrelid
        join pg_namespace n on n.oid = t.relnamespace
        where n.nspname = 'public'
          and ia.relam = (select oid from pg_am where amname = 'btree')
          and ib.relam = ia.relam
          and b.indisvalid
          and not a.indisunique
          and a.indnatts = a.indnkeyatts
          and a.indpred is null and b.indpred is null
          and a.indexprs is null and b.indexprs is null
          and a.indnkeyatts <= b.indnkeyatts
          and (b.indkey::int2[])[0:a.indnkeyatts - 1] = (a.indkey::int2[])[0:a.indnkeyatts - 1]
          and (b.indclass::oid[])[0:a.indnkeyatts - 1] = (a.indclass::oid[])[0:a.indnkeyatts - 1]
          and (b.indcollation::oid[])[0:a.indnkeyatts - 1] = (a.indcollation::oid[])[0:a.indnkeyatts - 1]
          and (b.indoption::int2[])[0:a.indnkeyatts - 1] = (a.indoption::int2[])[0:a.indnkeyatts - 1]
        order by 1
    SQL));

    expect($duplicates)->toBe([], 'Drop the shorter index, or make it unique if it enforces a constraint.');
});
