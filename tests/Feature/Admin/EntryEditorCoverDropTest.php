<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * «Kéo thả ảnh vào đây hoặc bấm để chọn» on an empty featured-image box (design 07): pictures dropped
 * there go up through the cover's «Chọn ảnh», opened on its device upload (the browser hands it the
 * files), and the member picks the cover from the library as after any upload. Only a member who may
 * add files is offered the drop, and the method asks again (editing the record is asked on mount and again by it).
 */

beforeEach(function (): void {
    app()->setLocale('vi');
});

/** A member on site 1 who edits every post and page, with $extra rights. */
function coverDropMember(array $extra): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $extra): void {
        grantRole($user, 'cover-drop-'.md5(implode(',', $extra)), array_merge(['entries.view', 'entries.create', 'entries.edit.any'], $extra));
    });

    return $user->fresh();
}

function coverDropPage(): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Xưởng', 'slug' => 'xuong', 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page;
}

it('offers the drop on the empty box to a member who may add files, and opens the picker on its device upload', function (): void {
    $member = coverDropMember(['media.view', 'media.upload']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        $page = coverDropPage();
        $c = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid]);
        $html = $c->html();

        expect($html)->toContain('Kéo thả ảnh vào đây hoặc bấm để chọn')
            ->toContain('x-on:drop.prevent=')
            ->toContain('$wire.dropCover()')
            ->toContain("if (window.bsStagePickerFiles(\$event.dataTransfer?.files, 'image/'))")
            // a refusal or a failed call lets the kept files go
            ->toContain('$wire.dropCover().then((ok) => ok || (window.__pendingPickerFiles = null), () => (window.__pendingPickerFiles = null))');

        $c->call('dropCover')
            ->assertReturned(true)
            ->assertSet('showImagePicker', true)
            ->assertSet('imagePickerMode', 'single')
            ->assertSet('pickerTab', 'upload')
            ->assertSet('pickerSource', 'device')
            ->assertSet('blockImagePickerPath', '');
    });
});

it('keeps the box a plain «Chọn ảnh» for a member who may only pick from the library, and refuses the drop', function (): void {
    $member = coverDropMember(['media.view']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        $page = coverDropPage();
        $c = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid]);

        expect($c->html())->toContain('Chọn ảnh')
            ->not->toContain('Kéo thả ảnh vào đây')
            ->not->toContain('$wire.dropCover()');
        $c->call('dropCover')->assertForbidden();
    });
});

it('leaves the picker shut in another language: the cover is every language\'s and is changed in the source', function (): void {
    $member = coverDropMember(['media.view', 'media.upload']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
        $page = coverDropPage();
        $c = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en']);
        // the box offers no drop there (its «Chọn ảnh» says where the cover is changed)
        expect($c->html())->toContain('data-cover-pick')
            ->not->toContain('Kéo thả ảnh vào đây')
            ->not->toContain('$wire.dropCover()');
        $c->call('dropCover')
            ->assertReturned(false)
            ->assertSet('showImagePicker', false)
            ->assertDispatched('bs-toast');
    });
});

it('refuses the drop from a member who may add files but no longer may edit the entry, and opens nothing', function (): void {
    $member = coverDropMember(['media.view', 'media.upload']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        $page = coverDropPage();
        $c = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid]);

        // the right to edit goes while the form is open: the method asks again
        $member->roles()->firstOrFail()->revokePermissionTo('entries.edit.any');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c->call('dropCover')->assertForbidden()
            ->assertSet('showImagePicker', false)
            ->assertSet('coverUlid', '');
        expect($page->fresh()->cover_media_id ?? null)->toBeNull();
    });
});
