<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
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

it('refuses a member who may only see products, and another language than the default, on the server', function (): void {
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
