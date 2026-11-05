<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Menus;

use App\Modules\Admin\Support\Menus\MenuScreen;
use App\Modules\Admin\Support\Menus\MenuSelfCheck;
use App\Modules\Admin\Support\Menus\MenuSourceSearch;
use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Actions\Menus\AssignMenu;
use App\Modules\Cms\Actions\Menus\DeleteMenu;
use App\Modules\Cms\Actions\Menus\DuplicateMenu;
use App\Modules\Cms\Actions\Menus\DuplicateMenuItem;
use App\Modules\Cms\Actions\Menus\FollowMenuArea;
use App\Modules\Cms\Actions\Menus\RestoreMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Menus\SetMenuAutoAdd;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuLimits;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Theming\MenuAreas;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Trình đơn screen (design 11). The screen is an editor that keeps its unsaved changes in the
 * browser and calls these methods from script ($wire.load(), $wire.saveDraft(…)…); each returns a
 * small array and holds no state on the server, so there is no public property at all (no model,
 * no id ever reaches the page). Every method is an endpoint: boot() re-checks menus.manage on each
 * request, the method authorizes its own ability through MenuPolicy, and validates every argument
 * (menus and items are named by ulid). A refused change comes back as
 * {ok: false, reason, message} — the reason code of MenuChangeRejectedException (or input_invalid)
 * and the plain-language sentence for it; success is {ok: true, …}.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-check on every request.
        abort_unless(Gate::allows('menus.manage'), 403);
    }

    /**
     * Everything the screen draws (MenuScreen::load), with the menu $menu open, or none.
     *
     * @return array<string, mixed>
     */
    public function load(mixed $menu = null): array
    {
        Gate::authorize('viewAny', Menu::class);
        if ($menu !== null && ! $this->isUlid($menu)) {
            return $this->invalid();
        }
        $found = $menu === null ? null : $this->menu($menu);
        if ($menu !== null && $found === null) {
            return $this->refused('item_invalid');
        }

        return ['ok' => true, ...app(MenuScreen::class)->load($found)];
    }

    /**
     * "Lưu": the whole draft of one menu (format: ApplyMenuDraft). Returns the new version and the
     * ulid each new row ("new:…" key) got.
     *
     * @return array<string, mixed>
     */
    public function saveDraft(mixed $menu, mixed $draft): array
    {
        if (! $this->isUlid($menu) || ! is_array($draft)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'update', function (Menu $found, User $actor) use ($draft): array {
            /** @var array<string, mixed> $draft */
            $saved = app(ApplyMenuDraft::class)->handle($actor, $found, $draft);

            return ['ok' => true, ...$saved];
        });
    }

    /**
     * Show $menu in $area for $locale.
     *
     * @return array<string, mixed>
     */
    public function assign(mixed $area, mixed $locale, mixed $menu): array
    {
        if (! $this->isArea($area) || ! is_string($locale) || ! $this->isUlid($menu)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'update', function (Menu $found, User $actor) use ($area, $locale): array {
            app(AssignMenu::class)->handle($actor, $area, $locale, $found);

            return ['ok' => true];
        });
    }

    /**
     * "Dùng trình đơn chung": $area shows, for $locale, what the area it may follow shows.
     *
     * @return array<string, mixed>
     */
    public function follow(mixed $area, mixed $locale): array
    {
        if (! $this->isArea($area) || ! is_string($locale)) {
            return $this->invalid();
        }
        Gate::authorize('manage', Menu::class);

        return $this->attempt(function () use ($area, $locale): array {
            $followed = MenuAreas::canSame($area);
            if ($followed === null) {
                throw new MenuChangeRejectedException('same_invalid');
            }
            app(FollowMenuArea::class)->handle($this->actor(), $area, $locale, $followed);

            return ['ok' => true];
        });
    }

    /**
     * $area shows nothing of its own for $locale any more.
     *
     * @return array<string, mixed>
     */
    public function clearArea(mixed $area, mixed $locale): array
    {
        if (! $this->isArea($area) || ! is_string($locale)) {
            return $this->invalid();
        }
        Gate::authorize('manage', Menu::class);

        return $this->attempt(function () use ($area, $locale): array {
            app(AssignMenu::class)->handle($this->actor(), $area, $locale, null);

            return ['ok' => true];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function createMenu(mixed $name): array
    {
        if (! is_string($name)) {
            return $this->invalid();
        }
        Gate::authorize('create', Menu::class);

        return $this->attempt(fn (): array => ['ok' => true, 'menu' => app(SaveMenu::class)->handle(null, $name)->ulid]);
    }

    /**
     * @return array<string, mixed>
     */
    public function renameMenu(mixed $menu, mixed $name): array
    {
        if (! $this->isUlid($menu) || ! is_string($name)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'update', function (Menu $found) use ($name): array {
            $saved = app(SaveMenu::class)->handle($found, $name, $found->locales);

            return ['ok' => true, 'version' => $this->version($saved)];
        });
    }

    /**
     * "Nhân bản" a menu: an unassigned copy with its items and labels.
     *
     * @return array<string, mixed>
     */
    public function copyMenu(mixed $menu): array
    {
        if (! $this->isUlid($menu)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'create', fn (Menu $found, User $actor): array => [
            'ok' => true, 'menu' => app(DuplicateMenu::class)->handle($actor, $found)->ulid,
        ]);
    }

    /**
     * "Nhân bản" an item with what is under it, right after it. $version: the token the screen
     * holds (a menu changed since is refused, draft_stale).
     *
     * @return array<string, mixed>
     */
    public function copyItem(mixed $menu, mixed $item, mixed $version): array
    {
        if (! $this->isUlid($menu) || ! $this->isUlid($item) || ! is_string($version)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'update', function (Menu $found, User $actor) use ($item, $version): array {
            $original = MenuItem::query()->where('menu_id', $found->id)->where('ulid', $item)->first(['id', 'menu_id']);
            if ($original === null) {
                throw new MenuChangeRejectedException('item_invalid');
            }
            $copy = app(DuplicateMenuItem::class)->handle($actor, $found, $original, $version);

            return ['ok' => true, 'item' => $copy->ulid, 'version' => $this->version($found)];
        });
    }

    /**
     * Delete a menu. One the website shows somewhere needs $evenIfAssigned (menu_in_use).
     *
     * @return array<string, mixed>
     */
    public function deleteMenu(mixed $menu, mixed $evenIfAssigned = false): array
    {
        if (! $this->isUlid($menu) || ! is_bool($evenIfAssigned)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'delete', function (Menu $found) use ($evenIfAssigned): array {
            app(DeleteMenu::class)->handle($found, $evenIfAssigned);

            return ['ok' => true];
        });
    }

    /**
     * "Khôi phục" an item from the menu's trash, with what was removed with it.
     *
     * @return array<string, mixed>
     */
    public function restore(mixed $menu, mixed $item): array
    {
        if (! $this->isUlid($menu) || ! $this->isUlid($item)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'restore', function (Menu $found, User $actor) use ($item): array {
            $trashed = MenuItem::onlyTrashed()->where('menu_id', $found->id)->where('ulid', $item)->first(['id', 'menu_id']);
            if ($trashed === null) {
                throw new MenuChangeRejectedException('item_invalid');
            }
            $restored = app(RestoreMenuItem::class)->handle($actor, $trashed);

            return ['ok' => true, 'restored' => $restored, 'version' => $this->version($found)];
        });
    }

    /**
     * "Tự thêm trang mới" on or off for a menu.
     *
     * @return array<string, mixed>
     */
    public function setAutoAdd(mixed $menu, mixed $on): array
    {
        if (! $this->isUlid($menu) || ! is_bool($on)) {
            return $this->invalid();
        }

        return $this->onMenu($menu, 'update', function (Menu $found, User $actor) use ($on): array {
            app(SetMenuAutoAdd::class)->handle($actor, $found, $on);

            return ['ok' => true, 'version' => $this->version($found)];
        });
    }

    /**
     * The add panel's search (MenuSourceSearch). $kind: page|post|category|product or null for all;
     * $menu: the menu being edited, for "already in this menu".
     *
     * @return array<string, mixed>
     */
    public function search(mixed $query, mixed $kind = null, mixed $menu = null): array
    {
        if (! is_string($query) || ($kind !== null && ! in_array($kind, MenuSourceSearch::KINDS, true)) || ($menu !== null && ! $this->isUlid($menu))) {
            return $this->invalid();
        }
        Gate::authorize('viewAny', Menu::class);
        $found = $menu === null ? null : $this->menu($menu);
        if ($menu !== null && $found === null) {
            return $this->refused('item_invalid');
        }

        /** @var string|null $kind */
        return ['ok' => true, 'results' => app(MenuSourceSearch::class)->search($query, $kind, $found?->id, app(SiteLocale::class)->default())];
    }

    /**
     * "Hệ thống tự kiểm" (MenuSelfCheck) for the menu $menu, or for the site's areas only.
     *
     * @return array<string, mixed>
     */
    public function selfCheck(mixed $menu = null): array
    {
        if ($menu !== null && ! $this->isUlid($menu)) {
            return $this->invalid();
        }
        Gate::authorize('viewAny', Menu::class);
        $found = $menu === null ? null : $this->menu($menu);
        if ($menu !== null && $found === null) {
            return $this->refused('item_invalid');
        }

        return ['ok' => true, 'issues' => app(MenuSelfCheck::class)->check($found)];
    }

    public function render(): View
    {
        return view('admin::livewire.menus.index');
    }

    /**
     * Find the menu by ulid (through the site's scope: another site's menu is not found), check
     * $ability on it, run $run and turn a refusal into its reason.
     *
     * @param  Closure(Menu, User): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function onMenu(string $ulid, string $ability, Closure $run): array
    {
        $menu = $this->menu($ulid);
        if ($menu === null) {
            Gate::authorize('viewAny', Menu::class);

            return $this->refused('item_invalid');
        }
        Gate::authorize($ability, $menu);

        return $this->attempt(fn (): array => $run($menu, $this->actor()), $menu);
    }

    /**
     * @param  Closure(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function attempt(Closure $run, ?Menu $menu = null): array
    {
        try {
            return $run();
        } catch (MenuChangeRejectedException $refusal) {
            return $this->refused($refusal->reason, $menu);
        }
    }

    /**
     * @return array{ok: false, reason: string, message: string}
     */
    private function refused(string $reason, ?Menu $menu = null): array
    {
        $params = [];
        if ($reason === 'depth_exceeded') {
            $params['depth'] = $menu === null ? MenuAreas::FALLBACK_DEPTH : MenuLimits::forMenu($menu->id)['depth'];
        }

        return ['ok' => false, 'reason' => $reason, 'message' => __('menus.reason_'.$reason, $params)];
    }

    /**
     * @return array{ok: false, reason: string, message: string}
     */
    private function invalid(): array
    {
        return $this->refused('input_invalid');
    }

    private function menu(string $ulid): ?Menu
    {
        return Menu::query()->where('ulid', $ulid)->first(['id', 'ulid', 'site_id', 'handle', 'name', 'locales', 'meta']);
    }

    private function version(Menu $menu): string
    {
        $menu->refresh();
        [$items, $labels] = app(ApplyMenuDraft::class)->read($menu->id);

        return MenuVersion::of($menu, $items, $labels);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @phpstan-assert-if-true string $value */
    private function isUlid(mixed $value): bool
    {
        return is_string($value) && Str::isUlid($value);
    }

    /** @phpstan-assert-if-true string $value */
    private function isArea(mixed $value): bool
    {
        return is_string($value) && MenuAreas::has($value);
    }
}
