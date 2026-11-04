<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Theming\MenuAreas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Make a region show another region's menu for one language ("Dùng trình đơn chung": the phone
 * menu shows the main menu) instead of a menu of its own. The same gate as {@see AssignMenu}, and:
 *  - only the region the theme names for it (MenuAreas::canSame) can be followed (same_invalid);
 *  - one hop only: a region that follows another cannot be followed, and a region another region
 *    follows cannot start following (same_chain), so the website never walks a chain;
 *  - the region's own menu is cleared in the same write (menu_id NULL), so the table's one-target
 *    CHECK holds.
 * The theme's own declaration has no chains, which is what keeps two editors from building one
 * at the same moment; the website reads one hop at most whatever is stored (MenuResolver).
 */
final readonly class FollowMenuArea
{
    public function __construct(private SiteLocale $siteLocales) {}

    /**
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, string $region, string $locale, string $followed): void
    {
        Gate::forUser($actor)->authorize('menus.manage');

        if (preg_match(MenuRules::REGION_PATTERN, $region) !== 1 || preg_match(MenuRules::REGION_PATTERN, $followed) !== 1) {
            throw new MenuChangeRejectedException('region_invalid');
        }
        if (! in_array($locale, $this->siteLocales->enabled(), true)) {
            throw new MenuChangeRejectedException('locale_invalid');
        }
        if (MenuAreas::canSame($region) !== $followed) {
            throw new MenuChangeRejectedException('same_invalid');
        }

        $followedFollows = MenuAssignment::query()->where('region_key', $followed)->where('locale', $locale)->whereNotNull('same_as_region')->exists();
        $isFollowed = MenuAssignment::query()->where('same_as_region', $region)->where('locale', $locale)->exists();
        if ($followedFollows || $isFollowed) {
            throw new MenuChangeRejectedException('same_chain');
        }

        MenuAssignment::query()->updateOrCreate(['region_key' => $region, 'locale' => $locale], ['menu_id' => null, 'same_as_region' => $followed]);

        Audit::record('cms.menu.followed', [
            'meta' => ['region' => $region, 'locale' => $locale, 'same_as' => $followed],
        ]);
    }
}
