<?php

use App\Modules\Rbac\Registry\PermissionRegistry;

/*
 * T2 increment-2 — role templates become keyed by site_type (design.md §15.2). GĐ1 ships only
 * `corporate`; its set is the existing 6 tenant roles unchanged (behavior-preserving). An unknown
 * type yields an empty set so callers never silently inherit the wrong shape. Pure registry — Unit.
 */

it('keys tenant role templates by site_type — corporate returns the 6 canonical roles', function (): void {
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate');

    expect(array_keys($tpl))->toBe(['owner', 'admin', 'editor', 'author', 'contributor', 'viewer'])
        ->and($tpl['owner']['level'])->toBe(90)
        ->and($tpl['admin']['level'])->toBe(70)
        ->and($tpl['editor']['level'])->toBe(50)
        ->and($tpl['author']['level'])->toBe(30)
        ->and($tpl['contributor']['level'])->toBe(20)
        ->and($tpl['viewer']['level'])->toBe(10)
        ->and($tpl['owner']['permissions'])->toBe('*');
});

it('returns an empty template set for an unknown site_type', function (): void {
    expect(PermissionRegistry::tenantRoleTemplates('does_not_exist'))->toBe([]);
});
