<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use Illuminate\Support\Facades\DB;

/**
 * Switch one of the current site's content languages on or off — for editors (`enabled`) and for
 * visitors (`is_public`). The single write path behind Settings › Ngôn ngữ website, so the rules
 * hold whoever calls it:
 *
 *  - the code is a lower-case BCP-47 shape the storefront can carry in a URL (en, ja, pt-br);
 *  - the default language stays enabled and public — changing WHICH language is the default is a
 *    separate operation, because it moves every canonical URL;
 *  - hiding a language from editors also hides it from visitors (public implies enabled);
 *  - switching a language on counts against the plan's `locales.max`, the default included. A site
 *    already above its limit keeps what it has; it just cannot switch on another.
 *
 * Who may change site settings is the caller's check; this enforces the site's data rules and plan
 * limit. Runs inside the tenant context.
 */
final readonly class ConfigureSiteLocale
{
    public function __construct(
        private CurrentSite $current,
        private Entitlement $entitlement,
        private SiteLocale $locales,
    ) {}

    /**
     * @throws SiteLocaleChangeRejectedException
     */
    public function handle(string $locale, bool $enabled, bool $public): SiteLocaleModel
    {
        if (preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new SiteLocaleChangeRejectedException('locale_invalid');
        }

        $public = $public && $enabled;
        $siteId = $this->current->id();

        $row = DB::transaction(function () use ($siteId, $locale, $enabled, $public): SiteLocaleModel {
            // Serialize language changes per site: two concurrent switch-ons must not both pass
            // the limit check. The site row is global (D6), so this lock needs no tenant scope.
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $row = SiteLocaleModel::query()->firstWhere('locale', $locale);

            if ($row !== null && $row->is_default && ! $public) {
                throw new SiteLocaleChangeRejectedException('default_locked');
            }

            $switchingOn = $enabled && ($row === null || ! $row->enabled);
            if ($switchingOn) {
                $limit = (int) $this->entitlement->featureValue('locales.max');
                if (SiteLocaleModel::query()->where('enabled', true)->count() >= $limit) {
                    throw new SiteLocaleChangeRejectedException('limit_reached');
                }
            }

            $row ??= new SiteLocaleModel(['locale' => $locale, 'is_default' => false]);
            $row->forceFill(['enabled' => $enabled, 'is_public' => $public])->save();

            return $row;
        });

        $this->locales->forget();

        return $row;
    }
}
