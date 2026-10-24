<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Create a menu or rename one. A new menu gets a stable handle from its name (unique per site,
 * "-2", "-3"… on a clash) that never changes on rename. `locales` records which languages the menu
 * is written for (null = all) — admin metadata; the website picks a menu by assignment.
 *
 * Who may manage menus (`menus.manage`) is the caller's check. Runs inside the tenant context.
 */
final readonly class SaveMenu
{
    private const int NAME_MAX = 120;

    public function __construct(private SiteLocale $siteLocales) {}

    /**
     * @param  list<string>|null  $locales
     *
     * @throws MenuChangeRejectedException
     */
    public function handle(?Menu $menu, string $name, ?array $locales = null): Menu
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new MenuChangeRejectedException('name_invalid');
        }
        if ($locales !== null) {
            $locales = array_values(array_unique($locales));
            if (array_diff($locales, $this->siteLocales->enabled()) !== []) {
                throw new MenuChangeRejectedException('locale_invalid');
            }
        }

        $created = $menu === null;
        $menu ??= new Menu(['handle' => $this->uniqueHandle($name)]);
        $menu->forceFill(['name' => $name, 'locales' => $locales === [] ? null : $locales])->save();

        Audit::record('cms.menu.saved', [
            'auditable_type' => Menu::class,
            'auditable_id' => $menu->id,
            'meta' => ['handle' => $menu->handle, 'name' => $name, 'created' => $created],
        ]);

        return $menu;
    }

    private function uniqueHandle(string $name): string
    {
        $base = Slugger::make($name, max: 60) ?: 'menu';
        $taken = Menu::query()->where('handle', 'like', $base.'%')->pluck('handle')->all();

        $handle = $base;
        for ($suffix = 2; in_array($handle, $taken, true); $suffix++) {
            $handle = "{$base}-{$suffix}";
        }

        return $handle;
    }
}
