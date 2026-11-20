<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Catalog\Index as ProductIndex;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A slug field emptied and committed (Enter, or leaving the field) is made again from the title or name
 * of that language, as a new item gets its link: same Slugger, same suffix when taken, same reserved
 * checks. A title with nothing usable gives the error and saves nothing; an empty slug is never stored.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function esPage(User $author, string $title, string $slug, string $status = 'draft'): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $entry;
}

function esProduct(string $name, string $slug): Product
{
    $product = Product::query()->create(['status' => 'draft']);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product;
}

function esEditor(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'catalog.view', 'catalog.manage']);
    });

    return $user->fresh();
}

it('rebuilds an emptied list slug from the title, in a free form, and keeps a live page redirecting', function (): void {
    $editor = esEditor();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $page = esPage($editor, 'Hôm nay có gì vui không?', 'cu', 'draft');
        $live = esPage($editor, 'Giới thiệu', 'khac', 'draft');
        app(PublishEntry::class)->handle($live->fresh(), 'vi', $editor);

        $component = Livewire::actingAs($editor)->test(Index::class)->call('saveSlug', $page->ulid, '   ');
        expect($page->translationFor('vi')->fresh()->slug)->toBe('hom-nay-co-gi-vui-khong');

        $component->call('saveSlug', $live->ulid, '');
        expect($live->translationFor('vi')->fresh()->slug)->toBe('gioi-thieu')
            ->and(SiteRoute::query()->where('entry_id', $live->id)->value('path'))->toBe('/gioi-thieu')
            ->and(Redirect::query()->where('from_path', '/khac')->where('to_path', '/gioi-thieu')->exists())->toBeTrue();
    });
});

it('puts the suffix on a rebuilt list slug another page holds, but not for a page of another website', function (): void {
    $editor = esEditor();
    app(Tenancy::class)->enter(2, function () use ($editor): void {
        esPage($editor, 'Liên hệ', 'lien-he'); // site B: must not count
    });

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $mine = esPage($editor, 'Liên hệ', 'cu');
        $first = Livewire::actingAs($editor)->test(Index::class)->call('saveSlug', $mine->ulid, '');
        expect($mine->translationFor('vi')->fresh()->slug)->toBe('lien-he');

        $other = esPage($editor, 'Liên hệ', 'cu-2');
        $first->call('saveSlug', $other->ulid, '');
        expect($other->translationFor('vi')->fresh()->slug)->toBe('lien-he-2');
    });
});

it('refuses an emptied list slug when the title cannot give one, and saves nothing', function (): void {
    $editor = esEditor();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $page = esPage($editor, 'រូបភាពផលិតផល', 'trang');
        Livewire::actingAs($editor)->test(Index::class)->call('saveSlug', $page->ulid, '')
            ->assertDispatched('bs-toast', text: __('entries.slug_untranslatable'));

        expect($page->translationFor('vi')->fresh()->slug)->toBe('trang');
    });
});

it('rebuilds an emptied product list address from the name, with the suffix, and refuses an unusable name', function (): void {
    $editor = esEditor();

    app(Tenancy::class)->enter(2, function (): void {
        esProduct('Dao phay', 'dao-phay'); // site B: must not count
    });

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $product = esProduct('Dao phay', 'cu');
        $test = Livewire::actingAs($editor)->test(ProductIndex::class)
            ->call('saveSlug', $product->ulid, ' ')
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['slug'] === 'dao-phay');

        $second = esProduct('Dao phay', 'cu-2');
        $test->call('saveSlug', $second->ulid, '')
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['slug'] === 'dao-phay-2');

        $odd = esProduct('!!!', 'giu-nguyen');
        $test->call('saveSlug', $odd->ulid, '')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['error'] === __('catalog.slug_invalid'));
        expect($odd->translations()->value('slug'))->toBe('giu-nguyen');
    });
});

it('rebuilds the slug of the product form when the field is left empty, and says why when it cannot', function (): void {
    $editor = esEditor();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $product = esProduct('Đĩa phay mặt', 'cu');
        Livewire::actingAs($editor)->test(ProductEditor::class, ['ulid' => $product->ulid])
            ->set('slug', '')
            ->assertSet('slug', 'dia-phay-mat')
            ->assertDispatched('bs-toast', text: __('catalog.ed.slug_auto', ['slug' => 'dia-phay-mat']))
            ->set('name', '!!!')->set('slug', '')
            ->assertSet('slug', '')
            ->assertHasErrors(['slug']);
    });
});

it('tells the entry form why an emptied slug stays blank when the title cannot give one', function (): void {
    $editor = esEditor();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        Livewire::actingAs($editor)->test(EntryEditor::class)
            ->set('title', 'រូបភាពផលិតផល')->set('slug', 'tu-go')->set('slug', '')
            ->assertSet('slug', '')
            ->assertHasErrors(['slug']);
    });
});

it('makes an emptied category slug again from the name on save, and keeps it when it is left out', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin công ty'], slugs: ['vi' => 'cu']));

        app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(names: ['vi' => 'Tin công ty'], slugs: ['vi' => '']));
        expect($category->translationFor('vi')?->slug)->toBe('tin-cong-ty');

        // a slug left out of the data keeps the current one
        app(UpdateTaxonomy::class)->handle($category, new TaxonomyData(names: ['vi' => 'Tên khác']));
        expect($category->translationFor('vi')?->fresh()?->slug)->toBe('tin-cong-ty');
    });
});
