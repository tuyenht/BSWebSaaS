<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Slice 1 contract — a block image prop holds a media_id chosen via a picker (no free-text URL). The
 * editor's block image picker sets blocks.<i>.props.<path> to a media_id from THIS site's library
 * (RLS-scoped), works through a repeater path (partners.logos[].image_url), and rejects a cross-site
 * id injected via the wire payload.
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

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->assertSet('showBlockImagePicker', false)
            ->call('openBlockImagePicker', '0.props.image_url')
            ->assertSet('showBlockImagePicker', true)
            ->assertSet('blockImagePickerPath', '0.props.image_url');
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
            ->call('selectBlockImage', $mine->id)
            ->assertSet('blocks.0.props.image_url', $mine->id)
            ->assertSet('showBlockImagePicker', false)
            ->call('openBlockImagePicker', '0.props.image_url')
            ->call('selectBlockImage', $foreign->id)
            ->assertSet('blocks.0.props.image_url', $mine->id);   // cross-site id rejected → unchanged
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
            ->call('selectBlockImage', $mine->id)
            ->assertSet('blocks.0.props.logos.0.image_url', $mine->id);
    });
});
