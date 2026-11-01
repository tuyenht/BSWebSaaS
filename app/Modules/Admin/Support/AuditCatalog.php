<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\VietnameseFold;

/**
 * What the audit log's action codes mean to a person: each code's plain sentence
 * (`admin.audit.act.<code>`) and the group it is filtered under on the Audit log screen.
 *
 * The groups are a fixed table of codes, not a column written with each row: regrouping later
 * then moves old and new rows together. A group filters with `action IN (…)`, which the btree on
 * `action` serves (a `LIKE 'x.%'` prefix would not, under the database's non-C collation). Stored
 * codes are never renamed; a code missing here reads "Thao tác khác" and belongs to no group.
 * Raw codes never reach the screen.
 */
final class AuditCatalog
{
    /** @var array<string, list<string>> group key => its codes, in the order the filter lists them */
    public const array GROUPS = [
        'sign_in' => [
            'auth.login.succeeded', 'auth.login.failed', 'auth.login.throttled', 'auth.login.captcha_failed',
            'auth.login.platform_denied', 'auth.otp.locked', 'auth.otp.send_failed', 'auth.otp.throttled',
            'auth.password.reset', 'auth.password.forgot_throttled', 'auth.password.reset_send_failed',
        ],
        'members' => [
            'rbac.role.assigned', 'rbac.role.revoked', 'rbac.role.created', 'rbac.role.updated', 'rbac.role.deleted',
            'rbac.invitation.sent', 'rbac.invitation.accepted', 'rbac.invitation.revoked', 'rbac.invitation.send_failed',
            'cms.category_scopes.changed', 'cms.category_scopes.cleared',
        ],
        'content' => [
            'entries.status.changed', 'entries.change.applied', 'entries.change.discarded', 'entries.change.folded',
            'entries.revision.restored',
        ],
        'menus' => [
            'cms.menu.saved', 'cms.menu.assigned', 'cms.menu.deleted', 'cms.menu_item.saved', 'cms.menu_item.moved',
            'cms.menu_item.deleted', 'cms.taxonomy.created', 'cms.taxonomy.updated', 'cms.taxonomy.moved',
            'cms.taxonomy.deleted',
        ],
        'domains' => [
            'tenancy.domain.attached', 'tenancy.domain.verified', 'tenancy.domain.primary_changed',
            'tenancy.domain.spelling_changed', 'tenancy.domain.https_requested', 'tenancy.domain.taken_over',
            'tenancy.domain.removed', 'tenancy.domain.failing', 'tenancy.domain.expiring', 'tenancy.domain.recovered',
            'tenancy.domain.lost', 'tenancy.domain.wait_expired', 'tenancy.domain.notice_failed', 'tenancy.domain.help_requested',
        ],
        'plans' => [
            'tenancy.customer.plan_changed', 'provisioning.customer.plan_changed',
            'tenancy.plan.created', 'provisioning.plan.created', 'tenancy.plan.updated', 'provisioning.plan.updated',
            'tenancy.plan.duplicated', 'tenancy.plan.reordered',
            'tenancy.plan.features_changed', 'provisioning.plan.features_changed',
            'tenancy.plan.upgrade_invitation_changed', 'provisioning.plan.upgrade_invitation_changed',
            'tenancy.site.feature_overridden', 'provisioning.site.feature_overridden',
            'tenancy.site.feature_override_cleared', 'provisioning.site.feature_override_cleared',
            'provisioning.site.locale_changed',
        ],
        'new_site' => ['provisioning.site.provisioned'],
        'support' => ['support.session.issued', 'support.session.entered', 'support.session.ended', 'support.session.notice_failed'],
        'settings' => ['settings.support_access.changed', 'platform.security.turnstile_login'],
    ];

    /** @var array<string, string>|null code => group key */
    private ?array $groupOf = null;

    public function label(string $code): string
    {
        return $this->groupOf($code) === null ? __('admin.audit.act_other') : __('admin.audit.act.'.$code);
    }

    public function groupOf(string $code): ?string
    {
        if ($this->groupOf === null) {
            $this->groupOf = [];
            foreach (self::GROUPS as $group => $codes) {
                foreach ($codes as $one) {
                    $this->groupOf[$one] = $group;
                }
            }
        }

        return $this->groupOf[$code] ?? null;
    }

    public function groupLabel(string $group): string
    {
        return __('admin.audit.group.'.$group);
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    public function codesIn(array $groups): array
    {
        $codes = [];
        foreach ($groups as $group) {
            array_push($codes, ...(self::GROUPS[$group] ?? []));
        }

        return array_values(array_unique($codes));
    }

    /**
     * The codes whose sentence, in the current language, contains $folded (a needle already folded
     * by VietnameseFold::text): typing "trình đơn" finds the menu rows although their codes say "cms.menu".
     *
     * @return list<string>
     */
    public function codesMatching(string $folded): array
    {
        if ($folded === '') {
            return [];
        }

        $codes = [];
        foreach (self::GROUPS as $group) {
            foreach ($group as $code) {
                if (str_contains(VietnameseFold::text($this->label($code)), $folded)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }
}
