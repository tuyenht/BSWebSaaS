<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * The product code (SKU) is written as the editor board draws it: upper-case, every run of spaces a
 * «-», at most 64 characters. The server applies the same spelling on save and when it looks for
 * another product holding the code (exact match, so the (site_id, sku) index serves it).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('stores a typed code upper-cased with spaces as hyphens', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', '  ab 1  c ')
            ->call('save')
            ->assertHasNoErrors();

        expect(Product::query()->value('sku'))->toBe('AB-1-C');
    });
});

it('shows the normalised code in the field when it is left', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())->test(Editor::class)->set('sku', 'ab 1')->assertSet('sku', 'AB-1');
    });
});

it('refuses a code longer than 64 instead of cutting it', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', str_repeat('a', 65))
            ->call('save')
            ->assertHasErrors(['sku']);

        expect(Product::query()->count())->toBe(0);
    });
});

it('says the other product holds the code whatever case it was typed in, once the stored code is upper-case', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $other = Product::query()->create(['status' => 'draft', 'sku' => 'AB-1']);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Mũi khoan', 'slug' => 'mui-khoan']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', 'ab 1')
            ->call('save')
            ->assertHasErrors(['sku' => __('catalog.ed.sku_taken_named', ['sku' => 'AB-1', 'name' => 'Mũi khoan'])]);

        expect(Product::query()->count())->toBe(1);
    });
});

it('names the Trash when the product holding the code is in it', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $other = Product::query()->create(['status' => 'draft', 'sku' => 'AB-1']);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Mũi khoan', 'slug' => 'mui-khoan']);
        $other->delete();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', 'ab-1')
            ->call('save')
            ->assertHasErrors(['sku' => __('catalog.ed.sku_taken_trash', ['sku' => 'AB-1', 'name' => 'Mũi khoan'])]);
    });
});

it('does not fail with a server error on a code an old lower-case row spells differently: the index compares exactly', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);
        // a row written before the editor normalised codes
        Product::query()->create(['status' => 'draft', 'sku' => 'ab-1']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', 'AB-1')
            ->call('save')
            ->assertHasNoErrors();

        // the old row is only found once catalog:normalize-skus has run (see its test)
        expect(Product::query()->orderBy('id')->pluck('sku')->all())->toBe(['ab-1', 'AB-1']);
    });
});

it('still refuses a member with view only: the editor cannot be opened and nothing is written', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        Livewire::actingAs($viewer->fresh())->test(Editor::class)->assertForbidden();

        expect(Product::query()->count())->toBe(0);
    });
});
