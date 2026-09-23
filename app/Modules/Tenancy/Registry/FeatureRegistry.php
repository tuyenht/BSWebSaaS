<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Registry;

/**
 * The code-defined catalog of plan features (design.md §15.2), the peer of ModuleCatalog for the
 * finer-grained grants a plan carries. A feature is a capability or a limit; feature_key is the
 * stable handle plan_features/site_features store, and `default` is what a site with no plan and
 * no override gets — fail-closed by design (a boolean feature defaults off; a limit defaults 0).
 *
 * Adding a capability = adding a row here; no schema change. Nothing enforces a feature until the
 * code that owns it reads Entitlement::featureValue() — this registry only says a feature EXISTS
 * and what it means, so unknown keys can be rejected at write time and defaults resolved at read.
 */
final class FeatureRegistry
{
    /**
     * @return array<string, array{name: string, kind: 'boolean'|'limit', default: bool|int}>
     */
    public static function all(): array
    {
        return [
            // Whether a site may run the multi-stage editorial workflow (vs the simple gate).
            'workflow.editorial' => ['name' => 'Quy trình biên tập đa cấp', 'kind' => 'boolean', 'default' => false],
            // Max sites a tenant may provision (0 = unset → caller treats as its own baseline).
            'sites.max' => ['name' => 'Số site tối đa', 'kind' => 'limit', 'default' => 1],
            // Media storage ceiling in megabytes.
            'media.storage_mb' => ['name' => 'Dung lượng media (MB)', 'kind' => 'limit', 'default' => 500],
            // Whether the site may create its own roles (fork a system role or start blank). The
            // six system roles stay available and locked either way.
            'rbac.custom_roles' => ['name' => 'Nhóm quyền tùy chỉnh', 'kind' => 'boolean', 'default' => false],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** The fail-closed default for a feature, or false for an unknown key. */
    public static function default(string $key): bool|int
    {
        return self::all()[$key]['default'] ?? false;
    }
}
