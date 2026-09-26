<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Bind a menu to a theme region for one language, or unbind it (null). One menu per
 * (region × language); a language without a binding shows the default language's menu
 * (MenuResolver). The language must be one the site writes in.
 */
final readonly class AssignMenu
{
    public function __construct(private SiteLocale $siteLocales) {}

    /**
     * @throws MenuChangeRejectedException
     */
    public function handle(string $region, string $locale, ?Menu $menu): void
    {
        if (preg_match(MenuRules::REGION_PATTERN, $region) !== 1) {
            throw new MenuChangeRejectedException('region_invalid');
        }
        if (! in_array($locale, $this->siteLocales->enabled(), true)) {
            throw new MenuChangeRejectedException('locale_invalid');
        }

        if ($menu === null) {
            MenuAssignment::query()->where('region_key', $region)->where('locale', $locale)->delete();
        } else {
            MenuAssignment::query()->updateOrCreate(['region_key' => $region, 'locale' => $locale], ['menu_id' => $menu->id]);
        }

        Audit::record('cms.menu.assigned', [
            'auditable_type' => $menu === null ? null : Menu::class,
            'auditable_id' => $menu?->id,
            'meta' => ['region' => $region, 'locale' => $locale, 'menu' => $menu?->handle],
        ]);
    }
}
