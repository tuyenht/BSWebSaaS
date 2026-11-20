<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Slice 1 contract — a block image prop holds a media_id chosen via a picker (no free-text URL). The
 * editor's block image field opens the shared «Chọn ảnh» dialog in its single mode, which sets
 * blocks.<i>.props.<path> to a media_id from THIS site's library (RLS-scoped), works through a
 * repeater path (partners.logos[].image_url), and rejects a cross-site id injected via the wire payload.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function blockPickerMedia(string $key, string $hashChar): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat($hashChar, 64),
        'storage_key' => $key, 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
}

it('opens the block image picker for a target prop path', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = blockPickerMedia('a/library/x-aaaaaaaaaaaa.png', 'a');

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Hi', 'image_url' => null]]])
            ->assertSet('showImagePicker', false)
            ->call('openBlockImagePicker', '0.props.image_url')
            ->assertSet('showImagePicker', true)
            ->assertSet('imagePickerMode', 'single')
            ->assertSet('blockImagePickerPath', '0.props.image_url')
            ->assertSeeHtml("togglePickerItem({$mine->id})")   // the shared picker's grid renders, wired by id
            ->assertDontSeeHtml('class="media-picker"');        // the old unstyled picker is gone
    });
});

it('sets a block image prop to a media_id from this site, rejecting a cross-site id', function (): void {
    $editor = User::factory()->create();
    $foreign = app(Tenancy::class)->enter(2, fn (): Media => blockPickerMedia('b/library/z-cccccccccccc.png', 'c'));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreign): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = blockPickerMedia('a/library/x-aaaaaaaaaaaa.png', 'a');

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Hi', 'image_url' => null]]])
            ->call('openBlockImagePicker', '0.props.image_url')
            ->call('togglePickerItem', $mine->id)
            ->call('insertPicked')
            ->assertSet('blocks.0.props.image_url', $mine->id)
            ->assertSet('showImagePicker', false)
            ->call('openBlockImagePicker', '0.props.image_url')
            ->call('togglePickerItem', $foreign->id)
            ->assertSet('pickerSelected', [])                     // not this site's: never selected
            ->call('pickImage', $foreign->id)
            ->assertSet('blocks.0.props.image_url', $mine->id);   // cross-site id rejected → unchanged
    });
});

it('saves a page whose wire payload carries another site\'s or an unknown image id, recording usage for neither', function (): void {
    // The blocks property is client-owned, so the picker check alone does not stop an id typed into
    // the payload. The save must behave the same for both ids (no error that tells them apart) and
    // must not record another site's media in this site's usage index.
    $editor = User::factory()->create();
    $foreignId = app(Tenancy::class)->enter(2, fn (): int => (int) blockPickerMedia('b/library/z-cccccccccccc.png', 'c')->id);
    $unknownId = 9_000_000_000_000_000;

    app(Tenancy::class)->enter(1, function () use ($editor, $foreignId, $unknownId): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);

        foreach (['khac' => $foreignId, 'khong-co' => $unknownId] as $slug => $id) {
            Livewire::actingAs($editor->fresh())->test(Editor::class)
                ->set('title', 'Trang')
                ->set('slug', "trang-{$slug}")
                ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Hi', 'image_url' => $id]]])
                ->call('save')
                ->assertHasNoErrors();
        }
    });

    expect(DB::connection('pgsql_admin')->table('mediables')->whereIn('media_id', [$foreignId, $unknownId])->count())->toBe(0);
});

it('opens the block picture dialog only for an image field of a block that exists, and never takes a path from the wire', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = blockPickerMedia('a/library/x-aaaaaaaaaaaa.png', 'a');

        $test = Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Hi', 'image_url' => null]]]);
        foreach (['0.type', '9.props.image_url', '0.props', 'props.image_url', '0.props.image_url.x', "0.props.image_url\n", '00.props.image_url', '0.props.logos.3.image_url'] as $path) {
            $test->call('openBlockImagePicker', $path)
                ->assertSet('showImagePicker', false)
                ->assertSet('blockImagePickerPath', '');
        }
        expect(fn () => $test->set('blockImagePickerPath', '0.type'))->toThrow(CannotUpdateLockedPropertyException::class);

        $test->call('openImagePicker', 'single')->call('togglePickerItem', $mine->id)->call('insertPicked')
            ->assertSet('blocks.0.type', 'hero');

        // «Bỏ ảnh» of a block picture takes the same paths only
        $test->call('clearBlockImage', '0.type')->assertSet('blocks.0.type', 'hero')
            ->call('clearBlockImage', '5.props.image_url');
        expect($test->get('blocks'))->toHaveCount(1);
    });
});

it('sets an image inside a repeater row (partners.logos[].image_url)', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = blockPickerMedia('a/library/logo-aaaaaaaaaaaa.png', 'a');

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'partners', 'props' => ['logos' => [['image_url' => null, 'name' => 'A']]]]])
            ->call('openBlockImagePicker', '0.props.logos.0.image_url')
            ->call('togglePickerItem', $mine->id)
            ->call('insertPicked')
            ->assertSet('blocks.0.props.logos.0.image_url', $mine->id)
            ->call('openBlockImagePicker', '0.props.logos.3.image_url')   // the list has one row
            ->assertSet('showImagePicker', false)
            ->call('clearBlockImage', '0.props.logos.0.image_url')         // «Bỏ ảnh» of a real field works
            ->assertSet('blocks.0.props.logos.0.image_url', null);
    });
});

it('renders a picker button (not a text input) and a preview for a set media_id', function (): void {
    config(['media.driver' => 'static', 'media.cloudflare.base' => 'https://files.test']);
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = blockPickerMedia('a/library/x-aaaaaaaaaaaa.png', 'a');

        $component = Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['image_url' => null]]]);

        $component->assertSee(__('entries.image_pick'))
            ->assertSeeHtml("openBlockImagePicker('0.props.image_url')");

        // A set media_id renders a resolved preview <img> (one batched lookup), never a raw URL input.
        $component->set('blocks.0.props.image_url', $mine->id)
            ->assertSeeHtml('src="https://files.test/a/library/x-aaaaaaaaaaaa.png"');
    });
});
