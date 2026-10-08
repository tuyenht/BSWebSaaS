<?php

use App\Modules\Tenancy\Registry\FeatureRegistry;

/*
 * The plan feature that lets a site's managers limit members to some categories: a per-site switch,
 * off unless a plan or a site override grants it.
 */
it('declares category limits as a per-site switch that is off by default', function (): void {
    expect(FeatureRegistry::all()['rbac.category_scopes'] ?? null)->toBe([
        'name' => 'Giới hạn chuyên mục theo thành viên',
        'kind' => 'boolean',
        'default' => false,
        'scope' => 'site',
    ])
        ->and(FeatureRegistry::default('rbac.category_scopes'))->toBeFalse()
        ->and(FeatureRegistry::refusal('rbac.category_scopes', true))->toBeNull()
        ->and(FeatureRegistry::refusal('rbac.category_scopes', 1))->toBe('wrong_kind');
});
