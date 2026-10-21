<?php

use App\Modules\Tenancy\Domains\DomainName;

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
    | Reserved labels (never a new site's {slug} under a platform base)
    |--------------------------------------------------------------------------
    |
    | Names kept for the platform's own services, whether or not the service runs yet
    | (admin., api., mail., sgtm. per ADR-22…): a site provisioned on one would hand a
    | customer a name the platform needs. The defaults (DomainName::RESERVED_LABELS) ALWAYS apply,
| even with a stale config cache; TENANCY_RESERVED_LABELS
    | (comma-separated) only adds to them, so a typo in an env file can never drop `admin`.
    | Whole-label match: `admins` or `my-admin` stay free. Generic shop words (shop, store,
    | blog) and environment words (demo, staging) are left to customers on purpose.
    */

    'reserved_labels' => array_values(array_unique(array_filter(
        array_map(fn (string $label): string => strtolower(trim($label)), [
            ...DomainName::RESERVED_LABELS,
            ...explode(',', (string) env('TENANCY_RESERVED_LABELS', '')),
        ]),
        fn (string $label): bool => $label !== '' && ! str_contains($label, '.'),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Platform console host (Bảo Sơn operator plane)
    |--------------------------------------------------------------------------
    |
    | The dedicated host for the operator control-plane (no tenant context).
    | Tenant admin + storefront live on the tenant's own domains.
    */

    'platform_host' => env('PLATFORM_HOST', 'platform.bswebsaas.test'),

    /*
    |--------------------------------------------------------------------------
    | Customers' own domains (design.md §28)
    |--------------------------------------------------------------------------
    |
    | target — the ONE host every customer points their domain at (CNAME for www / a subdomain,
    | ALIAS or CNAME flattening for the root). It must stay DNS-only and resolve to the web
    | server: moving servers then means changing this host's single A record. Defaults to the
    | platform host. txt_label — the DNS label of the TXT record that settles a contested domain
    | ({label}.{domain}); neutral by default so nothing names the platform (white-label).
    */

    /*
    |--------------------------------------------------------------------------
    | Keep platform subdomains out of search engines
    |--------------------------------------------------------------------------
    | On: a site still served on its platform subdomain ({slug}.{base}) tells
    | search engines not to index it (X-Robots-Tag: noindex) — it is a working
    | address, not the customer's brand. A customer's own domain, and any
    | subdomain of it, is always indexed normally. Off by default so a live site
    | that has no domain of its own yet does not drop out of search by surprise.
    */
    'noindex_platform_subdomains' => (bool) env('NOINDEX_PLATFORM_SUBDOMAINS', false),

    'custom_domains' => [
        'target' => env('CUSTOM_DOMAIN_TARGET') ?: env('PLATFORM_HOST', 'platform.bswebsaas.test'),
        'txt_label' => env('CUSTOM_DOMAIN_TXT_LABEL', '_site-verification'),
        // Where the first HTTPS request for a domain is sent (host:port of the web server), with
        // the customer's host kept as SNI. Blank = the domain's public address.
        'tls_endpoint' => env('CUSTOM_DOMAIN_TLS_ENDPOINT', ''),
        'tls_timeout' => (int) env('CUSTOM_DOMAIN_TLS_TIMEOUT', 45),
    ],

];
