<?php

use App\Modules\Admin\Livewire\Menus\Index as MenusIndex;
use App\Modules\Admin\Support\Menus\MenuScreen;
use App\Modules\Admin\Support\Menus\MenuSelfCheck;
use App\Modules\Admin\Support\Menus\MenuSourceSearch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The Trình đơn screen's endpoints: every one checks the editor may manage menus, validates its
 * arguments, names menus and items by ulid only (another site's are never found), and answers
 * {ok: true, …} or {ok: false, reason, message}. Loading the screen and the self-check cost the
 * same number of queries for a menu of 10 items as for one of 200.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editor = menuEditor(1);
});

function msIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** Calls one endpoint of the screen as $user in site 1 and returns what it answered. */
function msCall(User $user, string $method, mixed ...$arguments): array
{
    $out = null;
    msIn(1, function () use ($user, $method, $arguments, &$out): void {
        Livewire::actingAs($user->fresh())->test(MenusIndex::class)
            ->call($method, ...$arguments)
            ->assertReturned(function (mixed $returned) use (&$out): bool {
                $out = $returned;

                return true;
            });
    });

    return is_array($out) ? $out : [];
}

function msPage(string $title, string $status = 'published', string $type = 'page'): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => $status, 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'ms-'.$entry->id, 'status' => 'published']);

    return $entry;
}

function msCategory(string $name): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'ms-cat-'.$category->id]);

    return $category->fresh() ?? $category;
}

function msProduct(string $name, string $status = 'published'): Product
{
    $product = Product::query()->create(['status' => $status, 'published_at' => now()]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'ms-sp-'.$product->id]);

    return $product;
}

function msLink(Menu $menu, string $type, int $id, ?MenuItem $parent = null, string $label = ''): MenuItem
{
    $position = MenuItem::query()->where('menu_id', $menu->id)->where('parent_id', $parent?->id)->count();
    $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent?->id, 'position' => $position,
        'link_type' => $type, 'routable_type' => $type, 'routable_id' => $id]);
    if ($label !== '') {
        $item->translations()->create(['locale' => 'vi', 'label' => $label]);
    }

    return $item;
}

/** A menu shown in the main area with $count items: a mix of pages, categories and products, some nested. */
function msBigMenu(int $count): Menu
{
    $menu = app(SaveMenu::class)->handle(null, 'Menu '.$count);
    MenuAssignment::query()->updateOrCreate(['region_key' => 'primary', 'locale' => 'vi'], ['menu_id' => $menu->id, 'same_as_region' => null]);
    $parent = null;
    for ($i = 0; $i < $count; $i++) {
        $item = match ($i % 4) {
            0 => msLink($menu, 'entry', msPage('Trang '.$i)->id, null, 'Mục '.$i),
            1 => msLink($menu, 'taxonomy', msCategory('Chuyên mục '.$i)->id, $parent),
            2 => msLink($menu, 'product', msProduct('Sản phẩm '.$i, $i % 8 === 2 ? 'draft' : 'published')->id, $parent),
            default => msLink($menu, 'entry', msPage('Bài '.$i, 'published', 'post')->id, $parent, 'Bài '.$i),
        };
        $parent = $i % 4 === 0 ? $item : $parent;
    }
    MenuItem::query()->where('menu_id', $menu->id)->orderByDesc('id')->limit(max(1, intdiv($count, 20)))->get()->each->delete();

    return $menu;
}

/** Every key used anywhere in $value. */
function msKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $child) {
        $keys[] = (string) $key;
        array_push($keys, ...msKeys($child));
    }

    return array_values(array_unique($keys));
}

it('loads the same number of queries for a menu of 10 items as for one of 200', function (): void {
    [$small, $large] = msIn(1, fn (): array => [msBigMenu(10), msBigMenu(200)]);

    $counts = msIn(1, function () use ($small, $large): array {
        $screen = app(MenuScreen::class);
        $check = app(MenuSelfCheck::class);
        $screen->load($small);   // warm the per-request caches (site locales, settings)

        return [
            'load' => [countQueries(fn () => $screen->load($small)), countQueries(fn () => $screen->load($large))],
            'check' => [countQueries(fn () => $check->check($small)), countQueries(fn () => $check->check($large))],
        ];
    });

    expect($counts['load'][0])->toBe($counts['load'][1])
        ->and($counts['check'][0])->toBe($counts['check'][1])
        ->and($counts['load'][1])->toBeLessThanOrEqual(25)
        ->and($counts['check'][1])->toBeLessThanOrEqual(15);

    $loaded = msCall($this->editor, 'load', $large->ulid);
    expect($loaded['ok'])->toBeTrue()
        ->and($loaded['menu']['items'])->toHaveCount(190)
        ->and($loaded['menu']['trash'])->not->toBe([])
        ->and(msKeys($loaded))->not->toContain('id')->not->toContain('menu_id')->not->toContain('parent_id')->not->toContain('routable_id');
});

it('tells what the website hides by itself, naming sources by slug and ulid, never an id', function (): void {
    [$menu, $draft, $product] = msIn(1, function (): array {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $draft = msPage('ồ Bản nháp ộ', 'draft');
        $product = msProduct('Máy phay');
        $deep = msLink($menu, 'entry', $draft->id, null, 'ồ Nháp ộ');
        $mid = msLink($menu, 'product', $product->id, $deep);
        $low = msLink($menu, 'entry', msPage('Tầng 3')->id, $mid);
        msLink($menu, 'entry', msPage('Tầng 4')->id, $low);
        msLink($menu, 'entry', msPage('Đã xoá')->id)->update(['routable_id' => 999999]);
        $product->delete();

        return [$menu, $draft, $product];
    });

    $answer = msCall($this->editor, 'selfCheck', $menu->ulid);
    $issues = collect($answer['issues'])->keyBy('code');

    expect($answer['ok'])->toBeTrue()
        ->and($issues->keys()->all())->toContain('source_unpublished', 'source_trashed', 'source_gone', 'too_deep', 'menu_unplaced', 'area_empty')
        ->and($issues['source_unpublished']['source'])->toBe(['kind' => 'page', 'slug' => 'ms-'.$draft->id, 'ref' => $draft->ulid])
        ->and($issues['source_unpublished']['label'])->toBe('ồ Nháp ộ')
        ->and($issues['source_unpublished']['message'])->toContain('ồ Nháp ộ')
        ->and($issues['source_trashed']['source']['ref'])->toBe($product->ulid)
        ->and($issues['too_deep'])->toMatchArray(['count' => 1, 'depth' => 3, 'area' => null])
        ->and($issues['menu_unplaced']['menu'])->toBe($menu->ulid)
        ->and(msKeys($answer))->not->toContain('id')->not->toContain('routable_id');
});

it('finds pages, categories and products ignoring accents, without paging, and says what is already in the menu', function (): void {
    [$menu, $page] = msIn(1, function (): array {
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $page = msPage('Dịch vụ gia công');
        msPage('ồ Dịch vụ bảo trì ộ');
        msCategory('Dịch Vụ khác');
        msProduct('Máy phay');
        msLink($menu, 'entry', $page->id);
        for ($i = 0; $i < 25; $i++) {
            msPage('Dịch vụ '.$i);
        }

        return [$menu, $page];
    });
    msIn(2, fn () => msPage('Dịch vụ của site B'));

    $all = msCall($this->editor, 'search', 'dich vu', null, $menu->ulid)['results'];
    $titles = array_column($all, 'title');

    expect(array_count_values(array_column($all, 'kind')))->toBe(['page' => 20, 'category' => 1])
        ->and($titles)->toContain('Dịch Vụ khác')
        ->and($titles)->not->toContain('Dịch vụ của site B')
        ->and(msKeys($all))->not->toContain('id');

    $pages = msCall($this->editor, 'search', 'ồ DỊCH VỤ BẢO', 'page', $menu->ulid)['results'];
    expect($pages)->toHaveCount(1)
        ->and($pages[0])->toMatchArray(['title' => 'ồ Dịch vụ bảo trì ộ', 'kind' => 'page', 'published' => true, 'inMenu' => false]);

    $linked = msCall($this->editor, 'search', 'gia cong', 'page', $menu->ulid)['results'];
    expect($linked)->toHaveCount(1)
        ->and($linked[0])->toMatchArray(['key' => 'entry:'.$page->ulid, 'link' => ['type' => 'entry', 'ref' => $page->ulid], 'inMenu' => true]);

    $sql = msIn(1, fn () => DB::pretend(fn () => app(MenuSourceSearch::class)->search('x', 'page', null, 'vi')));
    expect(strtolower($sql[0]['query']))->toContain('limit 20')->not->toContain('offset');
});

it('saves a draft, copies, renames, assigns and restores through the screen', function (): void {
    $menu = msIn(1, fn () => app(SaveMenu::class)->handle(null, 'Menu chính'));

    $created = msCall($this->editor, 'createMenu', 'ồ Menu phụ ộ');
    expect($created['ok'])->toBeTrue()->and($created['menu'])->toBeString();

    $loaded = msCall($this->editor, 'load', $menu->ulid);
    $saved = msCall($this->editor, 'saveDraft', $menu->ulid, [
        'version' => $loaded['menu']['version'],
        'tree' => [['key' => 'new:1', 'parent' => null], ['key' => 'new:2', 'parent' => 'new:1']],
        'creates' => [
            ['key' => 'new:1', 'link' => ['type' => 'special', 'key' => 'home'], 'labels' => ['vi' => 'ồ Trang chủ ộ']],
            ['key' => 'new:2', 'link' => ['type' => 'url', 'url' => 'https://example.com'], 'labels' => ['vi' => 'Ngoài']],
        ],
    ]);
    expect($saved['ok'])->toBeTrue()->and(array_keys($saved['keys']))->toBe(['new:1', 'new:2']);

    $stale = msCall($this->editor, 'saveDraft', $menu->ulid, ['version' => $loaded['menu']['version'], 'tree' => []]);
    expect($stale)->toMatchArray(['ok' => false, 'reason' => 'draft_stale'])->and($stale['message'])->toBeString()->not->toBe('menus.reason_draft_stale');

    $copy = msCall($this->editor, 'copyItem', $menu->ulid, $saved['keys']['new:1'], $saved['version']);
    expect($copy['ok'])->toBeTrue();

    expect(msCall($this->editor, 'renameMenu', $menu->ulid, 'ồ Menu đầu trang ộ'))->toMatchArray(['ok' => true])
        ->and(msCall($this->editor, 'assign', 'primary', 'vi', $menu->ulid))->toMatchArray(['ok' => true])
        ->and(msCall($this->editor, 'follow', 'handheld', 'vi'))->toMatchArray(['ok' => true])
        ->and(msCall($this->editor, 'follow', 'footer', 'vi'))->toMatchArray(['ok' => false, 'reason' => 'same_invalid'])
        ->and(msCall($this->editor, 'setAutoAdd', $menu->ulid, true))->toMatchArray(['ok' => true])
        ->and(msCall($this->editor, 'deleteMenu', $menu->ulid))->toMatchArray(['ok' => false, 'reason' => 'menu_in_use']);

    $state = msCall($this->editor, 'load', $menu->ulid)['menu'];
    $removed = msCall($this->editor, 'saveDraft', $menu->ulid, [
        'version' => $state['version'],
        'tree' => array_values(array_map(fn (array $item): array => ['key' => $item['key'], 'parent' => $item['parent']],
            array_filter($state['items'], fn (array $item): bool => $item['key'] !== $copy['item'] && $item['parent'] !== $copy['item']))),
        'removes' => array_values(array_column(array_filter($state['items'], fn (array $item): bool => $item['key'] === $copy['item'] || $item['parent'] === $copy['item']), 'key')),
    ]);
    expect($removed['removed'])->toBe(2);

    $trash = msCall($this->editor, 'load', $menu->ulid)['menu']['trash'];
    expect($trash)->toHaveCount(1)->and($trash[0]['count'])->toBe(2)
        ->and(msCall($this->editor, 'restore', $menu->ulid, $trash[0]['key']))->toMatchArray(['ok' => true, 'restored' => 2]);

    msIn(1, fn () => expect(Menu::query()->where('ulid', $menu->ulid)->value('name'))->toBe('ồ Menu đầu trang ộ')
        ->and(MenuAssignment::query()->where('region_key', 'handheld')->value('same_as_region'))->toBe('primary'));
});

it('refuses bad arguments and never finds another site\'s menu', function (): void {
    $foreign = msIn(2, fn () => app(SaveMenu::class)->handle(null, 'Menu B'));
    msIn(1, fn () => app(SaveMenu::class)->handle(null, 'Menu chính'));

    foreach ([['load', 'not-a-ulid'], ['assign', 'sidebar', 'vi', $foreign->ulid], ['search', ['x']], ['search', 'x', 'tag'],
        ['setAutoAdd', $foreign->ulid, 'yes'], ['createMenu', ['name']]] as $call) {
        expect(msCall($this->editor, ...$call))->toMatchArray(['ok' => false, 'reason' => 'input_invalid']);
    }
    foreach ([['load', $foreign->ulid], ['copyMenu', $foreign->ulid], ['deleteMenu', $foreign->ulid], ['assign', 'primary', 'vi', $foreign->ulid]] as $call) {
        expect(msCall($this->editor, ...$call))->toMatchArray(['ok' => false, 'reason' => 'item_invalid']);
    }

    msIn(2, fn () => expect(Menu::query()->where('ulid', $foreign->ulid)->exists())->toBeTrue());
});

it('is closed to someone who may not manage menus, on the page and on every call', function (): void {
    $member = User::factory()->create();
    msIn(1, fn () => grantRole($member, 'plain-member', ['entries.view']));

    $this->actingAs($this->editor->fresh())->get('http://a.test/admin/menus')->assertOk()->assertSee(__('menus.title'));
    $this->actingAs($member->fresh())->get('http://a.test/admin/menus')->assertForbidden();

    msIn(1, function () use ($member): void {
        Livewire::actingAs($member->fresh())->test(MenusIndex::class)->assertForbidden();
    });
});
