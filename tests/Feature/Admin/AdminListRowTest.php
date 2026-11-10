<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;

/*
 * The shared record-list row: one container that switches its layout on its OWN width (a card
 * below 600, one record per line to 900, a table with a header from 900), a 44 select box drawn
 * 18, and one actions group (row buttons + a ⋮ menu). Sizes live in the shared stylesheet, so a
 * screen passes content only; names are never clipped.
 */

function listCss(): string
{
    return (string) file_get_contents(resource_path('css/admin-list.css'));
}

it('wraps the rows in a named table inside a width container, with an optional select bar', function (): void {
    $html = (string) $this->blade(
        '<x-bs-admin::list label="Danh sách trang" data-hide="au"><x-slot:select>Chọn</x-slot:select><x-bs-admin::list.row><x-bs-admin::list.cell col="main">A</x-bs-admin::list.cell></x-bs-admin::list.row></x-bs-admin::list>'
    );

    expect($html)->toMatch('/<div[^>]*class="list-rows"[^>]*data-hide="au"|<div[^>]*data-hide="au"[^>]*class="list-rows"/')
        ->and($html)->toContain('<div class="list-selbar">')
        ->and($html)->toMatch('/<div role="table" aria-label="Danh sách trang" class="list-g">/')
        ->and($html)->toMatch('/<div role="row" class="list-r">/')
        ->and($html)->toMatch('/<div role="cell" class="list-c list-c-main">/');
});

it('leaves the select bar out when the screen gives none', function (): void {
    $html = (string) $this->blade('<x-bs-admin::list label="L"></x-bs-admin::list>');

    expect($html)->not->toContain('list-selbar');
});

it('marks a header row and its sortable column headers', function (): void {
    $html = (string) $this->blade(
        '<x-bs-admin::list.head><x-bs-admin::list.cell head col="main" sort="ascending"><x-bs-admin::list.sort active dir="asc">Tiêu đề</x-bs-admin::list.sort></x-bs-admin::list.cell><x-bs-admin::list.cell head col="act">Thao tác</x-bs-admin::list.cell></x-bs-admin::list.head>'
    );

    expect($html)->toContain('<div role="row" class="list-r list-hd">')
        ->and($html)->toContain('<div role="columnheader" aria-sort="ascending" class="list-c list-c-main">')
        ->and($html)->toContain('<div role="columnheader" class="list-c list-c-act">')
        ->and($html)->toMatch('/<button type="button" class="list-sb" data-a="1">\s*Tiêu đề/');
});

it('shows a selected row by data-sel only', function (): void {
    $on = (string) $this->blade('<x-bs-admin::list.row :selected="true" wire:key="r1"></x-bs-admin::list.row>');
    $off = (string) $this->blade('<x-bs-admin::list.row></x-bs-admin::list.row>');

    expect($on)->toContain('data-sel="1"')
        ->and($on)->toContain('wire:key="r1"')
        ->and($off)->not->toContain('data-sel');
});

it('draws the select box as a 44 button with an 18 box, in each of its three states', function (string|bool $state, string $aria, int $glyphs): void {
    $html = (string) $this->blade('<x-bs-admin::list.check :checked="$s" label="Chọn “Giới thiệu”" wire:click="pick" />', ['s' => $state]);

    expect($html)->toMatch('/^\s*<button type="button" role="checkbox" aria-checked="'.$aria.'"/')
        ->and($html)->toContain('aria-label="Chọn “Giới thiệu”"')
        ->and($html)->toContain('class="list-ck"')
        ->and($html)->toContain('<span class="list-box" aria-hidden="true">')
        ->and($html)->toContain('wire:click="pick"')
        ->and(substr_count($html, '<svg'))->toBe($glyphs);
})->with([
    'unchecked' => [false, 'false', 0],
    'checked' => [true, 'true', 1],
    'mixed' => ['mixed', 'mixed', 1],
]);

it('draws the mixed state with the subtract glyph, not the tick', function (): void {
    $mixed = (string) $this->blade('<x-bs-admin::list.check checked="mixed" label="Chọn tất cả" />');

    expect($mixed)->toContain('M5 11V13H19V11H5Z')
        ->and($mixed)->not->toContain('M9.9997 15.1709');
});

it('can show its name as visible text instead of an aria-label (the select-all bar)', function (): void {
    $html = (string) $this->blade('<x-bs-admin::list.check :checked="false" label="Chọn tất cả" visible />');

    expect($html)->not->toContain('aria-label=')
        ->and($html)->toContain('class="list-ck list-ck-text"')
        ->and($html)->toContain('<span class="list-ck-label">Chọn tất cả</span>');
});

it('groups row buttons with a ⋮ menu button named for the record', function (): void {
    $html = (string) $this->blade(
        '<x-bs-admin::list.actions name="Giới thiệu"><x-bs-admin::button size="sm" variant="soft">Sửa</x-bs-admin::button><x-slot:menu><x-bs-admin::list.menu-item icon="ri-file-copy">Nhân bản</x-bs-admin::list.menu-item><x-bs-admin::list.menu-sep /><x-bs-admin::list.menu-item icon="ri-delete-bin" danger>Chuyển vào thùng rác</x-bs-admin::list.menu-item></x-slot:menu></x-bs-admin::list.actions>'
    );

    expect($html)->toContain('<div class="list-acts">')
        ->and($html)->toContain('aria-label="'.__('admin.list.more_for', ['name' => 'Giới thiệu']).'"')
        ->and($html)->toContain('aria-haspopup="menu"')
        ->and($html)->toContain(':aria-expanded="open.toString()"')
        ->and($html)->toContain('role="menu"')
        ->and(substr_count($html, 'role="menuitem"'))->toBe(2)
        ->and($html)->toContain('role="separator"')
        ->and($html)->toContain('data-t="danger"')
        // the ⋮ glyph, and Esc closes the menu with focus back on its button
        ->and($html)->toContain('M12 3C10.9 3 10 3.9 10 5C10 6.1 10.9 7 12 7')
        ->and($html)->toContain('x-on:keydown.escape.prevent.stop="close(true)"')
        ->and($html)->toContain('$refs.more.focus()')
        // the ⋮ is a row button from the shared button sizes: 36 visible, 44 touch
        ->and(preg_match('/<button type="button"[^>]*x-ref="more"[^>]*>/', $html, $more))->toBe(1)
        ->and($more[0])->toMatch('/class="[^"]*\bhit-area\b[^"]*\bsize-control-row\b/');
});

it('leaves the ⋮ out when the record has no further actions', function (): void {
    $html = (string) $this->blade('<x-bs-admin::list.actions name="X"><x-bs-admin::button size="sm">Khôi phục</x-bs-admin::button></x-bs-admin::list.actions>');
    $empty = (string) $this->blade('<x-bs-admin::list.actions name="X"><x-bs-admin::button size="sm">Sửa</x-bs-admin::button><x-slot:menu> </x-slot:menu></x-bs-admin::list.actions>');

    expect($html)->not->toContain('aria-haspopup="menu"')
        ->and($empty)->not->toContain('aria-haspopup="menu"');
});

it('names the record as a link with a visible quick-edit button beside it', function (): void {
    $html = (string) $this->blade('<x-bs-admin::list.name href="/admin/x" quick-edit="Sửa nhanh tiêu đề “A”">Một tiêu đề rất dài</x-bs-admin::list.name>');
    $plain = (string) $this->blade('<x-bs-admin::list.name>Bài đã bỏ</x-bs-admin::list.name>');

    expect($html)->toMatch('/<a href="\/admin\/x" wire:navigate class="list-name">Một tiêu đề rất dài<\/a>/')
        ->and($html)->toContain('class="list-qe"')
        ->and($html)->toContain('aria-label="Sửa nhanh tiêu đề “A”"')
        ->and($html)->toContain('x-on:click="$dispatch(\'list-quick-edit\')"')
        ->and($plain)->toContain('<span class="list-name">Bài đã bỏ</span>')
        ->and($plain)->not->toContain('list-qe');
});

it('switches layout on the list width in rem, from shared tokens, never clipping a name', function (): void {
    $css = listCss();

    expect($css)->toContain('container: list / inline-size')
        ->and($css)->toContain('@container list (width < 37.5rem)')
        ->and($css)->toContain('@container list (37.5rem <= width < 56.25rem)')
        ->and($css)->toContain('@container list (width >= 56.25rem)')
        ->and($css)->toContain('@container list (width >= 80rem)')
        // one grid for the header and every row, so a header lines up with its column
        ->and($css)->toContain('grid-template-columns: subgrid')
        ->and($css)->toContain('var(--spacing-hit)')
        ->and($css)->toContain('var(--text-small)')
        ->and($css)->not->toMatch('/@container[^{]*\dpx/')
        ->and($css)->not->toMatch('/text-overflow|line-clamp|ellipsis/');

    foreach (glob(base_path('app/Modules/Admin/resources/views/components/list/*.blade.php')) ?: [] as $file) {
        expect((string) file_get_contents($file))->not->toMatch('/\btruncate\b|line-clamp|text-ellipsis/');
    }
});

it('keeps neighbouring language chips\' touch hit areas apart, each chip at least its hit size less the gap', function (): void {
    expect(listCss())->toMatch('/@media \\(any-pointer: coarse\\) \\{\\s*\\.list-lcs,\\s*\\.list-lcg \\{\\s*--list-lc-gap: [^;]+;\\s*column-gap: var\\(--list-lc-gap\\);\\s*\\}\\s*\\.list-lc \\{[^}]*min-width: calc\\(var\\(--list-qe-s\\) - var\\(--list-lc-gap\\)\\);/');
});

it('keeps meta text at 12px or more', function (): void {
    preg_match_all('/font-size:\s*([^;]+);/', listCss(), $m);

    foreach ($m[1] as $size) {
        expect($size)->toMatch('/^var\(--text-(caption|small|body)\)$|^calc\(0\.9375rem \+ var\(--user-text-boost\)\)$|^inherit$/');
    }
});

function listCssBand(string $from, string $to): string
{
    $css = listCss();
    $start = strpos($css, $from);
    $end = strpos($css, $to, (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($css, (int) $start, (int) $end - (int) $start);
}

it('keeps a phone card to the name, one detail line and one row of status and buttons', function (): void {
    $card = listCssBand('@container list (width < 37.5rem)', '@container list (37.5rem <= width < 56.25rem)');

    expect($card)
        // author · date · views
        ->toMatch("/\\.list-m\\[data-for='au'\\]\\s*\\{\\s*order: 1;/")
        ->toMatch("/\\.list-m\\[data-for='when'\\]\\s*\\{\\s*display: inline;\\s*order: 2;/")
        ->toMatch("/\\.list-m\\[data-for='views'\\]\\s*\\{\\s*order: 3;/")
        // the button group runs into the card's side padding, as in the design
        ->toContain('margin-inline: auto -0.75rem');
});

it('shows the quick-edit pencil in the table only, so below 900 no 44px box reaches over the detail line', function (): void {
    // as in the design: below 900 the name stands alone and the row's ⋮ menu carries the quick edit
    expect(listCssBand('@container list (width < 56.25rem)', '@container list (37.5rem <= width < 56.25rem)'))
        ->toMatch('/\\.list-qe\\s*\\{\\s*display: none;\\s*\\}/')
        ->and(listCssBand('@container list (width < 37.5rem)', '@container list (width < 56.25rem)'))->not->toContain('.list-qe');

    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/livewire/entries/index.blade.php'));
    expect($source)->toMatch('/@if \\(\\$quickEdit\\)\\s*<x-bs-admin::list\\.menu-item icon="ri-pencil" x-on:click="\\$dispatch\\(\'list-quick-edit\'\\)">/');
});

it('keeps the path button inside its meta piece (no negative margin past the line)', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/livewire/entries/index.blade.php'));
    // up to the first class attribute: the click handler before it holds "=>"
    preg_match('/<button type="button" x-show="! editing".*?\sclass="([^"]*)"/s', $source, $button);

    expect($button)->not->toBeEmpty()
        ->and(explode(' ', $button[1]))->toContain('list-path')->not->toContain('hit-area', 'min-h-tap-cell', '-mx-1', 'px-1');
});

it('draws the name link and the path button at the full target size, the table cutting them to the name-cell size', function (): void {
    $css = listCss();
    $table = listCssBand('@container list (width >= 56.25rem)', '@container list (width >= 80rem)');

    // 44 everywhere by default: the visible box itself is the target, so nothing reaches over a neighbour
    expect($css)->toMatch('/\\.list-rows\\s*\\{[^}]*--list-tap: var\\(--spacing-hit\\);/')
        ->toMatch('/\\.list-tl\\s*\\{[^}]*min-height: var\\(--list-tap\\);/')
        ->toMatch('/\\.list-name\\s*\\{[^}]*display: flex;\\s*align-items: center;\\s*min-height: var\\(--list-tap\\);/')
        ->not->toMatch('/\\.list-name\\s*\\{[^}]*padding-block/')
        ->toMatch('/\\.list-path\\s*\\{[^}]*min-height: var\\(--list-tap\\);/')
        // the table takes the name-cell size (24 with a mouse at the default text size, 44 otherwise)
        ->and($table)->toMatch('/\\.list-r\\s*\\{\\s*--list-tap: var\\(--list-qe-s\\);\\s*\\}/');
});

it('keeps the card check box inside its row, with no negative margin into the name', function (): void {
    $card = listCssBand('@container list (width < 37.5rem)', '@container list (37.5rem <= width < 56.25rem)');

    expect($card)->toMatch('/\\.list-c-chk\\s*\\{[^}]*margin: 0 0 0 calc\\(\\(1\\.125rem - var\\(--spacing-hit\\)\\) \\/ 2\\);/')
        ->not->toContain('-0.5rem');
});

it('gives the chips line below 900 the height of their hit area', function (): void {
    expect(listCssBand('@container list (width < 56.25rem)', '@container list (37.5rem <= width < 56.25rem)'))
        ->toMatch("/\\.list-m\\[data-for='langs'\\]\\s*\\{\\s*--list-lc-up: var\\(--list-qe-s\\);/")
        ->toMatch('/\\.list-lcg\\s*\\{\\s*min-height: var\\(--list-qe-s\\);/');
});

it('draws the rows-per-page buttons as the shared pager link, with the row hit area', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/resources/views/livewire/entries/index.blade.php'));
    preg_match('/<button type="button" wire:click="setPerPage\([^>]*@class\(\[\'([^\']*)\'/', $source, $button);

    expect($button)->not->toBeEmpty()
        ->and(explode(' ', $button[1]))->toContain('pager-link')
        ->and($source)->not->toContain('cyclePerPage')
        ->and($source)->not->toContain('text-xs text-body-muted');
});

it('drops the select-all bar from 900, where the header box selects the page', function (): void {
    expect(listCssBand('@container list (width >= 56.25rem)', '@container list (width >= 80rem)'))
        ->toMatch('/\\.list-selbar\\s*\\{\\s*display: none;/');
});

describe('the posts and pages list on the shared row', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    function listRowEntry(User $author, string $title, string $slug, bool $trashed = false): Entry
    {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        if ($trashed) {
            $entry->delete();
        }

        return $entry;
    }

    it('names views only once there are some, written in the member\'s locale', function (): void {
        $user = User::factory()->create();
        app()->setLocale('vi');

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
            $entry = listRowEntry($user, 'Bài được đọc', 'bai-duoc-doc');
            Entry::query()->whereKey($entry->getKey())->update(['view_count' => 1234]);

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->assertSeeHtml('data-for="views">1.234 lượt xem</span>');
        });
    });

    it('renders the rows as a table with a header, select boxes and a ⋮ per record', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.edit.any', 'entries.create', 'entries.delete.any']);
            $entry = listRowEntry($user, 'Giới thiệu công ty', 'gioi-thieu');

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->assertOk()
                ->assertSeeHtml('role="table"')
                ->assertSeeHtml('<div role="row" class="list-r list-hd">')
                ->assertSeeHtml('role="columnheader" aria-sort="none"')
                ->assertSeeHtml('aria-label="'.e(__('entries.select_named', ['title' => 'Giới thiệu công ty'])).'"')
                ->assertSeeHtml('aria-label="'.e(__('admin.list.more_for', ['name' => 'Giới thiệu công ty'])).'"')
                ->assertSeeHtml('aria-label="'.e(__('entries.quick_edit_named', ['title' => 'Giới thiệu công ty'])).'"')
                ->assertSeeHtml('href="'.route('admin.entries.edit', ['ulid' => $entry->ulid]).'"')
                ->assertSeeHtml(e(__('entries.action_duplicate')))
                ->assertSeeHtml('class="list-c list-c-act">'.e(__('admin.actions')).'</div>')
                ->assertDontSeeHtml('data-for="views"')
                ->assertDontSeeHtml(e(__('entries.views_count', ['count' => 0])));

            $source = (string) file_get_contents(base_path('app/Modules/Admin/resources/views/livewire/entries/index.blade.php'));
            expect($source)->not->toMatch('/\\btruncate\\b|line-clamp/')
                // a phone card shows the path and languages only once the member turns them on in
                // "Hiện trên thẻ" (kept in their own preferences, see EntriesListHeaderTest)
                ->and($source)->toContain('x-show="vPath"')
                ->and($source)->toContain('show="vLangs"');
        });
    });

    it('ticks a row and the select-all box from the selection, mixed when only some are picked', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $a = listRowEntry($user, 'Trang A', 'trang-a');
            listRowEntry($user, 'Trang B', 'trang-b');

            $page = Livewire::actingAs($user->fresh())->test(Index::class);
            $page->assertSeeHtml('role="checkbox" aria-checked="false"')
                ->assertDontSeeHtml('role="checkbox" aria-checked="true"');

            $page->call('toggleSelectAll', [$a->ulid])
                ->assertSet('selected', [$a->ulid])
                ->assertSeeHtml('role="checkbox" aria-checked="true"')
                ->assertSeeHtml('role="checkbox" aria-checked="mixed"')
                ->assertSeeHtml('data-sel="1"');
        });
    });

    it('sorts from the header and says so on the column', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            listRowEntry($user, 'Trang A', 'trang-a');

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->call('sortBy', 'name')
                ->assertSeeHtml('aria-sort="ascending"')
                ->call('sortBy', 'name')
                ->assertSeeHtml('aria-sort="descending"');
        });
    });

    it('offers Restore and a permanent delete on a Trash row, and no editor link', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.edit.any', 'entries.delete.any']);
            $gone = listRowEntry($user, 'Bài đã bỏ', 'bai-da-bo', trashed: true);

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->call('setTab', 'trash')
                ->assertSeeHtml('wire:click="restore(\''.$gone->ulid.'\')"')
                ->assertSeeHtml('$wire.forceDelete(')
                ->assertSeeHtml('<span class="list-name">Bài đã bỏ</span>')
                ->assertDontSeeHtml('href="'.route('admin.entries.edit', ['ulid' => $gone->ulid]).'"')
                ->assertDontSeeHtml(e(__('entries.quick_edit_named', ['title' => 'Bài đã bỏ'])));
        });
    });

    it('keeps the row actions to what the member may do', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'viewer', ['entries.view']);
            listRowEntry($user, 'Chỉ xem', 'chi-xem');

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->assertSee('Chỉ xem')
                ->assertDontSeeHtml('class="list-qe"')
                ->assertDontSeeHtml(e(__('admin.list.more_for', ['name' => 'Chỉ xem'])))
                ->assertDontSeeHtml('$wire.trash(');
        });
    });

    it('gives a member who can publish the status menu and the ⋮, and the menu still changes the status', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish', 'entries.delete.any']);
            $entry = listRowEntry($user, 'Bài chờ đăng', 'bai-cho-dang');

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->assertSeeHtml('x-ref="pill"')
                ->assertSeeHtml('aria-label="'.e(__('admin.list.more_for', ['name' => 'Bài chờ đăng'])).'"')
                ->call('changeStatus', $entry->ulid, 'published')
                ->assertDispatched('bs-toast');

            expect($entry->fresh()->status)->toBe('published');
        });
    });
    describe('language versions on a row', function (): void {
        /** @param  list<string>  $codes  the site's locales, the first one the default */
        function listRowLocales(array $codes): void
        {
            foreach ($codes as $i => $code) {
                SiteLocale::query()->firstOrCreate(['locale' => $code], ['is_default' => $i === 0, 'enabled' => true]);
            }
        }

        function listRowTranslate(Entry $entry, string ...$codes): void
        {
            foreach ($codes as $code) {
                $entry->translations()->create(['locale' => $code, 'title' => 'Bản '.$code, 'slug' => 'ban-'.$code.'-'.$entry->id, 'status' => 'draft', 'body' => ['blocks' => []]]);
            }
        }

        it('gives an editor a link to each locale\'s editor, the source starred and a missing one to add', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
                listRowLocales(['vi', 'en', 'ja']);
                $entry = listRowEntry($user, 'Giới thiệu', 'gioi-thieu');
                listRowTranslate($entry, 'en');
                $edit = fn (string $lc): string => e(route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]));

                Livewire::actingAs($user->fresh())->test(Index::class)
                    ->assertSeeHtml('<a href="'.$edit('vi').'" wire:navigate class="list-lc" data-s="src"')
                    ->assertSeeHtml('<a href="'.$edit('en').'" wire:navigate class="list-lc" data-s="full"')
                    ->assertSeeHtml('title="Sửa bản tiếng Việt (bản nguồn)" aria-label="Sửa bản tiếng Việt (bản nguồn) — “Giới thiệu”"')
                    ->assertSeeHtml('title="Sửa bản tiếng Anh" aria-label="Sửa bản tiếng Anh — “Giới thiệu”"')
                    ->assertSeeHtml('title="Chưa có bản tiếng Nhật — bấm để thêm" aria-label="Thêm bản tiếng Nhật — “Giới thiệu”"')
                    ->assertSeeHtml('list-lc-star"')
                    ->assertDontSeeHtml('class="list-menu list-pop"');

                $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();
                // each chip reads as its short language name, not a code
                expect($html)->toMatch('/<a href="'.preg_quote($edit('ja'), '/').'" wire:navigate class="list-lc" data-s="empty"\s+data-add\s+title="[^"]*" aria-label="[^"]*">Nhật(<!--.*?-->)*<\/a>/')
                    ->and($html)->toMatch('/data-s="src"\s+title="[^"]*" aria-label="[^"]*">Việt(<!--.*?-->)*<svg/')
                    ->and($html)->toMatch('/aria-label="Sửa bản tiếng Anh — “Giới thiệu”">Anh(<!--.*?-->)*<\/a>/');
            });

            $source = (string) file_get_contents(base_path('app/Modules/Admin/resources/views/livewire/entries/index.blade.php'));
            expect($source)->not->toContain('group-hover'); // no hover popover: the chip itself is the control
        });

        it('gives a member who may only view a button per locale that says why', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'viewer', ['entries.view']);
                listRowLocales(['vi', 'en']);
                $entry = listRowEntry($user, 'Chỉ xem', 'chi-xem');

                Livewire::actingAs($user->fresh())->test(Index::class)
                    ->assertSeeHtml('<button type="button" class="list-lc" data-s="src"')
                    ->assertSeeHtml('aria-label="Tiếng Việt (bản nguồn), đã có nội dung. Bạn chỉ xem được trang này" aria-expanded="false"')
                    ->assertSeeHtml('aria-label="Chưa có bản tiếng Anh. Bạn chỉ xem được trang này" aria-expanded="false"')
                    ->assertSeeHtml('class="list-menu list-pop" role="group" aria-label="Tiếng Anh"')
                    ->assertSeeHtml(e('Chưa có bản dịch cho ngôn ngữ này.'))
                    // Tab out of the chips closes the note, as it does the summary menu
                    ->assertSeeHtml('x-on:click.outside="pop = null" x-on:keydown.tab="pop = null"')
                    ->assertDontSeeHtml('locale=en')
                    ->assertDontSeeHtml('data-add');
            });
        });

        it('tells an editor on a Trash row to restore first instead of opening the editor', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any', 'entries.delete.any']);
                listRowLocales(['vi', 'en']);
                $gone = listRowEntry($user, 'Bài đã bỏ', 'bai-da-bo', trashed: true);
                listRowTranslate($gone, 'en');

                Livewire::actingAs($user->fresh())->test(Index::class)
                    ->call('setTab', 'trash')
                    ->assertSeeHtml('aria-label="Tiếng Anh, đã có nội dung. Khôi phục trước rồi mới sửa được"')
                    ->assertSeeHtml('<button type="button" class="list-lc" data-s="full"')
                    ->assertDontSeeHtml('locale=en');
            });
        });

        it('notes the missing translations whatever the column choice, and hides a complete row\'s chips with it', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
                listRowLocales(['vi', 'en', 'ja', 'zh']);
                listRowEntry($user, 'Thiếu bản dịch', 'thieu-ban-dich');
                listRowTranslate(listRowEntry($user, 'Đủ bản dịch', 'du-ban-dich'), 'en', 'ja', 'zh');

                $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

                // one note, on the row that misses three, outside the column choice
                expect(substr_count($html, 'role="note"'))->toBe(1)
                    ->and($html)->toContain('<span class="list-m" data-for="langs">')
                    ->and($html)->toContain('<span class="list-lmiss" role="note" title="Chưa có bản tiếng Anh, tiếng Nhật và tiếng Trung"')
                    ->and($html)->toContain('<span>Chưa dịch: Anh, Nhật, Trung</span>')
                    // the complete row's piece follows the column choice
                    ->and($html)->toContain('<span class="list-m" data-for="langs" x-show="vLangs">')
                    // the column choice hides only the chips with content
                    ->and($html)->toContain('x-bind:data-off="(vLangs) ? null : \'\'"');
            });

            expect(listCss())->toMatch("/\\.list-lcg\\[data-off\\] > \\.list-lc:not\\(\\[data-s='empty'\\]\\)\\s*\\{\\s*display: none;/");
        });

        it('shows no language chips and no note on a single-language site', function (): void {
            $user = User::factory()->create();

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
                listRowLocales(['vi']);
                listRowEntry($user, 'Một ngôn ngữ', 'mot-ngon-ngu');

                Livewire::actingAs($user->fresh())->test(Index::class)
                    ->assertSee('Một ngôn ngữ')
                    ->assertDontSeeHtml('data-for="langs"')
                    ->assertDontSeeHtml('role="note"');
            });
        });

        it('sums up more than four locales in one button, shown with the column, that opens a menu of the versions', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
                listRowLocales(['vi', 'en', 'ja', 'zh', 'ko']);
                $entry = listRowEntry($user, 'Nhiều ngôn ngữ', 'nhieu-ngon-ngu');
                listRowTranslate($entry, 'en');
                listRowTranslate(listRowEntry($user, 'Đủ cả năm', 'du-ca-nam'), 'en', 'ja', 'zh', 'ko');

                $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

                // no chips; the note still names what is missing (whatever the column choice), and
                // the summary button follows the column choice
                expect($html)->not->toContain('class="list-lc"')
                    ->and(substr_count($html, 'role="note"'))->toBe(1)
                    ->and($html)->toContain('<span>Chưa dịch: Nhật, Trung, Hàn</span>')
                    ->and(substr_count($html, '<span class="list-lsw" x-show="vLangs"'))->toBe(2)
                    ->and($html)->toContain('<span>Thiếu 3 ngôn ngữ</span>')
                    ->and($html)->toContain('<span>Đủ 5 ngôn ngữ</span>')
                    ->and($html)->toContain('aria-label="'.e('Bản dịch của “Nhiều ngôn ngữ”: Thiếu 3 ngôn ngữ — bấm để chọn bản').'"')
                    ->and($html)->toContain('aria-haspopup="menu"')
                    ->and($html)->toContain('role="menu" aria-label="'.e('Các bản ngôn ngữ — Nhiều ngôn ngữ').'"')
                    ->and(substr_count($html, 'wire:navigate role="menuitem"'))->toBe(10)
                    ->and($html)->toContain('href="'.e(route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => 'ko'])).'" wire:navigate role="menuitem"')
                    ->and($html)->toContain('x-on:keydown.down.prevent=');
            });
        });

        it('draws a source with no content as missing, starred, named in the note', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
                listRowLocales(['vi', 'en']);
                $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $user->id]);
                listRowTranslate($entry, 'en');
                $edit = fn (string $lc): string => e(route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]));

                $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

                expect($html)->toContain('<a href="'.$edit('vi').'" wire:navigate class="list-lc" data-s="empty"')
                    ->and($html)->not->toContain('data-s="src"')
                    ->and($html)->toContain('<a href="'.$edit('en').'" wire:navigate class="list-lc" data-s="full"')
                    ->and($html)->toContain('title="Chưa có bản tiếng Việt (bản nguồn) — bấm để thêm" aria-label="Thêm bản tiếng Việt (bản nguồn) — “')
                    // the star still marks it as the source
                    ->and($html)->toMatch('/data-s="empty"\s+data-add\s+title="Chưa có bản tiếng Việt \(bản nguồn\)[^"]*" aria-label="[^"]*">Việt(<!--.*?-->)*<svg[^>]*list-lc-star/')
                    ->and($html)->toContain('<span>Chưa dịch: Việt</span>')
                    ->and($html)->toContain('title="Chưa có bản tiếng Việt"');
            });
        });

        it('tells a member who may view only that the source has no content yet', function (): void {
            $user = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user): void {
                grantRole($user, 'viewer', ['entries.view']);
                listRowLocales(['vi', 'en']);
                listRowTranslate(Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $user->id]), 'en');

                Livewire::actingAs($user->fresh())->test(Index::class)
                    ->assertSeeHtml('<button type="button" class="list-lc" data-s="empty"')
                    ->assertSeeHtml('aria-label="Chưa có bản tiếng Việt (bản nguồn). Bạn chỉ xem được trang này"')
                    ->assertSeeHtml('class="list-menu list-pop" role="group" aria-label="Tiếng Việt · bản nguồn"')
                    ->assertSeeHtml(e('Bản nguồn chưa có nội dung.'))
                    ->assertSeeHtml('<span>Chưa dịch: Việt</span>');
            });
        });

        it('links a member who may edit only their own records on their rows, and says why on the others', function (): void {
            $user = User::factory()->create();
            $other = User::factory()->create();
            app()->setLocale('vi');

            app(Tenancy::class)->enter(1, function () use ($user, $other): void {
                grantRole($user, 'author', ['entries.view', 'entries.edit.own']);
                listRowLocales(['vi', 'en']);
                $mine = listRowEntry($user, 'Bài của tôi', 'bai-cua-toi');
                $theirs = listRowEntry($other, 'Bài người khác', 'bai-nguoi-khac');

                $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

                expect($html)->toContain('<a href="'.e(route('admin.entries.edit', ['ulid' => $mine->ulid, 'locale' => 'en'])).'" wire:navigate class="list-lc"')
                    ->and($html)->not->toContain(e(route('admin.entries.edit', ['ulid' => $theirs->ulid, 'locale' => 'en'])))
                    ->and($html)->toContain('aria-label="Chưa có bản tiếng Anh. Bạn chỉ xem được trang này"')
                    // quick edit follows the same per-row right
                    ->and($html)->toContain(e(__('entries.quick_edit_named', ['title' => 'Bài của tôi'])))
                    ->and($html)->not->toContain(e(__('entries.quick_edit_named', ['title' => 'Bài người khác'])));
            });
        });
    });
});
