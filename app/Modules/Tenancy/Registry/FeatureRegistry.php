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
     * `scope` says whose the feature is: `site` — one website holds it, so a single website can be
     * given its own value; `tenant` — it belongs to the customer across all their websites, so only
     * the plan sets it.
     *
     * @return array<string, array{name: string, kind: 'boolean'|'limit', default: bool|int, scope: 'site'|'tenant'}>
     */
    public static function all(): array
    {
        return [
            // Whether a site may run the multi-stage editorial workflow (vs the simple gate).
            'workflow.editorial' => ['name' => 'Quy trình biên tập đa cấp', 'kind' => 'boolean', 'default' => false, 'scope' => 'site'],
            // Max sites a tenant may provision (0 = unset → caller treats as its own baseline).
            'sites.max' => ['name' => 'Số site tối đa', 'kind' => 'limit', 'default' => 1, 'scope' => 'tenant'],
            // Media storage ceiling in megabytes.
            'media.storage_mb' => ['name' => 'Dung lượng media (MB)', 'kind' => 'limit', 'default' => 500, 'scope' => 'site'],
            // Content languages a site may have enabled, its default included — 2 = Vietnamese plus
            // one foreign language. Checked only when a language is switched on, so a site already
            // above its plan keeps what it has.
            'locales.max' => ['name' => 'Số ngôn ngữ tối đa', 'kind' => 'limit', 'default' => 1, 'scope' => 'site'],
            // Customer-owned domains a site may attach. A root domain and its `www` count as one.
            'custom_domains.max' => ['name' => 'Số tên miền riêng tối đa', 'kind' => 'limit', 'default' => 1, 'scope' => 'site'],
            // Whether the site may create its own roles (fork a system role or start blank). The
            // six system roles stay available and locked either way.
            'rbac.custom_roles' => ['name' => 'Nhóm quyền tùy chỉnh', 'kind' => 'boolean', 'default' => false, 'scope' => 'site'],
            // Whether the site's managers may limit a member to some categories. Gates only CHANGING
            // the limits: limits already set keep applying when the feature is off (fail-closed).
            'rbac.category_scopes' => ['name' => 'Giới hạn chuyên mục theo thành viên', 'kind' => 'boolean', 'default' => false, 'scope' => 'site'],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Why $value cannot be stored for $key, or null when it can: the feature must exist, a switch
     * takes true/false, a limit a whole number of 0 or more.
     *
     * @return 'unknown_feature'|'wrong_kind'|null
     */
    public static function refusal(string $key, bool|int $value): ?string
    {
        $feature = self::all()[$key] ?? null;

        if ($feature === null) {
            return 'unknown_feature';
        }

        $ofKind = $feature['kind'] === 'limit' ? is_int($value) && $value >= 0 : is_bool($value);

        return $ofKind ? null : 'wrong_kind';
    }

    /** The fail-closed default for a feature, or false for an unknown key. */
    public static function default(string $key): bool|int
    {
        return self::all()[$key]['default'] ?? false;
    }
}
