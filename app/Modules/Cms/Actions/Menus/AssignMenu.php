<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Bind a menu to a theme region for one language, or unbind it (null). One menu per
 * (region × language); a language without a binding shows the default language's menu
 * (MenuResolver). The language must be one the site writes in. A region that followed another
 * region ({@see FollowMenuArea}) stops following it: the same write clears same_as_region, so the
 * table's one-target CHECK always holds. Only someone who may manage menus can change what a
 * region shows.
 */
final readonly class AssignMenu
{
    public function __construct(private SiteLocale $siteLocales) {}

    /**
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, string $region, string $locale, ?Menu $menu): void
    {
        Gate::forUser($actor)->authorize('manage', Menu::class);

        if (preg_match(MenuRules::REGION_PATTERN, $region) !== 1) {
            throw new MenuChangeRejectedException('region_invalid');
        }
        if (! in_array($locale, $this->siteLocales->enabled(), true)) {
            throw new MenuChangeRejectedException('locale_invalid');
        }

        if ($menu === null) {
            MenuAssignment::query()->where('region_key', $region)->where('locale', $locale)->delete();
        } else {
            MenuAssignment::query()->updateOrCreate(['region_key' => $region, 'locale' => $locale], ['menu_id' => $menu->id, 'same_as_region' => null]);
        }

        Audit::record('cms.menu.assigned', [
            'auditable_type' => $menu === null ? null : Menu::class,
            'auditable_id' => $menu?->id,
            'meta' => ['region' => $region, 'locale' => $locale, 'menu' => $menu?->handle],
        ]);
    }
}
