<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/**
 * Make another language the current site's default (design.md §10/ADR-11: the default is per site
 * and mutable). The new default is switched on for editors and visitors; the old one stays on and
 * public, only no longer the default. No content and no stored address changes: routes keep their
 * unprefixed paths per language and PathBuilder lays the prefix over them from the default it reads
 * on each request, so every address moves with this one row. The storefront then sends the old
 * addresses on: `/{new default}/…` answers 301 to the clean path, and a clean path that now belongs
 * to a former default answers 301 to `/{former}/…` (StorefrontController). The language stepping down
 * is recorded as a former default (site_settings locales/former_defaults, the most recent first, at
 * most MAX_FORMER) so only those languages' old addresses are sent on, never another language's.
 *
 * The caller may also say what becomes of the language stepping down (`$oldOn`, `$oldPublic`): it is
 * switched off or hidden in the same locked step, before the plan is counted, so a draft that swaps
 * a language for another at the plan's limit is not refused for a place it frees itself.
 * Switching on a language that was off counts against the plan's `locales.max`, as
 * ConfigureSiteLocale does. Who may change site settings is the caller's check. Runs inside the
 * tenant context; the site row lock serializes it with every other language change of the site.
 */
final readonly class ChangeDefaultSiteLocale
{
    private const int MAX_FORMER = 4;

    public function __construct(
        private CurrentSite $current,
        private Entitlement $entitlement,
        private SiteLocale $locales,
    ) {}

    /**
     * @throws SiteLocaleChangeRejectedException
     */
    public function handle(string $locale, bool $oldOn = true, bool $oldPublic = true): SiteLocaleModel
    {
        // Hidden-but-on is allowed; public-but-off is not (site_locales CHECK).
        $oldPublic = $oldOn && $oldPublic;
        if (preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new SiteLocaleChangeRejectedException('locale_invalid');
        }

        $siteId = $this->current->id();

        $row = DB::transaction(function () use ($siteId, $locale, $oldOn, $oldPublic): SiteLocaleModel {
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $row = SiteLocaleModel::query()->firstWhere('locale', $locale);

            if ($row !== null && $row->is_default) {
                return $row;
            }

            // At most one default per site (partial unique index): the old one steps down first,
            // taking the state the caller asked for, so a place it frees counts below.
            $old = SiteLocaleModel::query()->where('is_default', true)->value('locale');
            SiteLocaleModel::query()->where('is_default', true)
                ->update(['is_default' => false, 'enabled' => $oldOn, 'is_public' => $oldPublic]);

            if ($row === null || ! $row->enabled) {
                $limit = (int) $this->entitlement->featureValue('locales.max');
                if (SiteLocaleModel::query()->where('enabled', true)->count() >= $limit) {
                    throw new SiteLocaleChangeRejectedException('limit_reached');
                }
            }

            $this->rememberFormer(is_string($old) ? $old : null, $locale);

            $row ??= new SiteLocaleModel(['locale' => $locale]);
            $row->forceFill(['is_default' => true, 'enabled' => true, 'is_public' => true])->save();

            return $row;
        });

        $this->locales->forget();

        return $row;
    }

    /** Put the language stepping down first in the former defaults, and take the new default out. */
    private function rememberFormer(?string $old, string $new): void
    {
        $setting = SiteSetting::query()->firstOrNew(['group_name' => SiteLocale::FORMER_GROUP, 'key' => SiteLocale::FORMER_KEY]);
        $value = $setting->getAttribute('value');
        $codes = is_array($value) && is_array($value['codes'] ?? null) ? array_filter($value['codes'], 'is_string') : [];
        $codes = array_values(array_diff(array_unique([...($old !== null ? [$old] : []), ...$codes]), [$new]));

        $setting->setAttribute('value', ['codes' => array_slice($codes, 0, self::MAX_FORMER)]);
        $setting->save();
    }
}
