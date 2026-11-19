<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * `catalog:normalize-skus` writes the stored SKUs in the editor's spelling (upper-case, spaces as «-»),
 * site by site; a code whose new spelling another product already holds, and one over 64 characters,
 * are listed and left alone; a second run changes nothing; --dry-run writes nothing.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function nskProduct(int $siteId, string $slug, ?string $sku, bool $trashed = false): int
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $sku, $trashed): int {
        $product = Product::query()->create(['status' => 'draft', 'sku' => $sku]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'SP '.$slug, 'slug' => $slug]);
        if ($trashed) {
            $product->delete();
        }

        return $product->id;
    });
}

function nskSku(int $siteId, int $id): ?string
{
    return app(Tenancy::class)->enter($siteId, fn () => Product::withTrashed()->whereKey($id)->value('sku'));
}

it('counts without writing on a dry run', function (): void {
    $a = nskProduct(1, 'a', 'ab 1');

    $this->artisan('catalog:normalize-skus', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: would change 1 SKU(s) on 2 site(s)')
        ->assertSuccessful();

    expect(nskSku(1, $a))->toBe('ab 1');
});

it('applies the editor spelling to every site, the Trash included, and leaves right codes and no code alone', function (): void {
    $a = nskProduct(1, 'a', 'ab 1');
    $b = nskProduct(2, 'b', 'cd-2');
    $c = nskProduct(1, 'c', 'ef 3', trashed: true);
    $ok = nskProduct(1, 'ok', 'OK-1');
    $none = nskProduct(1, 'none', null);

    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('Changed 3 SKU(s) on 2 site(s)')->assertSuccessful();

    expect(nskSku(1, $a))->toBe('AB-1')
        ->and(nskSku(2, $b))->toBe('CD-2')
        ->and(nskSku(1, $c))->toBe('EF-3')
        ->and(nskSku(1, $ok))->toBe('OK-1')
        ->and(nskSku(1, $none))->toBeNull();
});

it('does not change a code whose new spelling another product of the site holds, and lists it', function (): void {
    $keep = nskProduct(1, 'giu', 'AB-1', trashed: true);
    $clash = nskProduct(1, 'trung', 'ab-1');
    // the same code in another site is no clash
    $other = nskProduct(2, 'khac', 'ab-1');

    $this->artisan('catalog:normalize-skus')
        ->expectsOutputToContain('site 1: clash, not changed: product trung ("ab-1")')
        ->expectsOutputToContain('Changed 1 SKU(s) on 2 site(s); 1 clash(es)')
        ->assertSuccessful();

    expect(nskSku(1, $keep))->toBe('AB-1')
        ->and(nskSku(1, $clash))->toBe('ab-1')
        ->and(nskSku(2, $other))->toBe('AB-1');
});

it('lets the lower id win when two rows of one run would end up the same', function (): void {
    $first = nskProduct(1, 'mot', 'x 1');
    $second = nskProduct(1, 'hai', 'X-1x');
    $third = nskProduct(1, 'ba', 'x-1');

    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('site 1: clash, not changed: product ba ("x-1")')->assertSuccessful();

    expect(nskSku(1, $first))->toBe('X-1')->and(nskSku(1, $second))->toBe('X-1X')->and(nskSku(1, $third))->toBe('x-1');
});

it('lists a code longer than 64 and does not cut it', function (): void {
    $long = str_repeat('a', 65);
    $id = nskProduct(1, 'dai', $long);

    $this->artisan('catalog:normalize-skus')
        ->expectsOutputToContain('site 1: longer than 64, not changed: product dai ("'.$long.'")')
        ->assertSuccessful();

    expect(nskSku(1, $id))->toBe($long);
});

it('changes nothing on a second run', function (): void {
    nskProduct(1, 'a', 'ab 1');
    nskProduct(1, 'b', 'ab-1');

    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('Changed 1 SKU(s)')->assertSuccessful();
    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('Changed 0 SKU(s) on 2 site(s); 1 clash(es)')->assertSuccessful();
});

it('makes the editor say the code is taken once the old row is in the new spelling', function (): void {
    $editor = User::factory()->create();
    nskProduct(1, 'cu', 'ab-1');

    $this->artisan('catalog:normalize-skus')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Dao phay')
            ->set('slug', 'dao-phay')
            ->set('sku', 'AB-1')
            ->call('save')
            ->assertHasErrors(['sku' => __('catalog.ed.sku_taken_named', ['sku' => 'AB-1', 'name' => 'SP cu'])]);
    });
});

it('reports the same clashes on a dry run as a real run does, and leaves the edit time alone', function (): void {
    $first = nskProduct(1, 'mot', 'x 1');
    nskProduct(1, 'ba', 'x-1');
    $before = app(Tenancy::class)->enter(1, fn () => Product::query()->whereKey($first)->value('updated_at'));

    $this->artisan('catalog:normalize-skus', ['--dry-run' => true])->expectsOutputToContain('would change 1 SKU(s) on 2 site(s); 1 clash(es)')->assertSuccessful();
    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('Changed 1 SKU(s) on 2 site(s); 1 clash(es)')->assertSuccessful();

    expect(app(Tenancy::class)->enter(1, fn () => Product::query()->whereKey($first)->value('updated_at')))->toEqual($before);
});

it('keeps the search words of a changed code', function (): void {
    $id = nskProduct(1, 'a', 'zz 9');

    $this->artisan('catalog:normalize-skus')->assertSuccessful();

    $words = DB::connection('pgsql_admin')->table('product_search_words')->where('product_id', $id)->get()->map(fn ($r) => json_encode($r))->implode('');
    expect($words)->toContain('zz');
});

it('clears a code made only of spaces instead of writing an empty one', function (): void {
    $a = nskProduct(1, 'a', '   ');
    $b = nskProduct(1, 'b', '  ');

    $this->artisan('catalog:normalize-skus')->expectsOutputToContain('Changed 2 SKU(s)')->assertSuccessful();

    expect(nskSku(1, $a))->toBeNull()->and(nskSku(1, $b))->toBeNull();
});
