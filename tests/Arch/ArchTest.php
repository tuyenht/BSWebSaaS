<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Support\Facades\Gate;

/*
 * Architecture invariants (CLAUDE.md §6).
 * Placeholders for now — each is promoted from ->todo() to a real arch
 * expectation as the relevant layer lands. They MUST be enforced before
 * the feature that needs them ships.
 */

// 1) Every tenant Eloquent model must use the BelongsToSite trait (RLS global scope).
//    Concrete for the canary now; extend the expectation as tenant models land.
arch('tenant models use the BelongsToSite trait')
    ->expect(TenantProbe::class)
    ->toUse(BelongsToSite::class);

// 2) Every entry action must authorize through a Policy (RBAC). The slice is
//    Livewire-first (no controllers): the Entry model has a bound policy, and the
//    admin entry components authorize through it (the AuthorizesRequests arch rule
//    in ModuleBoundariesTest enforces the components actually call it).
test('every Entry action routes through EntryPolicy', function () {
    expect(Gate::getPolicyFor(Entry::class))->toBeInstanceOf(EntryPolicy::class);
});

// 3) Every workflow state transition must go through the workflow engine.
test('workflow transitions go through the engine')
    ->todo('Enforce once the workflow engine exists.');
