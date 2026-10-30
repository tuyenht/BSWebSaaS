<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\DB;
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
        grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'catalog.manage']);
    });

    return $user->fresh();
}

/** Turns extra content languages on for site 1. */
function slugLocales(string ...$locales): void
{
    foreach ($locales as $locale) {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => $locale, 'is_default' => false, 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** A `posts` section bound to a fresh category; returns the category id. */
function slugPostSection(): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin', 'slug' => 'tin']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);

    return $category->id;
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

it('asks for a link when a Japanese title has kanji, instead of a Chinese reading', function (): void {
    $user = slugEditor();
    slugLocales('ja');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class, ['locale' => 'ja'])
            ->set('title', '会社案内')->set('slug', '')
            ->call('save')
            ->assertHasErrors(['slug'])
            ->assertSet('slug', '');

        expect(EntryTranslation::query()->count())->toBe(0);
    });
});

it('makes a link in a non-default language', function (): void {
    $user = slugEditor();
    slugLocales('en');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class, ['locale' => 'en'])
            ->set('title', 'Máy tiện CNC')->set('slug', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'may-tien-cnc');

        expect(EntryTranslation::query()->where('locale', 'en')->count())->toBe(1);
    });
});

it('does not add -2 for a title another website already uses', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(2, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'body' => ['blocks' => []], 'status' => 'draft']);
    });

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Liên hệ')->set('slug', '')->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'lien-he');
    });
});

it('makes a post link from the title', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        slugPostSection();

        Livewire::actingAs($user)->test(EntryEditor::class, ['section' => 'posts'])
            ->set('title', 'Khai trương xưởng mới')->set('slug', '')
            ->call('save')
            ->assertHasNoErrors()->assertSet('slug', 'khai-truong-xuong-moi');

        expect(Entry::query()->where('type', 'post')->count())->toBe(1);
    });
});

it('leaves the link blank when the save fails validation, so a later title change still works', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        slugPostSection();

        $editor = Livewire::actingAs($user)->test(EntryEditor::class, ['section' => 'posts'])
            ->set('title', 'Khai trương xưởng mới')->set('slug', '')->set('categoryId', null)
            ->call('save')
            ->assertHasErrors(['categoryId'])
            ->assertSet('slug', '');
        expect(Entry::query()->count())->toBe(0);

        // The title changes and a category is picked: the link follows the new title.
        $editor->set('title', 'Xưởng mới')->set('categoryId', Taxonomy::query()->first()->id)
            ->call('save')->assertHasNoErrors()->assertSet('slug', 'xuong-moi');
    });
});

it('treats a path owned by a category as taken when a page title would collide', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');

        $editor = Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Tin tức')->set('slug', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'tin-tuc-2');

        $editor->call('publish')->assertHasNoErrors();
        expect(SiteRoute::query()->where('path', '/tin-tuc-2')->exists())->toBeTrue();
    });
});

it('does not treat the route of the page itself as taken when it is saved again', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $editor = Livewire::actingAs($user)->test(EntryEditor::class)
            ->set('title', 'Liên hệ')->set('slug', '')->call('publish')->assertHasNoErrors();
        expect(SiteRoute::query()->where('path', '/lien-he')->count())->toBe(1);

        $editor->set('slug', '')->call('save')->assertHasNoErrors();
        expect(SiteRoute::query()->where('path', '/lien-he')->count())->toBe(1)
            ->and(EntryTranslation::query()->where('slug', 'lien-he')->count())->toBe(1);
    });
});

it('asks the database one question for the link however many numbered ones are taken', function (): void {
    $user = slugEditor();

    $saveWithTaken = function (int $taken) use ($user): array {
        app(Tenancy::class)->enter(1, function () use ($taken): void {
            EntryTranslation::query()->delete();
            Entry::query()->delete();
            foreach (range(1, $taken) as $n) {
                $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
                $entry->translations()->create([
                    'locale' => 'vi', 'title' => 'Máy', 'slug' => $n === 1 ? 'may-tien' : 'may-tien-'.$n,
                    'body' => ['blocks' => []], 'status' => 'draft',
                ]);
            }
        });

        $slug = '';
        $count = 0;
        app(Tenancy::class)->enter(1, function () use ($user, &$slug, &$count): void {
            // A throwaway save first, so one-off warm-up queries are not counted.
            Livewire::actingAs($user)->test(EntryEditor::class)->set('title', 'Khởi động')->set('slug', '')->call('save');
            $editor = Livewire::actingAs($user)->test(EntryEditor::class)->set('title', 'Máy tiện')->set('slug', '');
            $count = countQueries(function () use ($editor, &$slug): void {
                $editor->call('save');
                $slug = $editor->get('slug');
            });
        });

        return [$slug, $count];
    };

    [$slugOne, $countOne] = $saveWithTaken(1);
    [$slugSix, $countSix] = $saveWithTaken(6);

    expect($slugOne)->toBe('may-tien-2')
        ->and($slugSix)->toBe('may-tien-7')
        ->and($countSix)->toBe($countOne);
});

it('treats a path owned by a category as taken when a product name would collide', function (): void {
    $user = slugEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Máy tiện', 'slug' => 'may-tien']);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'may-tien');

        Livewire::actingAs($user)->test(ProductEditor::class)
            ->set('name', 'Máy tiện')->set('slug', '')->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'may-tien-2');
    });
});

it('reads back only the base and its numbered copies, not every link that starts with it', function (): void {
    $taken = app(Tenancy::class)->enter(1, function (): array {
        foreach (['may-tien', 'may-tien-2', 'may-tien-cnc', 'may-tien-2-truc'] as $slug) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Máy', 'slug' => $slug, 'body' => ['blocks' => []], 'status' => 'draft']);
        }

        return Slugger::takenAmong(EntryTranslation::query()->where('locale', 'vi'), 'slug', 'may-tien');
    });

    expect($taken)->toEqualCanonicalizing(['may-tien', 'may-tien-2']);
});
