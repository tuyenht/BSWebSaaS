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

    /*
    |--------------------------------------------------------------------------
    | Base domains (subdomain provisioning)
    |--------------------------------------------------------------------------
    |
    | base_domains — danh sách base hợp lệ; thêm 'bsweb.com' về sau chỉ sửa env,
    | không sửa code. primary_base — base ghép cho site tạo mới
    | (slug => {slug}.{primary_base}). trim + array_values: chống khoảng trắng
    | trong "baoson.net, bsweb.com" và re-index sạch.
    */

    'base_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BASE_DOMAINS', 'baoson.net'))
    ))),

    'primary_base' => env('PRIMARY_BASE', 'baoson.net'),

    /*
    |--------------------------------------------------------------------------
    | Platform console host (Bảo Sơn operator plane)
    |--------------------------------------------------------------------------
    |
    | The dedicated host for the operator control-plane (no tenant context).
    | Tenant admin + storefront live on the tenant's own domains.
    */

    'platform_host' => env('PLATFORM_HOST', 'platform.bswebsaas.test'),

];
