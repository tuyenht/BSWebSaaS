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

$featureModules = [
    'App\\Modules\\Cms',
    'App\\Modules\\Commerce',
    'App\\Modules\\Media',
    'App\\Modules\\Rbac',
    'App\\Modules\\Taxonomy',
    'App\\Modules\\Forms',
];

// Tenancy is foundational — it may not reach into any feature module.
arch('Tenancy module depends on no feature module')
    ->expect('App\\Modules\\Tenancy')
    ->not->toUse($featureModules);

// Feature modules must not reach into another feature module's internals;
// cross-module coupling at swap/external points goes through Shared\Contracts.
arch('feature modules do not depend on Tenancy internals (only its public surface)')
    ->expect($featureModules)
    ->not->toUse('App\\Modules\\Tenancy\\Models');

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
