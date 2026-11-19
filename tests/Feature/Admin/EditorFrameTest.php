<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The frame every add/edit screen shares (components/editor-frame.blade.php; design 01 Admin Shell):
 * one header — the trail «⌂ › list › Thêm mới | Sửa», the name with «(?) Hướng dẫn» at the end of its
 * row and one line on what the screen is for — then the bar ← Quay lại · save state · Xem trước ·
 * Lưu nháp · ⋮ · the main button. The ⋮ holds Lưu nháp, «Chuyển vào thùng rác» on a saved record for a
 * member who may delete it (the list's policy, asked again by the method), and, for the phone's one
 * bar of a child screen, Xem trước and Cỡ chữ. On a phone the layout marks the screen as a child
 * screen, so its own bar replaces the admin's top bar.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function frameSection(): void
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
}

function frameEntry(string $type, User $author, string $title, string $slug, string $status = 'draft'): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => $status, 'author_id' => $author->id, 'meta' => []]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => []]]);

    return $entry->fresh() ?? $entry;
}

function frameMember(array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'frame-role', $permissions));

    return $user->fresh() ?? $user;
}

/** The phone's one bar (a child screen): the name in the bar, the second (?), Xem trước and Cỡ chữ in the ⋮. */
function assertChildBar(mixed $component): void
{
    $component->assertSeeHtml('class="ped-h1 m-0"')
        ->assertSeeHtml('ped-help')
        ->assertSeeHtml('class="list-mi ped-child-mi"')
        ->assertSeeHtml('class="ped-fs"')
        ->assertSee('Cỡ chữ')
        ->assertSeeHtml('role="menuitemradio"');
}

describe('posts and pages', function (): void {
    it('draws a new post with the Shell header, the guide, the bar and the ⋮ without the Trash', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any', 'entries.publish']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            frameSection();
            $c = Livewire::actingAs($member)->test(EntryEditor::class, ['section' => 'posts'])
                ->assertSeeHtmlInOrder([
                    '<header class="ped-head',
                    'href="'.route('admin.entries.index', ['section' => 'posts']).'"', '>Tin tức</a>', 'aria-current="page">Thêm mới</span>',
                    'id="editor-title" tabindex="-1"', 'Thêm tin tức</h1>', 'data-guide-open',
                    'Viết tin tức mới: nội dung, ảnh, đường dẫn và cách hiện trên Google.',
                    '</header>',
                    'data-guide="back"', 'data-guide="state"', 'data-guide="preview"', 'data-guide="save"', 'data-guide="more"', 'data-guide="main"',
                ])
                ->assertSeeHtml('data-guide-ctx')
                ->assertSeeHtml('"screen":"editor-post"')
                // nothing saved yet: Xem trước says why it waits, and the ⋮ has no Trash item
                ->assertSeeHtml('aria-disabled="true"')
                ->assertSee('Hãy lưu nháp một lần, rồi xem trước được.')
                ->assertSeeHtml('aria-haspopup="menu"')
                ->assertDontSee('Chuyển vào thùng rác');
            assertChildBar($c);
        });
    });

    it('prints the guide once: a later round trip leaves it out', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            frameSection();
            Livewire::actingAs($member)->test(EntryEditor::class, ['section' => 'posts'])
                ->assertSeeHtml('data-guide-ctx')
                ->set('title', 'Tin mới')
                ->assertDontSeeHtml('data-guide-ctx');
        });
    });

    it('offers Xem trước of the saved version and the Trash item on a post the member may delete', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            frameSection();
            $post = frameEntry('post', $member, 'Khai trương xưởng mới', 'khai-truong');
            $c = Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $post->ulid])
                ->assertSeeHtmlInOrder(['aria-current="page">Sửa</span>', 'Sửa tin tức</h1>', 'Sửa nội dung, ảnh, đường dẫn và cách hiện trên Google — mở từ dòng trong danh sách.'])
                ->assertSeeHtml('href="'.route('admin.entries.preview', ['ulid' => $post->ulid]).'"')
                ->assertSeeHtml('"id":"preview"')
                ->assertSeeHtml('wire:click="trash"')
                ->assertSee('Chuyển vào thùng rác');
            assertChildBar($c);
        });
    });

    it('draws no «Xem trước» for a member who may not preview the post, and the guide has no step for it', function (): void {
        $author = frameMember(['entries.view', 'entries.create', 'entries.edit.any']);
        $member = frameMember(['entries.edit.any']);

        app(Tenancy::class)->enter(1, function () use ($author, $member): void {
            frameSection();
            $post = frameEntry('post', $author, 'Bài không xem trước', 'bai-khong-xem-truoc');
            Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $post->ulid])
                ->assertSeeHtml('data-guide-ctx')
                ->assertSeeHtml('"id":"more"')
                ->assertDontSeeHtml('data-guide="preview"')
                ->assertDontSeeHtml('"id":"preview"');
        });
    });

    it('moves a post to the Trash once: a second move keeps its Trash date and says the post changed', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.own']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            frameSection();
            $post = frameEntry('post', $member, 'Bài bỏ hai lần', 'bai-bo-hai-lan');
            $c = Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $post->ulid])->call('trash');
            $trashedAt = Entry::onlyTrashed()->whereKey($post->id)->value('deleted_at');
            expect($trashedAt)->not->toBeNull();

            $this->travel(5)->minutes();
            $c->call('trash')
                ->assertOk()
                ->assertDispatched('bs-toast', text: 'Nội dung này không còn ở đây — có thể vừa được chuyển vào Thùng rác ở thẻ khác hoặc bởi người khác. Chữ bạn đang gõ vẫn còn trên màn này.');

            expect(Entry::onlyTrashed()->whereKey($post->id)->value('deleted_at'))->toEqual($trashedAt);
        });
    });

    it('leaves the Trash item out for a member who may not delete the post, and the method refuses them in words', function (): void {
        $author = frameMember(['entries.view', 'entries.create', 'entries.edit.any']);
        $member = frameMember(['entries.view', 'entries.edit.any', 'entries.delete.own']);

        app(Tenancy::class)->enter(1, function () use ($author, $member): void {
            frameSection();
            $post = frameEntry('post', $author, 'Bài của người khác', 'bai-nguoi-khac');
            Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $post->ulid])
                ->assertDontSee('Chuyển vào thùng rác')
                ->assertDontSeeHtml('wire:click="trash"')
                ->call('trash')
                ->assertOk()
                ->assertDispatched('bs-toast', text: 'Đây là bài viết của người khác — bạn chỉ chuyển vào Thùng rác được bài viết do mình tạo.');

            expect(Entry::query()->whereKey($post->id)->exists())->toBeTrue();
        });
    });

    it('moves a post to the Trash from the ⋮ and leaves for its list, which says so', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.own']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            frameSection();
            $post = frameEntry('post', $member, 'Bài sẽ bỏ', 'bai-se-bo');
            Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $post->ulid])
                ->call('trash')
                ->assertJs('window.bsLeaveTo('.Js::encode(route('admin.entries.index', ['section' => 'posts'])).', '.Js::encode(['text' => 'Đã chuyển “Bài sẽ bỏ” vào Thùng rác']).')');

            expect(Entry::query()->whereKey($post->id)->exists())->toBeFalse()
                ->and(Entry::onlyTrashed()->whereKey($post->id)->exists())->toBeTrue();
        });
    });

    it('shows the Trash item locked on the Home page, with the reason, and the method moves nothing', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $home = frameEntry('page', $member, 'Trang chủ', PathBuilder::HOME_SLUG);
            Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $home->ulid])
                ->assertSee('Chuyển vào thùng rác')
                ->assertSee('Muốn bỏ trang này, hãy chọn trang khác làm Trang chủ trước.')
                ->assertDontSeeHtml('wire:click="trash"')
                ->call('trash')
                ->assertDispatched('bs-toast', text: 'Muốn bỏ trang này, hãy chọn trang khác làm Trang chủ trước.');

            expect(Entry::query()->whereKey($home->id)->exists())->toBeTrue();
        });
    });

    it('refuses a page that has sub-pages: they go with it from the Pages list', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $parent = frameEntry('page', $member, 'Dịch vụ', 'dich-vu');
            $child = frameEntry('page', $member, 'Gia công', 'gia-cong');
            $child->forceFill(['parent_id' => $parent->id])->save();

            Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $parent->ulid])
                ->assertSee('Chuyển vào thùng rác')
                ->call('trash')
                ->assertDispatched('bs-toast', text: 'Trang này còn trang con. Hãy chuyển nó vào Thùng rác từ danh sách Trang: các trang con đi cùng để đường dẫn không bị gãy.');

            expect(Entry::query()->whereKey($parent->id)->exists())->toBeTrue();
        });
    });

    it('draws a page being edited with the same frame', function (): void {
        $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any', 'entries.publish']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $page = frameEntry('page', $member, 'Giới thiệu', 'gioi-thieu');
            $c = Livewire::actingAs($member)->test(EntryEditor::class, ['ulid' => $page->ulid])
                ->assertSeeHtmlInOrder(['aria-current="page">Sửa</span>', 'Sửa trang</h1>', 'data-guide="back"', 'data-guide="preview"', 'data-guide="more"'])
                ->assertSeeHtml('"screen":"editor-page"')
                ->assertSeeHtml('wire:click="trash"');
            assertChildBar($c);
        });
    });
});

describe('products', function (): void {
    beforeEach(function (): void {
        DB::connection('pgsql_admin')->table('site_modules')->insert([
            ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    });

    it('draws a new product with the Shell header, «Sản phẩm» as a link, and no Trash item', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $c = Livewire::actingAs($member)->test(ProductEditor::class)
                ->assertSeeHtmlInOrder([
                    '<header class="ped-head', 'href="'.route('admin.catalog.index').'"', '>Sản phẩm</a>', 'aria-current="page">Thêm mới</span>',
                    'Thêm sản phẩm</h1>', 'data-guide-open', 'Nhập tên, ảnh, giá và mô tả — phần còn lại nằm trong “Thêm tùy chọn”.', '</header>',
                    'data-guide="back"', 'data-guide="save"', 'data-guide="more"', 'data-guide="main"',
                ])
                // the product board keeps «Xem trước» out of the bar: it is the phone's ⋮ item only
                ->assertDontSeeHtml('data-guide="preview"')
                ->assertSeeHtml('"screen":"editor-product"')
                ->assertSeeHtml('ped-child-mi')
                ->assertDontSee('Chuyển vào thùng rác');
            assertChildBar($c);
        });
    });

    it('offers Xem trước (in the phone\'s ⋮) and the Trash item on a saved product; the move leaves for the list', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $product = Product::query()->create(['status' => 'draft']);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá hàn', 'slug' => 'do-ga-han']);

            Livewire::actingAs($member)->test(ProductEditor::class, ['ulid' => $product->ulid])
                ->assertSeeHtmlInOrder(['aria-current="page">Sửa</span>', 'Sửa sản phẩm</h1>', 'Sửa ảnh, giá, mô tả và cách hiện trên Google — mở từ dòng trong danh sách.'])
                ->assertSeeHtml('href="'.route('admin.catalog.preview', ['ulid' => $product->ulid]).'"')
                ->assertDontSeeHtml('ped-pv')
                ->assertSeeHtml('wire:click="trash"')
                ->call('trash')
                ->assertJs('window.bsLeaveTo('.Js::encode(route('admin.catalog.index')).', '.Js::encode(['text' => 'Đã chuyển “Đồ gá hàn” vào Thùng rác.']).')');

            expect(Product::query()->whereKey($product->id)->exists())->toBeFalse()
                ->and(Product::onlyTrashed()->whereKey($product->id)->exists())->toBeTrue();
        });
    });

    it('refuses in words a member whose delete right went after the screen opened; the product stays', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $product = Product::query()->create(['status' => 'draft']);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Khuôn dập', 'slug' => 'khuon-dap']);
            $c = Livewire::actingAs($member)->test(ProductEditor::class, ['ulid' => $product->ulid])->assertSeeHtml('wire:click="trash"');

            $member->roles()->firstOrFail()->revokePermissionTo('catalog.manage');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            auth()->setUser($member->fresh() ?? $member);
            $c->call('trash')
                ->assertOk()
                ->assertDispatched('bs-toast', text: 'Nhóm quyền của bạn không xóa được sản phẩm này.');

            expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
        });
    });

    it('refuses in words a product that is now outside the member\'s category limits; the product stays', function (): void {
        [$moulds, $jigs] = app(Tenancy::class)->enter(1, fn (): array => [
            app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Khuôn'])),
            app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Đồ gá'])),
        ]);
        $product = app(Tenancy::class)->enter(1, function () use ($moulds): Product {
            $product = Product::query()->create(['status' => 'draft']);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Khuôn ép', 'slug' => 'khuon-ep']);
            $product->assignCategories([$moulds->id]);

            return $product;
        });
        $member = frameMember(['catalog.view', 'catalog.manage']);
        $limits = fn () => DB::connection('pgsql_admin')->table('member_category_scopes');
        $limits()->insert(['site_id' => 1, 'user_id' => $member->id, 'taxonomy_id' => $moulds->id]);
        app(TeamContext::class)->forget();

        app(Tenancy::class)->enter(1, function () use ($member, $product, $moulds, $jigs, $limits): void {
            app(CategoryScopes::class)->forget();
            $c = Livewire::actingAs($member)->test(ProductEditor::class, ['ulid' => $product->ulid])->assertSeeHtml('wire:click="trash"');

            // another admin narrows the member's limits to «Đồ gá» while the screen is open
            $limits()->where('user_id', $member->id)->where('taxonomy_id', $moulds->id)->delete();
            $limits()->insert(['site_id' => 1, 'user_id' => $member->id, 'taxonomy_id' => $jigs->id]);
            app(CategoryScopes::class)->forget();
            $c->call('trash')
                ->assertOk()
                ->assertDispatched('bs-toast', text: 'Bạn chỉ được sửa sản phẩm trong: Đồ gá.');

            expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
        });
    });

    it('refuses the move with a 403 once the website\'s plan no longer has the catalog; the product stays', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $product = Product::query()->create(['status' => 'draft']);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Bàn gá', 'slug' => 'ban-ga']);
            $c = Livewire::actingAs($member)->test(ProductEditor::class, ['ulid' => $product->ulid]);

            DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->where('module_key', 'catalog')->update(['enabled' => false]);
            app()->forgetInstance(Entitlement::class);
            $c->call('trash')->assertForbidden();

            expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
        });
    });

    it('moves a product to the Trash once: a second move keeps its Trash date and says it is gone', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $product = Product::query()->create(['status' => 'draft']);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Khuôn bỏ hai lần', 'slug' => 'khuon-bo-hai-lan']);
            $c = Livewire::actingAs($member)->test(ProductEditor::class, ['ulid' => $product->ulid])->call('trash');
            $trashedAt = Product::onlyTrashed()->whereKey($product->id)->value('deleted_at');
            expect($trashedAt)->not->toBeNull();

            $this->travel(5)->minutes();
            $c->call('trash')
                ->assertOk()
                ->assertDispatched('bs-toast', text: 'Nội dung này không còn ở đây — có thể vừa được chuyển vào Thùng rác ở thẻ khác hoặc bởi người khác. Chữ bạn đang gõ vẫn còn trên màn này.');

            expect(Product::onlyTrashed()->whereKey($product->id)->value('deleted_at'))->toEqual($trashedAt);
        });
    });

    it('shows the address before the slug inside the field, not a bare slash', function (): void {
        $member = frameMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $c = Livewire::actingAs($member)->test(ProductEditor::class);
            $base = $c->get('address')['base'];

            expect($base)->toContain('/');
            $c->assertSeeHtml('dir="ltr">'.e($base).'</span>');
        });
    });
});

it('marks the add and edit screens as child screens for the phone, and no other', function (): void {
    $member = frameMember(['entries.view', 'entries.create', 'entries.edit.any']);

    app(Tenancy::class)->enter(1, function (): void {
        frameSection();
    });

    $this->actingAs($member)->get('http://a.test/admin/content/create?section=posts')->assertOk()->assertSee('data-child-screen', false);
    $this->actingAs($member)->get('http://a.test/admin/content?section=posts')->assertOk()->assertDontSee('data-child-screen', false);
});
