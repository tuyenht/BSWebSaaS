<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A website's files are its customers' data: support staff signed in for a member never delete one
 * for good — not from «Thư viện ảnh & tệp»'s Trash (a selection or all of it), not from the image
 * picker's Trash, and not through any other path that force-deletes a file (MediaObserver refuses
 * it). The buttons stay, greyed, with the support sentence. Once the session ends, the member
 * deletes for good as usual.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
});

/** A trashed library file of site 1 with its stored object. */
function supportTrashedFile(string $name): Media
{
    return app(Tenancy::class)->enter(1, function () use ($name): Media {
        $media = Media::query()->create([
            'collection' => 'library', 'slug' => $name, 'hash' => hash('sha256', $name), 'disk' => 'r2',
            'storage_key' => "s1/library/{$name}.png", 'original_filename' => "{$name}.png", 'mime' => 'image/png', 'size' => 2048,
        ]);
        Storage::disk('r2')->put($media->storage_key, 'bytes');
        $media->delete();

        return $media;
    });
}

/** Hand Livewire's test requests (which skip the middleware) one session, as StartSession does, signed in as $user. */
function supportSessionFor(User $user): void
{
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($user);
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });
}

it('never lets support staff signed in for a member delete files for good from the media library\'s Trash', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'media-admin', ['media.view', 'media.upload', 'media.delete.any', 'media.purge']));
    $user = $user->fresh() ?? $user;
    $a = supportTrashedFile('a');
    $b = supportTrashedFile('b');
    supportSessionFor($user);

    app(Tenancy::class)->enter(1, function () use ($user, $a, $b): void {
        // the question was opened before support staff signed in (or a payload replays it): the answer is refused too
        $opened = Livewire::actingAs($user)->test(Library::class)->call('setFolder', '~trash')
            ->call('askPurge', true)->assertSet('askPurgeOpen', true);
        app(SupportSession::class)->start($user, $user, 1, 'Kiểm tra', now()->addMinutes(30));

        Livewire::actingAs($user)->test(Library::class)->call('setFolder', '~trash')
            ->call('togglePick', $a->ulid)
            ->assertSee(__('media.lib.purge_all'))
            ->assertSee(__('admin.support.locked'))
            ->assertDontSeeHtml('askPurge(')
            ->call('askPurge', false)->assertForbidden();
        $opened->call('confirmPurge')->assertForbidden();
        Livewire::actingAs($user)->test(Library::class)->set('picked', [$b->ulid])->call('confirmPurge')->assertForbidden();
        // every force delete of a file is refused, whoever asks
        expect(fn () => Media::onlyTrashed()->whereKey($a->id)->firstOrFail()->forceDelete())
            ->toThrow(AuthorizationException::class, __('admin.support.locked'));
    });

    expect(app(Tenancy::class)->enter(1, fn (): int => Media::onlyTrashed()->count()))->toBe(2);
    Storage::disk('r2')->assertExists(['s1/library/a.png', 's1/library/b.png']);

    // once the support session ends, the member deletes for good as usual
    app(SupportSession::class)->end();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Library::class)->call('setFolder', '~trash')
        ->assertSeeHtml('askPurge(true)')->call('askPurge', true)->call('confirmPurge'));
    expect(app(Tenancy::class)->enter(1, fn (): int => Media::withTrashed()->count()))->toBe(0);
    Storage::disk('r2')->assertMissing(['s1/library/a.png', 's1/library/b.png']);
});

it('never lets support staff signed in for a member delete files for good from the image picker\'s Trash', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'picker-admin', ['catalog.view', 'catalog.manage', 'media.view', 'media.delete.any', 'media.purge']));
    $user = $user->fresh() ?? $user;
    $a = supportTrashedFile('a');
    supportTrashedFile('b');
    supportSessionFor($user);

    app(Tenancy::class)->enter(1, function () use ($user, $a): void {
        // the Trash was opened before support staff signed in (or a payload replays it): its buttons are refused too
        $picked = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')->call('togglePickerItem', $a->id);
        $opened = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')->call('openPickerTrash');
        app(SupportSession::class)->start($user, $user, 1, 'Kiểm tra', now()->addMinutes(30));

        Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->call('openPickerTrash')->call('togglePickerItem', $a->id)
            ->assertSee(__('media.picker_trash_empty'))
            ->assertSee(__('admin.support.locked'))
            ->assertDontSeeHtml('$wire.purgePickerSelection()')
            ->assertDontSeeHtml('$wire.emptyPickerTrash()')
            ->call('purgePickerSelection')->assertForbidden();
        $picked->call('purgePickerSelection')->assertForbidden();
        $opened->call('emptyPickerTrash')->assertForbidden();
    });

    expect(app(Tenancy::class)->enter(1, fn (): int => Media::onlyTrashed()->count()))->toBe(2);

    app(SupportSession::class)->end();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery')
        ->call('openPickerTrash')->assertSeeHtml('$wire.emptyPickerTrash()')->call('emptyPickerTrash'));
    expect(app(Tenancy::class)->enter(1, fn (): int => Media::withTrashed()->count()))->toBe(0);
});
