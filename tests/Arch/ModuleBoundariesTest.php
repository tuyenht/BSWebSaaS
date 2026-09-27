<?php

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/*
 * Module boundary invariants (CLAUDE.md §6 — "ranh giới module"; design.md Mục 22.1).
 *
 * Modular Monolith: each bounded context lives under app/Modules/<Context>/.
 * Dependency direction is ACYCLIC and Tenancy is the foundation (Gate #1) — it
 * must depend on NO feature module. These are REAL assertions (not ->todo): they
 * pass vacuously while a module is still empty, and fail CI the moment a violating
 * class lands. Add a module's namespace to the lists below as it is built.
 */

// Auto-discover module namespaces from the filesystem so these boundary rules never go stale
// as modules land (the previous hand-kept list both named unbuilt modules and silently omitted
// built ones). Tenancy is the base; Shared is the interface seam.
// Resolved relative to this file (not app_path()) because arch rules are collected before the
// application container is booted.
$modulesPath = __DIR__.'/../../app/Modules';
$modules = collect(scandir($modulesPath) ?: [])
    ->filter(fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir("{$modulesPath}/{$entry}"))
    ->values();

$namespacesExcept = fn (array $keep): array => $modules
    ->reject(fn (string $module): bool => in_array($module, $keep, true))
    ->map(fn (string $module): string => "App\\Modules\\{$module}")
    ->all();

// Tenancy is foundational (Gate #1) — it may depend on NO other module (only the Shared seam).
arch('Tenancy module depends on no feature module')
    ->expect('App\\Modules\\Tenancy')
    ->not->toUse($namespacesExcept(['Tenancy', 'Shared']));

// Direct readers of Tenancy\Models are the documented exceptions (app/Modules/README.md): the
// Admin shell + Provisioning orchestrator read its model registry (e.g. ContentSection), and
// Identity owns the core User<->Tenant relation. Theme is listed PROVISIONALLY — it currently
// reads SiteSetting for per-site theme tokens and is under review to move behind a Shared
// contract; until that is decided it is named here so the rule mirrors reality. Every other
// module must reach tenancy only through its public surface (BelongsToSite trait, the
// Tenancy\Context classes).
//
// One rule PER module: with an array on the expect() side, `->not->toUse()` only fails when
// EVERY listed module violates, so the single-rule form passed while four modules read the
// models directly. The reads that predated the fix now go through Tenancy\Context (the debt list
// that tracked them is empty and gone); anything new fails CI.
foreach ($namespacesExcept(['Tenancy', 'Shared', 'Admin', 'Provisioning', 'Identity', 'Theme']) as $namespace) {
    arch("{$namespace} reaches tenancy through its public surface, not its models")
        ->expect($namespace)
        ->not->toUse('App\\Modules\\Tenancy\\Models');
}

// Shared contracts are the only sanctioned cross-module interface seam.
arch('Shared namespace holds interfaces only')
    ->expect('App\\Modules\\Shared\\Contracts')
    ->toBeInterfaces();

// Every admin entry component authorizes through a policy (structural promotion of
// ArchTest #2 "every action through Policy"): each carries the trait that exposes
// $this->authorize(), and the behavioural tests prove the calls actually gate.
arch('admin entry components authorize through a policy')
    ->expect('App\\Modules\\Admin\\Livewire\\Entries')
    ->toUse(AuthorizesRequests::class);

// Theme is pure presentation: it renders primitives (PageRenderer::render(type, …)) and
// must reach into NO content/delivery/orchestration module — that is what lets any module
// register components into Theme's shared registry without forming a cycle.
arch('Theme module is content-agnostic')
    ->expect('App\\Modules\\Theme')
    ->not->toUse([
        'App\\Modules\\Cms',
        'App\\Modules\\Storefront',
        'App\\Modules\\Admin',
        'App\\Modules\\Provisioning',
        'App\\Modules\\Rbac',
        'App\\Modules\\Identity',
        'App\\Modules\\Audit',
    ]);

// Cms is the content layer — it must not reach up into its consumers (the two delivery
// planes + the provisioning orchestrator); they depend on Cms, never the reverse.
arch('Cms module does not depend on its consumers')
    ->expect('App\\Modules\\Cms')
    ->not->toUse([
        'App\\Modules\\Storefront',
        'App\\Modules\\Admin',
        'App\\Modules\\Provisioning',
    ]);

// Identity sits below Rbac (Rbac builds roles/permissions on top of the user).
arch('Identity module does not depend on Rbac')
    ->expect('App\\Modules\\Identity')
    ->not->toUse('App\\Modules\\Rbac');

// The two public delivery planes are independent: the storefront never reaches into admin.
arch('Storefront module does not depend on Admin')
    ->expect('App\\Modules\\Storefront')
    ->not->toUse('App\\Modules\\Admin');
