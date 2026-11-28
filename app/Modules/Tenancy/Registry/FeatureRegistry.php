<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Registry;

/**
 * The code-defined catalog of plan features (design.md §15.2), the peer of ModuleCatalog for the
 * finer-grained grants a plan carries. A feature is a capability or a limit; feature_key is the
 * stable handle plan_features/site_features store, and `default` is what a site with no plan and
 * no override gets. A boolean feature defaults off (fail-closed); a limit defaults to the level a
 * customer with no plan is given (1 website, 500 MB, 1 language, 1 domain, 10 people per website).
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
     * the plan sets it. `plans` false marks a switch no plan carries: it is off until a super_admin
     * turns it on for one website (SiteFeatures), and a plan grant for it is never stored or read.
     *
     * @return array<string, array{name: string, kind: 'boolean'|'limit', default: bool|int, scope: 'site'|'tenant', plans?: false}>
     */
    public static function all(): array
    {
        return [
            // Whether a site may run the multi-stage editorial workflow (vs the simple gate).
            'workflow.editorial' => ['name' => 'Quy trình biên tập đa cấp', 'kind' => 'boolean', 'default' => false, 'scope' => 'site'],
            // Max websites a tenant may have (soft-deleted ones excluded); 0 = no new website.
            // Checked by ProvisionSite when a website is created, so one already above the limit stays.
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
            // People a website may hold: its members (anyone with a role there) plus the invitations
            // still waiting for an answer (SiteHeadcount). Checked when someone is invited and when an
            // invitation is accepted, so a website already above its limit keeps everyone it has.
            'users.max' => ['name' => 'Số người dùng tối đa / website', 'kind' => 'limit', 'default' => 10, 'scope' => 'site'],
            // Mails a website may send in one of its days through the platform's shared outbox before
            // its managers are told to connect their own (at 80% and 100%). Mails of the ordinary kinds
            // (invitations, domain notices) are held back to the next day only past ten times this
            // (config mail.quota.abuse_multiplier); security mails and contact-form notices are never
            // held, and mail through the website's own outbox is counted, never limited (MailQuotaGate).
            'mail.platform_daily' => ['name' => 'Số thư qua hộp thư chung mỗi ngày', 'kind' => 'limit', 'default' => 30, 'scope' => 'site'],
            // Whether the website's «Chuyển hướng» may send a visitor to another website (an https
            // address). Off: the screen does not offer it and the storefront ignores such rows.
            'redirects.external' => ['name' => 'Chuyển hướng sang website khác', 'kind' => 'boolean', 'default' => false, 'scope' => 'site', 'plans' => false],
            // Whether the website may choose how its pages, posts and products get their addresses
            // («Đường dẫn»). Gates only CHANGING the format: what a website stored keeps serving off.
            'permalinks.custom' => ['name' => 'Tự chọn kiểu đường dẫn', 'kind' => 'boolean', 'default' => true, 'scope' => 'site'],
        ];
    }

    /**
     * The features a plan may carry: every one but the switches only a super_admin turns on for one
     * website. What the plan screens list, edit and compare.
     *
     * @return array<string, array{name: string, kind: 'boolean'|'limit', default: bool|int, scope: 'site'|'tenant', plans?: false}>
     */
    public static function onPlans(): array
    {
        return array_filter(self::all(), static fn (array $feature): bool => ($feature['plans'] ?? true) !== false);
    }

    /** Whether $key is a switch no plan carries: only a website's own value (set by a super_admin) turns it on. */
    public static function siteOnly(string $key): bool
    {
        return self::has($key) && ! array_key_exists($key, self::onPlans());
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
