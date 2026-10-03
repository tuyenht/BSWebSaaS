<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\OverrideSiteFeature;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may give ONE website its own value for a feature, and the trail it leaves (design.md §15.2)
 * — the entry point for the platform console. Only platform staff holding
 * `platform.entitlements.manage` (managing websites is not enough): what a website is granted is
 * the platform's call, never the website's own.
 *
 * The rules of the change itself live in OverrideSiteFeature; a refused change throws its
 * FeatureChangeRejectedException and leaves no trail.
 */
final readonly class SiteFeatures
{
    public function __construct(
        private Tenancy $tenancy,
        private PlatformAccess $platform,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws FeatureChangeRejectedException unknown_feature · wrong_kind · customer_wide
     */
    public function override(User $actor, Site $site, string $key, bool|int $value): void
    {
        $this->change($actor, $site, $key, $value, 'tenancy.site.feature_overridden');
    }

    /**
     * Take the website's own value away: its customer's plan decides again.
     *
     * @throws AuthorizationException
     * @throws FeatureChangeRejectedException unknown_feature · customer_wide
     */
    public function clear(User $actor, Site $site, string $key): void
    {
        $this->change($actor, $site, $key, null, 'tenancy.site.feature_override_cleared');
    }

    private function change(User $actor, Site $site, string $key, bool|int|null $value, string $action): void
    {
        if (! $this->platform->isSuperAdmin($actor) && ! $this->platform->can($actor, 'platform.entitlements.manage')) {
            throw new AuthorizationException;
        }

        $siteId = Cast::int($site->getKey());

        $this->tenancy->enter($siteId, function () use ($actor, $siteId, $key, $value, $action): void {
            // What the website had before the change — its override, or what its plan gave it.
            $was = app(Entitlement::class)->featureValue($key);

            app(OverrideSiteFeature::class)->handle($key, $value);

            Audit::record($action, [
                'site_id' => $siteId,
                'actor_id' => Cast::int($actor->getKey()),
                'actor_label' => $actor->email,
                'meta' => array_filter(['feature' => $key, 'value' => $value, 'was' => $was], fn (mixed $v): bool => $v !== null),
            ]);
        });
    }
}
