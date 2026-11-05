<?php

use App\Modules\Cms\Actions\Menus\DeleteMenuItem;
use App\Modules\Cms\Actions\Menus\DuplicateMenu;
use App\Modules\Cms\Actions\Menus\DuplicateMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Menus\SetMenuAutoAdd;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * "Nhân bản" of a whole menu and of one item with its branch, and "Tự thêm trang mới": a page
 * that goes public joins every menu that asked for it, once, and never comes back after an editor
 * removed it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editor = menuEditor(1);
});

function mcIn(Closure $callback): mixed
{
    return app(Tenancy::class)->enter(1, $callback);
}

function mcRow(Menu $menu, string $label, ?MenuItem $parent = null, array $meta = []): MenuItem
{
    $position = MenuItem::query()->where('menu_id', $menu->id)->where('parent_id', $parent?->id)->count();
    $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent?->id, 'position' => $position,
        'link_type' => 'url', 'url' => 'https://example.com/'.$position, 'meta' => $meta]);
    $item->translations()->create(['locale' => 'vi', 'label' => $label]);
    $item->translations()->create(['locale' => 'en', 'label' => $label.' EN']);

    return $item;
}

/** Labels of the live tree with their positions: ['0 A' => ['0 A1' => []], …]. */
function mcTree(Menu $menu, ?int $parent = null, string $locale = 'vi'): array
{
    $tree = [];
    foreach (MenuItem::query()->with('translations')->where('menu_id', $menu->id)->where('parent_id', $parent)->orderBy('position')->orderBy('id')->get() as $item) {
        $tree[$item->position.' '.$item->translationFor($locale)?->label] = mcTree($menu, $item->id, $locale);
    }

    return $tree;
}

function mcPage(string $type = 'page', string $status = 'published'): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => $status, 'published_at' => $status === 'published' ? now() : null]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'ồ Trang ộ '.$entry->id, 'slug' => 'trang-'.$entry->id, 'status' => 'published']);

    return $entry;
}

/** @return list<int> the pages each menu item links to, in order */
function mcLinked(Menu $menu): array
{
    return MenuItem::query()->where('menu_id', $menu->id)->where('link_type', 'entry')->orderBy('position')->pluck('routable_id')->map(fn ($id): int => (int) $id)->all();
}

it('copies a whole menu with its items, labels and options, shown nowhere and not taking new pages', function (): void {
    mcIn(function (): void {
        $menu = app(SaveMenu::class)->handle(null, 'ồ Menu chính ộ');
        $menu->forceFill(['meta' => ['auto_add_pages' => true]])->save();
        $a = mcRow($menu, 'Sản phẩm', meta: ['icon' => 'phone', 'badge' => 'new']);
        mcRow($menu, 'Khuôn', $a);
        mcRow($menu, 'Liên hệ');
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

        app()->setLocale('vi');   // the copy's name is in the editor's language
        $copy = app(DuplicateMenu::class)->handle($this->editor, $menu);

        expect($copy->name)->toBe('ồ Menu chính ộ (bản sao)')
            ->and($copy->ulid)->not->toBe($menu->ulid)
            ->and(mcTree($copy))->toBe(mcTree($menu))
            ->and(mcTree($copy, locale: 'en'))->toBe(mcTree($menu, locale: 'en'))
            ->and(MenuItem::query()->where('menu_id', $copy->id)->whereNull('parent_id')->orderBy('position')->first()?->meta)->toBe(['icon' => 'phone', 'badge' => 'new'])
            ->and($copy->fresh()?->meta['auto_add_pages'] ?? false)->toBeFalse()
            ->and(MenuAssignment::query()->where('menu_id', $copy->id)->exists())->toBeFalse()
            ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(3);
    });
});

it('copies one item with its branch right after it, renumbering the rows that follow', function (): void {
    mcIn(function (): void {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        mcRow($menu, 'Trang chủ');
        $services = mcRow($menu, 'ồ Dịch vụ ộ');
        $child = mcRow($menu, 'Gia công', $services);
        mcRow($menu, 'Phay', $child);
        mcRow($menu, 'Liên hệ');

        $copy = app(DuplicateMenuItem::class)->handle($this->editor, $menu, $services);

        expect(mcTree($menu))->toBe([
            '0 Trang chủ' => [],
            '1 ồ Dịch vụ ộ' => ['0 Gia công' => ['0 Phay' => []]],
            '2 ồ Dịch vụ ộ (bản sao)' => ['0 Gia công' => ['0 Phay' => []]],
            '3 Liên hệ' => [],
        ])
            ->and($copy->ulid)->not->toBe($services->ulid)
            ->and($copy->translationFor('en')?->label)->toBe('ồ Dịch vụ ộ EN (copy)');
    });
});

it('refuses to copy for someone who may not manage menus', function (): void {
    $stranger = User::factory()->create();

    mcIn(function () use ($stranger): void {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $item = mcRow($menu, 'Trang chủ');

        expect(fn () => app(DuplicateMenu::class)->handle($stranger, $menu))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DuplicateMenuItem::class)->handle($stranger, $menu, $item))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetMenuAutoAdd::class)->handle($stranger, $menu, true))->toThrow(AuthorizationException::class)
            ->and(Menu::query()->count())->toBe(1);
    });
});

it('adds a page that goes public to every menu that asked for it, once', function (): void {
    mcIn(function (): void {
        $on = app(SaveMenu::class)->handle(null, 'Menu chính');
        $off = app(SaveMenu::class)->handle(null, 'Chân trang');
        mcRow($on, 'Trang chủ');
        app(SetMenuAutoAdd::class)->handle($this->editor, $on, true);

        $published = mcPage();
        $draft = mcPage(status: 'draft');
        $post = mcPage('post');

        expect(mcLinked($on))->toBe([$published->id])
            ->and(mcLinked($off))->toBe([])
            ->and(MenuItem::query()->where('menu_id', $on->id)->where('routable_id', $published->id)->value('position'))->toBe(1);

        // Taken offline and published again: still one item.
        $published->update(['status' => 'draft']);
        $published->update(['status' => 'published']);
        // The draft going public joins too; the post never does.
        $draft->update(['status' => 'published']);
        $post->update(['status' => 'draft']);
        $post->update(['status' => 'published']);

        expect(mcLinked($on))->toBe([$published->id, $draft->id]);
    });
});

it('never brings back a page an editor removed from the menu', function (): void {
    mcIn(function (): void {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        app(SetMenuAutoAdd::class)->handle($this->editor, $menu, true);
        $page = mcPage();
        $item = MenuItem::query()->where('menu_id', $menu->id)->where('routable_id', $page->id)->firstOrFail();

        app(DeleteMenuItem::class)->handle($this->editor, $item);
        $page->update(['status' => 'draft']);
        $page->update(['status' => 'published']);

        expect(mcLinked($menu))->toBe([]);

        // Turned off, a new page is not added.
        app(SetMenuAutoAdd::class)->handle($this->editor, $menu, false);
        mcPage();
        expect(mcLinked($menu))->toBe([])
            ->and($menu->fresh()?->meta ?? [])->not->toHaveKey('auto_add_pages');
    });
});
