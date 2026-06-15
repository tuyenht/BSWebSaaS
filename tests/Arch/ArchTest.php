<?php

/*
 * Architecture invariants (CLAUDE.md §6).
 * Placeholders for now — each is promoted from ->todo() to a real arch
 * expectation as the relevant layer lands. They MUST be enforced before
 * the feature that needs them ships.
 */

// 1) Every tenant Eloquent model must use the BelongsToSite trait (RLS global scope).
test('tenant models use the BelongsToSite trait')
    ->todo('Enforce once App\\Models\\Tenant\\* exist (Gate #1+).');

// 2) Every controller action must authorize through a Policy (RBAC).
test('controller actions authorize through a Policy')
    ->todo('Enforce once controllers and policies exist.');

// 3) Every workflow state transition must go through the workflow engine.
test('workflow transitions go through the engine')
    ->todo('Enforce once the workflow engine exists.');
