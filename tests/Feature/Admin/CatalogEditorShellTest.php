<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The product editor's frame: the buttons each status shows (Lưu nháp · Đăng · Cập nhật · Lưu), the
 * save state beside the title, the draft that saves itself three seconds after the last change (a
 * published product never does: its changes wait for Cập nhật), the leave dialog's words, and the
 * short summary as plain text of at most 255 characters — an older, longer or formatted summary is
 * shown with a warning and kept exactly as stored until the field is changed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');   // the words checked below are the Vietnamese ones
});

function shellManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));

    return $user->fresh();
}

/** A product of the current site with its vi translation. */
function shellProduct(string $status = 'draft', array $translation = []): Product
{
    $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
    $product->translations()->create($translation + ['locale' => 'vi', 'name' => 'Đồ gá hàn', 'slug' => 'do-ga-han']);

    return $product->fresh();
}

describe('buttons and title per status', function (): void {
    it('offers Lưu nháp and Đăng on a new product, titled Thêm sản phẩm, never saved yet', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)
                ->assertViewHas('actions', ['secondary' => 'Lưu nháp', 'primary' => 'Đăng', 'primaryIcon' => 'ri-global'])
                ->assertSee('Thêm sản phẩm')
                ->assertSee('Thêm mới')
                ->assertSee('Chưa lưu lần nào')
                ->assertSee('Chưa đăng lần nào — tự ghi khi bấm Đăng lần đầu')
                ->assertSee('Chưa — sản phẩm không hiện trên website')
                ->assertSee('Sản phẩm này chưa được lưu. Rời trang này bây giờ thì phần vừa viết sẽ mất.')
                ->assertSee('Lưu nháp và rời đi');
        });
    });

    it('offers Lưu nháp and Đăng on a draft, with the draft wording in the leave dialog', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('draft');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertViewHas('actions', ['secondary' => 'Lưu nháp', 'primary' => 'Đăng', 'primaryIcon' => 'ri-global'])
                ->assertSee('Sửa sản phẩm')
                ->assertSee('Thay đổi vừa rồi chưa kịp tự lưu. Rời trang bây giờ thì thay đổi này sẽ mất.')
                ->assertSee('Lưu nháp và rời đi');
        });
    });

    it('offers only Cập nhật on a published product, which says how visitors see it', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('published');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertViewHas('actions', ['secondary' => null, 'primary' => 'Cập nhật', 'primaryIcon' => 'ri-refresh'])
                ->assertDontSee('Lưu nháp')
                ->assertSee('(ghi lần đăng đầu)')
                ->assertSee('Có — không kèm giá, nút “Liên hệ báo giá”')   // a brochure site, the default
                ->assertSee('Rời trang này bây giờ thì phần vừa sửa sẽ mất.')
                ->assertSee('Cập nhật và rời đi');
        });
    });

    it('offers Lưu and Đăng on a product taken off the website', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('archived');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertViewHas('actions', ['secondary' => 'Lưu', 'primary' => 'Đăng', 'primaryIcon' => 'ri-global'])
                ->assertSee('Đã gỡ khỏi website')
                ->assertSee('Lưu và rời đi');
        });
    });
});

describe('saving and publishing', function (): void {
    it('never takes the status from the browser', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)->set('status', 'published');
        });
    })->throws(CannotUpdateLockedPropertyException::class);

    it('keeps a draft a draft on Lưu nháp, and publishes it on Đăng with its first publication date', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $editor = Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Đồ gá hàn khung')
                ->call('save')
                ->assertHasNoErrors()
                ->assertSet('status', 'draft')
                ->assertSet('savedKind', 'saved')
                ->assertDispatched('bs-toast')
                ->assertNoRedirect();

            $product = Product::query()->sole();
            expect($product->status)->toBe('draft')
                ->and($product->published_at)->toBeNull()
                ->and($editor->get('ulid'))->toBe($product->ulid);   // the same form edits it from now on

            $editor->call('publish')->assertHasNoErrors()->assertSet('status', 'published');

            expect($product->fresh()->status)->toBe('published')
                ->and($product->fresh()->published_at)->not->toBeNull()
                ->and(Product::query()->count())->toBe(1);
        });
    });

    it('keeps a published product published on Cập nhật', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('published');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', 'Đồ gá hàn mới')
                ->call('publish')
                ->assertHasNoErrors();

            expect($product->fresh()->status)->toBe('published')
                ->and($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn mới');
        });
    });

    it('puts the status back when publishing fails, and says the form has fields to fix', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', '')
                ->call('publish')
                ->assertHasErrors('name')
                ->assertSet('status', 'draft')
                ->assertSet('saveFailed', true)
                ->assertSee('Chưa lưu — còn ô cần sửa')
                ->set('name', 'Đồ gá')
                ->call('publish')
                ->assertHasNoErrors()
                ->assertSet('saveFailed', false);

            expect(Product::query()->sole()->status)->toBe('published');
        });
    });

    it('counts the changes not saved yet, and drops them on Bỏ thay đổi', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('published', ['summary' => 'Tóm tắt']);

            $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid]);
            expect($editor->viewData('changes'))->toBe(0);

            $editor->set('name', 'Tên khác')->set('summary', 'Tóm tắt khác')->set('sku', 'DG-9');
            expect($editor->viewData('changes'))->toBe(3);

            $editor->call('resetChanges')
                ->assertSet('name', 'Đồ gá hàn')
                ->assertSet('summary', 'Tóm tắt')
                ->assertSet('sku', null);
            expect($editor->viewData('changes'))->toBe(0)
                ->and($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn');
        });
    });
});

describe('saving a draft by itself', function (): void {
    it('saves a changed draft, says it saved it by itself, and keeps it a draft', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('draft');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', 'Đồ gá hàn khung')
                ->call('autosave')
                ->assertHasNoErrors()
                ->assertSet('savedKind', 'auto')
                ->assertNotDispatched('bs-toast')
                ->assertSee('Đã tự lưu nháp lúc');

            expect($product->fresh()->status)->toBe('draft')
                ->and($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn khung');
        });
    });

    it('saves a new product as a draft by itself once it has a name', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Máy tiện')
                ->call('autosave')
                ->assertHasNoErrors();

            expect(Product::query()->sole()->status)->toBe('draft');
        });
    });

    it('leaves the fields as typed while storing tidy text, and a blank address follows the name until a button save', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $editor = Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Máy ')
                ->set('summary', "Dòng một\n")
                ->call('autosave')
                ->assertHasNoErrors()
                ->assertSet('name', 'Máy ')            // the space before the next word stays
                ->assertSet('summary', "Dòng một\n")
                ->assertSet('slug', '');
            expect($editor->viewData('changes'))->toBe(0);

            $product = Product::query()->sole();
            expect($product->translationFor('vi')?->name)->toBe('Máy')
                ->and($product->translationFor('vi')?->summary)->toBe('Dòng một')
                ->and($product->translationFor('vi')?->slug)->toBe('may');

            $editor->set('name', 'Máy tiện CNC')->call('autosave')->assertHasNoErrors();
            expect($product->fresh()->translationFor('vi')?->slug)->toBe('may-tien-cnc');

            $editor->call('publish')->assertHasNoErrors()->assertSet('slug', 'may-tien-cnc');
            expect($product->fresh()->status)->toBe('published');
        });
    });

    it('never saves a published product by itself', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('published');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', 'Tên chưa muốn cho khách thấy')
                ->call('autosave')
                ->assertSet('savedKind', null)
                ->assertSee('bấm Cập nhật để khách thấy');

            expect($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn');
        });
    });

    it('never saves a product taken off the website by itself', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('archived');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', 'Tên khác')
                ->call('autosave')
                ->assertSet('savedKind', null);

            expect($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn');
        });
    });

    it('writes nothing when nothing changed', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $product = shellProduct('draft');
            DB::table('products')->update(['updated_at' => '2026-01-01 00:00:00+00']);

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->call('autosave')
                ->assertSet('savedKind', null);

            expect($product->fresh()->updated_at?->toDateString())->toBe('2026-01-01');
        });
    });

    it('stays quiet when the form still has a field to fix, and keeps the errors already shown', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', '')
                ->set('sku', 'DG-1')
                ->call('autosave')
                ->assertHasNoErrors()
                ->assertSet('savedKind', null)
                ->assertSet('ulid', null);

            expect(Product::query()->count())->toBe(0);

            $taken = shellProduct('draft', ['slug' => 'da-co']);
            $taken->update(['sku' => 'DG-1']);

            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Đồ gá')
                ->set('sku', 'DG-1')
                ->call('save')
                ->assertHasErrors('sku')
                ->call('autosave')
                ->assertHasErrors('sku');   // the message the member saw stays until it is fixed
        });
    });
});

describe('the short summary', function (): void {
    it('takes plain text up to 255 characters and refuses more', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Đồ gá')
                ->set('summary', str_repeat('ă', 256))
                ->call('save')
                ->assertHasErrors(['summary' => 'max']);

            expect(Product::query()->count())->toBe(0);

            Livewire::actingAs($user)->test(Editor::class)
                ->set('name', 'Đồ gá')
                ->set('summary', "Dòng một\nDòng <b>hai</b>".str_repeat('ă', 230))
                ->call('save')
                ->assertHasNoErrors();

            // Stored as typed: the storefront prints it as text, so the tags stay visible text.
            $product = Product::query()->sole();
            expect($product->translationFor('vi')?->summary)->toBe("Dòng một\nDòng <b>hai</b>".str_repeat('ă', 230));

            // Opened again, plain text is plain text: shown as it is, no warning, nothing changed.
            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->set('summary', "Bền & nhẹ\ndưới 5 kg")->call('save')->assertHasNoErrors();
            $again = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('summary', "Bền & nhẹ\ndưới 5 kg")
                ->assertDontSee('Tóm tắt cũ');
            expect($again->viewData('changes'))->toBe(0);
        });
    });

    it('keeps an older summary longer than 255 characters exactly while it is left alone, and says so', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $long = str_repeat('Đồ gá chính xác. ', 20);   // 340 characters
            $product = shellProduct('draft', ['summary' => $long]);

            $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('summary', $long)
                ->assertSee('Tóm tắt cũ dài 340 ký tự');
            expect($editor->viewData('changes'))->toBe(0);

            $editor->set('name', 'Đồ gá mới')->call('save')->assertHasNoErrors();

            expect($product->fresh()->translationFor('vi')?->summary)->toBe($long);

            // Changed, it is new text: the limit applies.
            $editor->set('summary', $long.'Thêm')->call('save')->assertHasErrors(['summary' => 'max']);
            expect($product->fresh()->translationFor('vi')?->summary)->toBe($long);
        });
    });

    it('shows an older formatted summary as plain text and keeps the stored markup until it is changed', function (): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $html = '<p>Teaser <strong>ngắn</strong> &amp; gọn</p><p>Dòng hai</p>';
            $product = shellProduct('draft', ['summary' => $html]);

            $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
                ->assertSet('summary', "Teaser ngắn & gọn\nDòng hai")
                ->assertSee('Tóm tắt cũ có định dạng');

            $editor->call('save')->assertHasNoErrors();
            expect($product->fresh()->translationFor('vi')?->summary)->toBe($html);

            $editor->set('summary', 'Teaser mới')->call('save')->assertHasNoErrors();
            expect($product->fresh()->translationFor('vi')?->summary)->toBe('Teaser mới');

            $inline = shellProduct('draft', ['slug' => 'do-ga-2', 'summary' => 'Bền &amp; <strong>nhẹ</strong>']);
            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $inline->ulid])
                ->assertSet('summary', 'Bền & nhẹ')
                ->assertSee('Tóm tắt cũ có định dạng');
        });
    });
});

describe('authorization', function (): void {
    it('refuses every action once the member may no longer manage products', function (string $step): void {
        $user = shellManager();

        app(Tenancy::class)->enter(1, function () use ($user, $step): void {
            $product = shellProduct('draft');
            $media = Media::query()->create([
                'collection' => 'library', 'slug' => 'anh-x', 'hash' => str_repeat('a', 64),
                'storage_key' => 's/library/anh-x.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
            ]);
            ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $media->id, 'position' => 0]);
            $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])->set('name', 'Đồ gá khác');

            $user->roles()->firstOrFail()->revokePermissionTo('catalog.manage');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            auth()->setUser($user->fresh());

            $answer = match ($step) {
                'save' => $editor->call('save'),
                'publish' => $editor->call('publish'),
                'autosave' => $editor->call('autosave'),
                'resetChanges' => $editor->call('resetChanges'),
                'removeImage' => $editor->call('removeImage', 0),
                'moveImageUp' => $editor->call('moveImageUp', 0),
                'moveImageDown' => $editor->call('moveImageDown', 0),
                'categoryId' => $editor->set('categoryId', 1),
            };

            $answer->assertForbidden();
            expect($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn');
        });
    })->with(['save', 'publish', 'autosave', 'resetChanges', 'removeImage', 'moveImageUp', 'moveImageDown', 'categoryId']);
});
