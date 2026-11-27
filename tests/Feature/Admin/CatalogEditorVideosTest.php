<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;

/*
 * YouTube videos on the product editor (board 09 «Sửa sản phẩm»): a list in products.meta.videos,
 * each `{yt: 11-character id, title}`; only the id is stored, never the link. The first picture stays
 * the cover and the videos come right after it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function pvdMember(array $permissions = ['catalog.view', 'catalog.manage', 'media.view']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-'.uniqid(), $permissions));

    return $user->fresh() ?? $user;
}

/** A saved product with a Vietnamese version, a category and the given number of pictures. */
function pvdProduct(int $pictures = 2, array $meta = []): Product
{
    $product = Product::query()->create(['status' => 'draft', 'price' => 1000, 'meta' => $meta]);
    $product->translations()->create(['locale' => 'vi', 'name' => 'Mũi khoan', 'slug' => 'mui-khoan']);
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Dụng cụ', 'slug' => 'dung-cu']);
    $product->assignCategories([$category->id]);
    for ($i = 0; $i < $pictures; $i++) {
        $media = Media::query()->create(['collection' => 'library', 'slug' => "pvd-{$i}", 'hash' => hash('sha256', "pvd{$i}"), 'storage_key' => "s/library/pvd-{$i}.png",
            'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024]);
        $product->images()->create(['media_id' => $media->id, 'position' => $i]);
    }

    return $product;
}

const PVD_ID = 'dQw4w9WgXcQ';

/** A picture in the Library, not yet on any product. */
function pvdMedia(): Media
{
    $key = uniqid('pvdm-');

    return Media::query()->create(['collection' => 'library', 'slug' => $key, 'hash' => hash('sha256', $key), 'storage_key' => "s/library/{$key}.png",
        'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024]);
}

/** The value of `$attr` on the tag that carries `$marker`, as the editor drew it; null when no such tag. */
function pvdAttr(string $html, string $marker, string $attr): ?string
{
    if (preg_match('~<[a-z]+\b(?=[^>]*\s'.preg_quote($marker, '~').'[\s>=])[^>]*>~', $html, $tag) !== 1) {
        return null;
    }

    return preg_match('~\s'.preg_quote($attr, '~').'="([^"]*)"~', $tag[0], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : null;
}

it('stores the 11-character id of every accepted YouTube link shape, never the link', function (string $url): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user, $url): void {
        $product = pvdProduct();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', $url, '  Cắt thử thép  ')
            ->assertReturned(true)
            ->assertSet('videos', [['yt' => PVD_ID, 'title' => 'Cắt thử thép']])
            ->call('save')->assertHasNoErrors();

        $meta = Product::query()->whereKey($product->id)->value('meta');
        expect(json_decode((string) json_encode($meta), true)['videos'])->toBe([['yt' => PVD_ID, 'title' => 'Cắt thử thép']])
            ->and((string) json_encode($meta))->not->toContain('http')->not->toContain('youtube');
    });
})->with([
    'watch' => 'https://www.youtube.com/watch?v='.PVD_ID,
    'watch with other params' => 'https://youtube.com/watch?list=PLx&v='.PVD_ID.'&t=5s',
    'mobile' => 'https://m.youtube.com/watch?v='.PVD_ID,
    'short link' => 'https://youtu.be/'.PVD_ID.'?si=abc',
    'shorts' => 'https://www.youtube.com/shorts/'.PVD_ID,
    'embed' => 'www.youtube.com/embed/'.PVD_ID,
]);

it('refuses a link that is not one video of YouTube, with the board\'s words, and stores nothing', function (string $url): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user, $url): void {
        $product = pvdProduct();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', $url, '')
            ->assertReturned(false)
            ->assertSet('videos', [])
            ->assertSet('videoError', __('catalog.ed.video_bad_link'));
    });
})->with([
    'other host' => 'https://vimeo.com/123456789',
    'lookalike host' => 'https://www.youtube.com.evil.test/watch?v='.PVD_ID,
    'javascript' => 'javascript:alert(1)//youtube.com/watch?v='.PVD_ID,
    'playlist only' => 'https://www.youtube.com/playlist?list=PLabcdefghijklmnop',
    'short id' => 'https://youtu.be/abc',
    'long id' => 'https://youtu.be/'.PVD_ID.'xx',
    'garbage' => 'hello',
    'empty' => '',
]);

it('keeps the first picture as the cover: no video without a picture, no removing the last picture under a video', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $none = pvdProduct(0);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $none->ulid])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')->assertReturned(false)->assertSet('videos', [])
            ->assertSet('toastMsg', __('catalog.ed.video_need_image'));

        $one = pvdProduct(1);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $one->ulid])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')->assertReturned(true)
            ->call('removeImage', 0)
            ->assertCount('imageMediaIds', 1)
            ->assertSet('toastMsg', __('catalog.ed.video_keep_image'));
    });
});

it('refuses a link already on the product and a twenty-first video', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct();
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')->assertReturned(true)
            ->call('addVideo', 'https://www.youtube.com/watch?v='.PVD_ID, 'Lại')->assertReturned(false)
            ->assertSet('videoError', __('catalog.ed.video_dup'))
            ->assertCount('videos', 1);
        for ($i = 1; $i < 20; $i++) {
            $c->call('addVideo', 'https://youtu.be/'.str_pad((string) $i, 11, 'a', STR_PAD_LEFT), '')->assertReturned(true);
        }
        $c->assertCount('videos', 20)
            ->call('addVideo', 'https://youtu.be/zzzzzzzzzzz', '')->assertReturned(false)
            ->assertSet('videoError', __('catalog.ed.video_max', ['max' => 20]))
            ->assertCount('videos', 20);
    });
});

it('saves the videos in add order after a reload, keeps the product\'s other meta keys, and shows them right after the cover', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(2, ['note' => 'keep me']);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', 'https://youtu.be/aaaaaaaaaaa', 'Một')
            ->call('addVideo', 'https://youtu.be/bbbbbbbbbbb', 'Hai')
            ->call('save')->assertHasNoErrors();

        $meta = (array) Product::query()->whereKey($product->id)->value('meta');
        expect($meta['note'])->toBe('keep me')
            ->and($meta['videos'])->toBe([['yt' => 'aaaaaaaaaaa', 'title' => 'Một'], ['yt' => 'bbbbbbbbbbb', 'title' => 'Hai']]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('videos', $meta['videos'])
            ->assertSet('changes', 0)
            ->assertSeeInOrder(['data-img-i="0"', 'data-vid-i="0"', 'data-vid-i="1"', 'data-img-i="1"'], false)
            ->call('removeVideo', 0)->call('removeVideo', 0)->call('save')->assertHasNoErrors();
        expect((array) Product::query()->whereKey($product->id)->value('meta'))->toBe(['note' => 'keep me']);
    });
});

it('takes a video off with «Hoàn tác» that puts it back where it stood', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct();
        $videos = [['yt' => 'aaaaaaaaaaa', 'title' => 'Một'], ['yt' => 'bbbbbbbbbbb', 'title' => 'Hai'], ['yt' => 'ccccccccccc', 'title' => '']];
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', 'https://youtu.be/aaaaaaaaaaa', 'Một')
            ->call('addVideo', 'https://youtu.be/bbbbbbbbbbb', 'Hai')
            ->call('addVideo', 'https://youtu.be/ccccccccccc', '')
            ->call('removeVideo', 1)
            ->assertSet('videos', [$videos[0], $videos[2]])
            ->assertSet('undo', 'video')
            ->call('undoLast')
            ->assertSet('videos', $videos);
    });
});

it('refuses a member who may only see products on the server', function (): void {
    $viewer = pvdMember(['catalog.view', 'media.view']);
    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        $product = pvdProduct(1, ['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => '']]]);
        $open = fn () => Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $product->ulid]);
        $open()->assertSet('canEdit', false);
        $open()->call('addVideo', 'https://youtu.be/'.PVD_ID, '')->assertForbidden();
        $open()->call('removeVideo', 0)->assertForbidden();
        expect(fn () => $open()->set('videos', []))->toThrow(Exception::class);
        expect((array) Product::query()->whereKey($product->id)->value('meta'))->toBe(['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => '']]]);
    });
});

it('drops a stored or sent entry that is not an id, keeping the rest', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(1, ['videos' => [['yt' => 'javascript:x', 'title' => 'x'], ['yt' => 'aaaaaaaaaaa', 'title' => 'ok'], ['yt' => 'aaaaaaaaaaa', 'title' => 'again']]]);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('videos', [['yt' => 'aaaaaaaaaaa', 'title' => 'ok']]);
    });
});

it('refuses to add or remove a video in another language than the default, and keeps the stored list', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        SiteLocale::query()->updateOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLanguages::class)->forget();
        $videos = [['yt' => 'aaaaaaaaaaa', 'title' => 'Một'], ['yt' => 'bbbbbbbbbbb', 'title' => 'Hai']];
        $product = pvdProduct(2, ['videos' => $videos]);
        $product->translations()->create(['locale' => 'en', 'name' => 'Drill bit', 'slug' => 'drill-bit']);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->assertSet('videos', $videos)
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, 'Mới')->assertReturned(false)->assertDispatched('bs-toast')
            ->assertSet('videos', $videos)
            ->call('removeVideo', 0)->assertDispatched('bs-toast')->assertSet('videos', $videos)->assertSet('undo', null)
            ->set('name', 'Drill bit 2')->call('save')->assertHasNoErrors();

        expect((array) Product::query()->whereKey($product->id)->value('meta'))->toBe(['videos' => $videos]);
    });
});

it('never empties the pictures under a video: a save without any picture is refused and keeps them', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(2, ['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => '']]]);
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('imageMediaIds', [])->call('save')->assertHasErrors(['imageMediaIds']);
        expect($product->images()->count())->toBe(2);
    });
});

it('keeps the videos visible and removable after their last picture is deleted from the Library', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(1, ['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => 'Một'], ['yt' => 'bbbbbbbbbbb', 'title' => '']], 'note' => 'keep me']);
        Media::query()->whereKey($product->images()->value('media_id'))->forceDelete();
        expect($product->images()->count())->toBe(0);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('imageMediaIds', [])
            ->assertSeeHtml('data-vid-i="0"')
            ->assertDontSeeHtml('class="ped-imempty"')
            // a change to the videos still leaving some with no picture is the user's own: the save asks for a
            // picture first (or the videos gone), keeping what was typed
            ->set('name', 'Mũi khoan 2')->call('removeVideo', 0)->call('save')->assertHasErrors(['imageMediaIds'])
            ->assertSee(__('catalog.ed.video_no_image'))->assertDontSee(__('catalog.ed.video_stored_no_image'))
            ->assertSet('name', 'Mũi khoan 2')->assertCount('videos', 1)
            ->call('removeVideo', 0)->assertSet('videos', [])->assertHasNoErrors(['imageMediaIds'])->assertSeeHtml('class="ped-imempty"')
            ->call('save')->assertHasNoErrors();

        expect((array) Product::query()->whereKey($product->id)->value('meta'))->toBe(['note' => 'keep me'])
            ->and($product->translations()->value('name'))->toBe('Mũi khoan 2');
    });
});

it('saves an unrelated change of a product whose last picture the Library deleted, and asks for a picture in the gallery card', function (string $how): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user, $how): void {
        $videos = [['yt' => 'aaaaaaaaaaa', 'title' => 'Một']];
        $product = pvdProduct(1, ['videos' => $videos, 'note' => 'keep me']);
        Media::query()->whereKey($product->images()->value('media_id'))->forceDelete();

        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSee(__('catalog.ed.video_stored_no_image'))
            ->set('price', '2500')->call($how)->assertHasNoErrors()
            // still stored that way: the card keeps asking
            ->assertSee(__('catalog.ed.video_stored_no_image'))->assertCount('videos', 1);
        $stored = Product::query()->whereKey($product->id)->firstOrFail();
        expect($stored->price)->toBe(2500)
            ->and($stored->meta['videos'] ?? null)->toBe($videos)
            ->and($stored->meta['note'] ?? null)->toBe('keep me')
            ->and($stored->images()->count())->toBe(0);
        if ($how === 'publish') {
            expect($stored->status)->toBe('published');
        }
    });
})->with(['save', 'publish', 'autosave']);

it('keeps the picture added to a product whose last picture the Library deleted while its videos stay', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(1, ['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => '']]]);
        Media::query()->whereKey($product->images()->value('media_id'))->forceDelete();
        $picture = pvdMedia();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSee(__('catalog.ed.video_stored_no_image'))
            ->set('imageMediaIds', [$picture->id])->assertDontSee(__('catalog.ed.video_stored_no_image'))
            // the only picture is now the cover under a video: it stays
            ->call('removeImage', 0)->assertSet('imageMediaIds', [$picture->id])->assertSet('toastMsg', __('catalog.ed.video_keep_image'))
            ->call('save')->assertHasNoErrors();
        expect($product->images()->pluck('media_id')->all())->toBe([$picture->id]);
    });
});

it('says where focus goes after a video is added, taken off or put back', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('addVideo', 'https://youtu.be/aaaaaaaaaaa', '')->assertDispatched('ped-vfocus', i: 0)
            ->call('addVideo', 'https://youtu.be/bbbbbbbbbbb', '')->assertDispatched('ped-vfocus', i: 1)
            ->call('removeVideo', 1)->assertDispatched('ped-vfocus', i: 0)
            ->call('undoLast')->assertDispatched('ped-vfocus', i: 1)
            ->call('removeVideo', 1)->call('removeVideo', 0)->assertDispatched('ped-vfocus', i: -1);
    });
});

it('checks the link when the user leaves the field, and clears the message for a good or empty one', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('checkVideoLink', 'hello')->assertSet('videoError', __('catalog.ed.video_bad_link'))
            ->call('checkVideoLink', 'https://youtu.be/'.PVD_ID)->assertSet('videoError', '')
            ->call('checkVideoLink', 'hello')->call('checkVideoLink', '  ')->assertSet('videoError', '');
        $viewer = pvdMember(['catalog.view', 'media.view']);
        Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $product->ulid])->call('checkVideoLink', 'x')->assertForbidden();
    });
});

it('draws the strip as the board does: its words, the add group, the YouTube mark and the disabled moves', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(2, ['videos' => [['yt' => 'aaaaaaaaaaa', 'title' => 'Một']]]);
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSee(__('catalog.ed.img_strip'))->assertSee('role="group" aria-labelledby="ped-yt-h"', false)
            ->assertSee('aria-describedby="ped-yt-o"', false)->assertSee(__('catalog.ed.img_add_media_why'), false)
            ->assertSee('x-on:blur="$wire.checkVideoLink(url)"', false);
        expect(__('catalog.ed.img_strip'))->toContain('video')
            ->and(file_get_contents(base_path('app/Modules/Admin/resources/views/components/icon.blade.php')))->toContain("'ri-youtube'");
    });
});

it('sizes the add-video row by the gallery card it sits in, as the board does', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/admin-editor.css'));
    expect($css)->toMatch('~\.ped-yt \{[^}]*container-type: inline-size~')
        ->and($css)->toContain('@container (min-width: 32.5rem) and (min-width: 29em) {')->not->toContain('@container (min-width: 40rem)');
});

it('never saves a new product with videos and no picture, by any save', function (string $how): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user, $how): void {
        $picture = pvdMedia();
        $c = Livewire::actingAs($user)->test(Editor::class)
            ->set('name', 'Dao phay')->set('slug', 'dao-phay')
            ->set('imageMediaIds', [$picture->id])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, 'Cắt thử')->assertReturned(true)
            ->set('imageMediaIds', [])
            ->call($how);
        expect(Product::query()->count())->toBe(0);
        // what was typed and added stays on the screen
        $c->assertSet('name', 'Dao phay')->assertCount('videos', 1);
        if ($how !== 'autosave') {
            $c->assertHasErrors(['imageMediaIds'])->assertSee(__('catalog.ed.video_no_image'));
        }
    });
})->with(['save', 'publish', 'autosave']);

it('never saves videos onto a product that was stored with no picture, after the picture added for them is taken away', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(0, ['note' => 'keep me']);
        $picture = pvdMedia();
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('imageMediaIds', [$picture->id])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')->assertReturned(true)
            ->set('imageMediaIds', [])
            ->call('save')->assertHasErrors(['imageMediaIds'])->assertCount('videos', 1);
        expect((array) Product::query()->whereKey($product->id)->value('meta'))->toBe(['note' => 'keep me']);
    });
});

it('does not put a video back with «Hoàn tác» once the pictures are gone', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $picture = pvdMedia();
        Livewire::actingAs($user)->test(Editor::class)
            ->set('name', 'Dao phay')->set('slug', 'dao-phay')
            ->set('imageMediaIds', [$picture->id])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')
            ->call('removeVideo', 0)->assertSet('undo', 'video')
            ->set('imageMediaIds', [])
            ->call('undoLast')->assertSet('videos', [])->assertSet('toastMsg', __('catalog.ed.video_need_image'))
            ->call('save')->assertHasNoErrors();
        expect(Product::query()->value('meta'))->not->toHaveKey('videos');
    });
});

it('does not put a video back with «Hoàn tác» after «Bỏ thay đổi» took the pictures of a new product', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $picture = pvdMedia();
        Livewire::actingAs($user)->test(Editor::class)
            ->set('imageMediaIds', [$picture->id])
            ->call('addVideo', 'https://youtu.be/'.PVD_ID, '')
            ->call('removeVideo', 0)
            ->call('resetChanges')->assertSet('imageMediaIds', [])->assertSet('undo', 'video')
            ->call('undoLast')->assertSet('videos', [])->assertSet('toastMsg', __('catalog.ed.video_need_image'))
            ->set('name', 'Dao phay')->set('slug', 'dao-phay')
            ->call('save')->assertHasNoErrors();
        expect(Product::query()->value('meta'))->not->toHaveKey('videos');
    });
});

it('refuses «Hoàn tác» on a video when another tab filled the list since and «Bỏ thay đổi» read it again', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $mine = array_map(static fn (int $i): array => ['yt' => str_pad((string) $i, 11, 'm', STR_PAD_LEFT), 'title' => ''], range(1, 20));
        $theirs = array_map(static fn (int $i): array => ['yt' => str_pad((string) $i, 11, 't', STR_PAD_LEFT), 'title' => ''], range(1, 20));
        $product = pvdProduct(1, ['videos' => $mine]);
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->call('removeVideo', 0)->assertSet('undo', 'video')->assertCount('videos', 19);
        // another tab saves twenty other videos meanwhile
        Product::query()->whereKey($product->id)->update(['meta' => json_encode(['videos' => $theirs])]);
        $c->call('resetChanges')->assertSet('videos', $theirs)
            ->call('undoLast')
            ->assertSet('videoError', __('catalog.ed.video_max', ['max' => 20]))
            ->assertSet('videos', $theirs);
    });
});

it('draws the picture toolbar\'s refusals from the pictures and videos of each render', function (): void {
    $user = pvdMember();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = pvdProduct(2);
        $c = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid]);
        $state = static function () use ($c): array {
            $html = $c->html();

            return [
                'up' => pvdAttr($html, 'data-ped-up', 'x-bind:aria-disabled'),
                'down' => pvdAttr($html, 'data-ped-down', 'x-bind:aria-disabled'),
                'rm' => pvdAttr($html, 'data-ped-rm', 'aria-disabled'),
                'rmWhy' => pvdAttr($html, 'data-ped-rm', 'title'),
                'pos' => str_contains($html, ".replace(':n', ".count($c->get('imageMediaIds')).')'),
            ];
        };
        $free = ['up' => '[0].includes(sel).toString()', 'down' => '[1].includes(sel).toString()', 'rm' => 'false', 'rmWhy' => __('catalog.ed.img_remove_why'), 'pos' => true];
        expect($state())->toBe($free);

        // a video after the cover: picture 2 no longer moves up, the cover no longer moves down
        $c->call('addVideo', 'https://youtu.be/aaaaaaaaaaa', '');
        $withVideo = ['up' => '[0,1].includes(sel).toString()', 'down' => '[1,0].includes(sel).toString()', 'rm' => 'false', 'rmWhy' => __('catalog.ed.img_remove_why'), 'pos' => true];
        expect($state())->toBe($withVideo);

        // picture 2 taken off: the last picture stays under the video, and says why
        $c->call('removeImage', 1);
        $keep = ['up' => '[0,1].includes(sel).toString()', 'down' => '[0].includes(sel).toString()', 'rm' => 'true', 'rmWhy' => __('catalog.ed.video_keep_image'), 'pos' => true];
        expect($state())->toBe($keep);

        // the video taken off: the last picture may go again
        $c->call('removeVideo', 0);
        expect($state())->toBe(['up' => '[0].includes(sel).toString()', 'down' => '[0].includes(sel).toString()', 'rm' => 'false', 'rmWhy' => __('catalog.ed.img_remove_why'), 'pos' => true]);

        // «Hoàn tác» puts the video back, and the last picture is kept again
        $c->call('undoLast');
        expect($state())->toBe($keep);

        // «Bỏ thay đổi»: back to the two stored pictures with no video
        $c->call('resetChanges');
        expect($state())->toBe($free);
    });
});
