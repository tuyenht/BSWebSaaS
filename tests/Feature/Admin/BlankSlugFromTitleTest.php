<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * A blank link (slug) is made from the title/name in any writing system and made free with
 * -2, -3…; a link the person typed is kept as it is.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Inside a site context: the grants are team-scoped to that site. */
function slugEditor(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'catalog.manage']);
    });

    return $user->fresh();
}

it('makes a page link from a Vietnamese title', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Máy tiện CNC')
            ->set('slug', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'may-tien-cnc');

        expect(Entry::query()->first()->translationFor('vi')->slug)->toBe('may-tien-cnc');
    });
});

it('makes a page link from a Chinese title', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', '产品图片')
            ->set('slug', '  ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'chan-pin-tu-pian');
    });
});

it('appends -2 when the generated link is already taken, and keeps a typed link', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Máy tiện CNC')->call('save')->assertSet('slug', 'may-tien-cnc');

        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Máy tiện CNC')->call('save')->assertHasNoErrors()->assertSet('slug', 'may-tien-cnc-2');

        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Máy tiện CNC')->set('slug', 'tu-go')->call('save')->assertSet('slug', 'tu-go');
    });
});

it('keeps the link of an existing page when it is saved again', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $component = Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Liên hệ')->call('save')->assertSet('slug', 'lien-he');
        $component->set('title', 'Liên hệ mới')->call('save')->assertSet('slug', 'lien-he');
    });
});

it('asks for a link when the title has nothing usable', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', '!!! ???')->set('slug', '')
            ->call('save')
            ->assertHasErrors(['slug']);
    });
});

it('makes a product link from the name, free within the language', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(ProductEditor::class)
            ->set('name', 'Máy tiện CNC')->set('slug', '')->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'may-tien-cnc');

        Livewire::actingAs($user)->test(ProductEditor::class)
            ->set('name', 'Máy tiện CNC')->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'may-tien-cnc-2');

        Livewire::actingAs($user)->test(ProductEditor::class)
            ->set('name', '产品图片')->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'chan-pin-tu-pian');

        Livewire::actingAs($user)->test(ProductEditor::class)
            ->set('name', 'Bàn gá')->set('slug', 'ban-ga-tay')->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'ban-ga-tay');

        expect(Product::query()->count())->toBe(4);
    });
});
