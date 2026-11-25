<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The product editor beyond the form (design 09): the cards it shows in place of the form (the plan,
 * no permission, a read that failed, the Trash), the ⋮'s status moves, the read-only form of a member
 * who may only see products, several categories with one main one, and the picture strip's moves —
 * every write refused on the server to who may not, and nothing reaching another website's rows.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function cesMember(array $permissions, string $role = 'cat-manager', int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $role, $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function cesProduct(string $status = 'draft', string $name = 'Đồ gá hàn', string $slug = 'do-ga-han'): Product
{
    $product = Product::query()->create(['status' => $status] + ($status === 'published' ? ['published_at' => now()] : []));
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product;
}

function cesCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => $name]));
}

function cesMedia(string $suffix): int
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => "anh-{$suffix}", 'hash' => str_repeat($suffix, 64), 'original_filename' => "anh-{$suffix}.png",
        'storage_key' => "s/library/anh-{$suffix}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ])->id;
}

/** @return list<int> the product's categories as stored, main first */
function cesCategories(Product $product): array
{
    return DB::table('product_taxonomies')->where('product_id', $product->id)->where('position', '<', Product::FACET_POSITION_BASE)
        ->orderBy('position')->pluck('taxonomy_id')->map(fn (mixed $id): int => (int) $id)->all();
}

describe('the cards in place of the form', function (): void {
    it('shows the plan card before anything else when the website has no catalog, and refuses every write', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);
        DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->update(['enabled' => false]);
        app()->forgetInstance(Entitlement::class);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct();

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('screen', 'plan')->assertSee(__('catalog.plan_title'))->assertDontSee('Đồ gá hàn')
                ->call('save')->assertForbidden();
            Livewire::actingAs($manager)->test(Editor::class)->assertSet('screen', 'plan');
        });
    });

    it('shows the no-permission card to a member without the right to see products, naming their role', function (): void {
        $member = cesMember(['media.view'], 'tro-ly');

        app(Tenancy::class)->enter(1, function () use ($member): void {
            $product = cesProduct();

            Livewire::actingAs($member)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('screen', 'noperm')->assertSee(__('catalog.ed.noperm_title'))->assertSee(__('catalog.ed.noperm_text', ['role' => 'tro-ly']))
                ->assertDontSee('Đồ gá hàn')->assertDontSee(__('catalog.ed.state_back'));
        });
    });

    it('shows the error card with «Thử lại» when the product cannot be read, and opens the form on retry', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct();
            $fail = true;
            DB::beforeExecuting(function (string $query) use (&$fail): void {
                if ($fail && str_contains($query, 'from "products"') && str_contains($query, '"ulid" =')) {
                    throw new QueryException('pgsql', $query, [], new RuntimeException('server busy'));
                }
            });

            $c = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('screen', 'error')->assertSee(__('catalog.ed.error_title'))->assertSee(__('catalog.error_retry'))->assertSee(__('catalog.ed.state_back'));

            $fail = false;
            $c->call('retry')->assertSet('screen', 'form')->assertSet('name', 'Đồ gá hàn');
        });
    });

    it('opens a product in the Trash on its card; «Khôi phục» brings it back as a draft with the form', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct('published');
            $product->delete();

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('screen', 'trash')->assertSee(__('catalog.ed.trash_title'))
                ->assertSee(__('catalog.ed.trash_text_manage'))
                ->assertSeeHtml('wire:click="restore"')
                ->call('restore')
                ->assertSet('screen', 'form')->assertSet('status', 'draft')->assertSet('name', 'Đồ gá hàn')
                ->assertDispatched('bs-toast', text: __('catalog.ed.toast_restored'));

            $row = Product::query()->whereKey($product->id)->first();
            expect($row)->not->toBeNull()->and($row?->status)->toBe('draft');
        });
    });

    it('shows a product in the Trash without «Khôi phục» to who may only see it, and refuses the restore', function (): void {
        $viewer = cesMember(['catalog.view'], 'cat-viewer');

        app(Tenancy::class)->enter(1, function () use ($viewer): void {
            $product = cesProduct();
            $product->delete();

            Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('screen', 'trash')->assertSee(__('catalog.ed.trash_text_view'))->assertDontSeeHtml('wire:click="restore"')
                ->call('restore')->assertForbidden();

            expect(Product::onlyTrashed()->whereKey($product->id)->exists())->toBeTrue();
        });
    });

    it('moves a product to the Trash and stays on its card; «Hoàn tác» brings it back as it was, with what was typed', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct('published');

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('summary', 'Đang gõ dở')
                ->call('trash')
                ->assertSet('screen', 'trash')->assertSet('undo', 'trash')
                ->assertSee(__('catalog.toast_trashed', ['name' => 'Đồ gá hàn']))
                ->call('undoLast')
                ->assertSet('screen', 'form')->assertSet('status', 'published')->assertSet('summary', 'Đang gõ dở')->assertSet('toastMsg', '');

            expect(Product::query()->whereKey($product->id)->value('status'))->toBe('published');
        });
    });
});

describe('saving from a card', function (): void {
    it('refuses a save or a move to the Trash on the card of a product that could not be read, and writes nothing', function (string $call): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager, $call): void {
            $product = cesProduct();
            $fail = true;
            DB::beforeExecuting(function (string $query) use (&$fail): void {
                if ($fail && str_contains($query, 'from "products"') && str_contains($query, '"ulid" =')) {
                    throw new QueryException('pgsql', $query, [], new RuntimeException('server busy'));
                }
            });
            $c = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('screen', 'error');
            $fail = false;
            $before = DB::table('products')->where('id', $product->id)->first();

            $c->set('name', 'Lén')->set('slug', 'len')->call($call)->assertNotFound();

            expect(DB::table('products')->where('id', $product->id)->first())->toEqual($before)
                ->and(DB::table('product_translations')->where('product_id', $product->id)->value('name'))->toBe('Đồ gá hàn');
        });
    })->with(['save', 'publish', 'trash']);

    it('refuses a save on the Trash card, even once the product came back elsewhere, and writes nothing', function (string $call): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager, $call): void {
            $product = cesProduct();
            $product->delete();

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('screen', 'trash')->call($call)->assertNotFound();
            expect(Product::onlyTrashed()->whereKey($product->id)->exists())->toBeTrue();

            // restored by another member while this card stays open
            $c = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('screen', 'trash');
            $product->restore();
            $before = DB::table('products')->where('id', $product->id)->first();
            $c->call($call)->assertNotFound();

            expect(DB::table('products')->where('id', $product->id)->first())->toEqual($before);
        });
    })->with(['save', 'publish']);
});

describe('the ⋮ status moves', function (): void {
    it('takes a published product off the website or back to draft, each a full save with its words', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct('published');
            $c = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSee(__('catalog.ed.menu_unpublish'))->assertSee(__('catalog.ed.menu_unpublish_hint'))->assertSee(__('catalog.ed.menu_to_draft'));

            $c->set('name', 'Đồ gá hàn mới')->call('unpublish')->assertHasNoErrors()
                ->assertSet('status', 'archived')->assertDispatched('bs-toast', text: __('catalog.ed.toast_unpublished'));
            expect(Product::query()->whereKey($product->id)->value('status'))->toBe('archived')
                ->and(DB::table('product_translations')->where('product_id', $product->id)->value('name'))->toBe('Đồ gá hàn mới');

            $c->assertSee(__('catalog.ed.menu_to_draft_hint_archived'))->assertDontSee(__('catalog.ed.menu_unpublish_hint')) // the menu's own line; «Gỡ khỏi website lúc» stays on the «Đăng» card
                ->call('toDraft')->assertSet('status', 'draft')->assertDispatched('bs-toast', text: __('catalog.ed.toast_to_draft'));
            expect(Product::query()->whereKey($product->id)->value('status'))->toBe('draft');

            $c->assertDontSee(__('catalog.ed.menu_to_draft'))->call('toDraft')->assertNotFound();
            // «Khôi phục» belongs to the Trash card only (a fresh screen: a refused call leaves no state to go on with)
            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])->call('restore')->assertNotFound();
        });
    });

    it('keeps the status when a field to fix stops the move', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct('published');
            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', '')->call('unpublish')->assertHasErrors('name')->assertSet('status', 'published');

            expect(Product::query()->whereKey($product->id)->value('status'))->toBe('published');
        });
    });
});

describe('read only', function (): void {
    it('shows a member who may only see products the form locked, with the note and no save', function (): void {
        $viewer = cesMember(['catalog.view'], 'cat-viewer');

        app(Tenancy::class)->enter(1, function () use ($viewer): void {
            $product = cesProduct('published');
            $product->translations()->where('locale', 'vi')->update(['description' => '<p>Thép <strong>C45</strong></p><script>alert(1)</script>']);

            Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('canEdit', false)->assertSet('screen', 'form')
                ->assertSee(__('catalog.ed.ro_lead'))->assertSee(__('catalog.ed.ro_text', ['role' => 'cat-viewer']))
                ->assertSeeHtml('id="catalog-name" wire:model="name" readonly')
                ->assertSeeHtml('<strong>C45</strong>')->assertDontSeeHtml('<script>alert(1)')
                ->assertDontSeeHtml('wire:click="publish"')->assertDontSeeHtml('wire:click="unpublish"')->assertDontSeeHtml('data-guide="trash"')
                ->assertDontSeeHtml('wire:click="openImagePicker"')
                ->assertSee(__('catalog.ed.img_empty'))->assertDontSee(__('catalog.ed.img_choose'))
                // the preview page is for who may manage products; the ⋮ stays with «Cỡ chữ» only
                ->assertDontSeeHtml(route('admin.catalog.preview', ['ulid' => $product->ulid]))
                ->assertSeeHtml('data-guide="more"')->assertSee(__('admin.account.text_size'));
        });
    });

    it('offers «Xem trước» to who may change the product', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $product = cesProduct('published');

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('canEdit', true)->assertSeeHtml(route('admin.catalog.preview', ['ulid' => $product->ulid]));
        });
    });

    it('refuses a member limited to some product categories a product outside them, even to read', function (): void {
        $viewer = cesMember(['catalog.view'], 'cat-viewer');

        [$inside, $outside] = app(Tenancy::class)->enter(1, fn (): array => [cesCategory('Khuôn'), cesCategory('Đồ gá')]);
        DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $viewer->id, 'taxonomy_id' => $inside->id]);
        app(CategoryScopes::class)->forget();

        app(Tenancy::class)->enter(1, function () use ($viewer, $inside, $outside): void {
            $out = cesProduct();
            $out->assignCategories([$outside->id]);
            $in = cesProduct(name: 'Khuôn ép', slug: 'khuon-ep');
            $in->assignCategories([$inside->id]);

            Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $out->ulid])->assertForbidden();
            Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $in->ulid])->assertSet('canEdit', false)->assertSee('Khuôn ép');
        });
    });
});

describe('several categories', function (): void {
    it('files the product under every ticked category, the first one ticked the main one, and moves the star', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            $a = cesCategory('Khuôn');
            $b = cesCategory('Khuôn nhỏ', $a);
            $c = cesCategory('Đồ gá');
            $product = cesProduct();

            $editor = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->call('toggleCategory', $b->ulid)->call('toggleCategory', $c->ulid)->call('toggleCategory', $a->ulid)
                ->assertSet('categoryIds', [$b->id, $c->id, $a->id])->assertSet('categoryId', $b->id)
                ->assertSee(__('catalog.ed.cat_main'))->assertSee(__('catalog.ed.cat_star'))->assertSeeHtml("toggleCategory('{$a->ulid}')");

            $editor->call('setMainCategory', $a->ulid)->assertSet('categoryIds', [$a->id, $b->id, $c->id])->call('save')->assertHasNoErrors();
            expect(cesCategories($product))->toBe([$a->id, $b->id, $c->id]);

            // the main one unticked: the next one takes its place
            $editor->call('toggleCategory', $a->ulid)->assertSet('categoryIds', [$b->id, $c->id])->assertSet('categoryId', $b->id)->call('save');
            expect(cesCategories($product))->toBe([$b->id, $c->id])
                ->and((int) DB::table('product_taxonomies')->where('product_id', $product->id)->where('position', 0)->value('taxonomy_id'))->toBe($b->id);

            Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('categoryIds', [$b->id, $c->id]);
        });
    });

    it('lets a limited member add only categories inside the limits and keeps a stored one they cannot see', function (): void {
        $lead = cesMember(['catalog.view', 'catalog.manage']);

        [$inside, $outside, $sub] = app(Tenancy::class)->enter(1, function (): array {
            $inside = cesCategory('Khuôn');

            return [$inside, cesCategory('Đồ gá'), cesCategory('Khuôn nhỏ', $inside)];
        });
        DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $lead->id, 'taxonomy_id' => $inside->id]);
        app(CategoryScopes::class)->forget();

        app(Tenancy::class)->enter(1, function () use ($lead, $inside, $outside, $sub): void {
            $product = cesProduct(name: 'Khuôn ép', slug: 'khuon-ep');
            $product->assignCategories([$inside->id, $outside->id]);

            $editor = Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSee('Khuôn')->assertDontSee('Đồ gá');
            $editor->call('setMainCategory', $outside->ulid)->assertForbidden();
            // nor taken out: a stored category the member cannot see stays
            Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid])->call('toggleCategory', $outside->ulid)->assertForbidden();

            Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid])->set('name', 'Khuôn ép mới')->call('save')->assertHasNoErrors();
            expect(cesCategories($product))->toBe([$inside->id, $outside->id]);

            // a category outside the limits added elsewhere after the form opened stays too
            $product->assignCategories([$inside->id]);
            $mid = Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid]);
            $product->assignCategories([$inside->id, $outside->id]);
            $mid->set('name', 'Khuôn ép lần hai')->call('save')->assertHasNoErrors();
            expect(cesCategories($product))->toBe([$inside->id, $outside->id]);

            // one inside the limits is taken out; the one outside stays
            $product->assignCategories([$inside->id, $sub->id, $outside->id]);
            Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid])
                ->call('toggleCategory', $sub->ulid)->call('save')->assertHasNoErrors();
            expect(cesCategories($product))->toBe([$inside->id, $outside->id]);

            // a category outside the limits taken out elsewhere after the form opened is not filed again
            $product->assignCategories([$inside->id, $outside->id]);
            $stale = Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('categoryIds', [$inside->id, $outside->id]);
            $product->assignCategories([$inside->id]);
            $stale->set('name', 'Khuôn ép lần ba')->call('save')->assertHasNoErrors()->assertSet('categoryIds', [$inside->id])->assertSet('categoryId', $inside->id);
            expect(cesCategories($product))->toBe([$inside->id]);

            $product2 = cesProduct(name: 'Khuôn dập', slug: 'khuon-dap');
            $product2->assignCategories([$inside->id]);
            Livewire::actingAs($lead)->test(Editor::class, ['ulid' => $product2->ulid])->call('toggleCategory', $outside->ulid)->assertForbidden();
            expect(cesCategories($product2))->toBe([$inside->id]);
        });
    });
});

describe('the picture strip', function (): void {
    it('moves a picture by dragging, makes one the main picture, and takes one off with «Hoàn tác» back to its place', function (): void {
        $manager = cesMember(['catalog.view', 'catalog.manage', 'media.view']);

        app(Tenancy::class)->enter(1, function () use ($manager): void {
            [$a, $b, $c] = [cesMedia('a'), cesMedia('b'), cesMedia('c')];
            $product = cesProduct();
            foreach ([$a, $b, $c] as $i => $id) {
                $product->images()->create(['media_id' => $id, 'position' => $i]);
            }

            $editor = Livewire::actingAs($manager)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSee(__('catalog.gallery_primary'))->assertSee(__('catalog.ed.img_set_cover'))
                ->call('moveImage', 2, 0)->assertSet('imageMediaIds', [$c, $a, $b])
                ->call('setCover', 2)->assertSet('imageMediaIds', [$b, $c, $a])
                ->call('moveImage', 9, 0)->assertSet('imageMediaIds', [$b, $c, $a]);

            $editor->call('removeImage', 0)->assertSet('imageMediaIds', [$c, $a])
                ->assertSet('toastMsg', __('catalog.ed.toast_img_cover_removed', ['name' => 'anh-b.png', 'next' => 'anh-c.png']))
                ->call('undoLast')->assertSet('imageMediaIds', [$b, $c, $a])->assertSet('toastMsg', '');

            $editor->call('removeImage', 1)->assertSet('toastMsg', __('catalog.ed.toast_img_removed', ['name' => 'anh-c.png']))
                ->call('save')->assertHasNoErrors();
            expect(DB::table('product_images')->where('product_id', $product->id)->orderBy('position')->pluck('media_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$b, $a]);
        });
    });
});

describe('another website', function (): void {
    it('refuses another website\'s picture put in the gallery by hand, and attaches nothing', function (): void {
        $siteB = cesMember(['catalog.view', 'catalog.manage', 'media.view'], 'cat-manager-b', 2);
        enableCatalog(2);

        $mediaA = app(Tenancy::class)->enter(1, fn (): int => cesMedia('a'));

        app(Tenancy::class)->enter(2, function () use ($siteB, $mediaA): void {
            $own = cesProduct(name: 'Bàn gá', slug: 'ban-ga');

            Livewire::actingAs($siteB)->test(Editor::class, ['ulid' => $own->ulid])
                ->set('imageMediaIds', [$mediaA])->call('save')->assertHasErrors(['imageMediaIds.0']);
        });

        expect(DB::connection('pgsql_admin')->table('product_images')->where('media_id', $mediaA)->count())->toBe(0)
            ->and(DB::connection('pgsql_admin')->table('product_images')->where('site_id', 2)->count())->toBe(0);
    });

    it('never opens or changes another website\'s product, nor files under its category', function (): void {
        $siteA = cesMember(['catalog.view', 'catalog.manage']);
        $siteB = cesMember(['catalog.view', 'catalog.manage'], 'cat-manager-b', 2);
        enableCatalog(2);

        [$productA, $categoryA] = app(Tenancy::class)->enter(1, fn (): array => [cesProduct('published'), cesCategory('Khuôn')]);

        app(Tenancy::class)->enter(2, function () use ($siteB, $productA, $categoryA): void {
            Livewire::actingAs($siteB)->test(Editor::class, ['ulid' => $productA->ulid])->assertNotFound();

            $own = cesProduct(name: 'Bàn gá', slug: 'ban-ga');
            Livewire::actingAs($siteB)->test(Editor::class, ['ulid' => $own->ulid])->call('toggleCategory', $categoryA->ulid)->assertNotFound();
        });

        app(Tenancy::class)->enter(1, function () use ($productA, $siteA): void {
            expect(Product::query()->whereKey($productA->id)->value('status'))->toBe('published')
                ->and(DB::table('product_taxonomies')->count())->toBe(0);
            Livewire::actingAs($siteA)->test(Editor::class, ['ulid' => $productA->ulid])->assertSet('screen', 'form');
        });
    });
});
