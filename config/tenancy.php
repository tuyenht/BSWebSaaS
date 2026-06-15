<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant isolation assertion (Gate #1, design.md §5 layer #2)
    |--------------------------------------------------------------------------
    |
    | strict — assert on every Tenancy::enter (dev/CI). sample — 1/sample_rate
    | (prod). off — disabled. The per-query in-memory guard follows the same flag.
    */

    'assert' => env('TENANCY_ASSERT', 'strict'),

    'sample_rate' => (int) env('TENANCY_ASSERT_SAMPLE_RATE', 100),

];
