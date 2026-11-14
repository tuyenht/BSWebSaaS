<?php

use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Admin\Livewire\Entries\Index as EntryIndex;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductSiteWords;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Support\EntrySearchWords;
use App\Modules\Cms\Support\TaxonomySearchWords;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The storefront search words of one item are rewritten from its translations under the item's lock.
 * The check of `search:words` (sync) must take the item's lock FIRST and read the translations in a
 * statement of its own AFTER it: under READ COMMITTED a locking read joined to the translations
 * returns the translations of the statement's snapshot, taken before it waited, so a save that ended
 * during the wait would have its words overwritten with the old ones. And it must not lock the
 * translation rows themselves, in an order of its own (by language), which a save writing another
 * language first would deadlock with. As in EntryPublishLockOrderTest, the statement order is
 * asserted rather than a real two-connection race, which would be flaky.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * Run $callback and return every statement it issued, lower case, in order.
 *
 * @return list<string>
 */
function siteWordsStatementsDuring(Closure $callback): array
{
    /** @var list<string> $queries */
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower(trim($query->sql));
    });

    $callback();

    return $queries;
}

/**
 * The index of the first statement matching $pattern, or -1.
 *
 * @param  list<string>  $statements
 */
function siteWordsFirst(array $statements, string $pattern): int
{
    foreach ($statements as $i => $sql) {
        if (preg_match($pattern, $sql) === 1) {
            return $i;
        }
    }

    return -1;
}

it('locks the product row, then reads its translations in a statement of their own', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = Product::query()->create(['status' => 'published', 'sku' => 'VB-1']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Vòng bi', 'slug' => 'vong-bi', 'summary' => '<p>Bi thép</p>']);

        $statements = siteWordsStatementsDuring(fn () => ProductSiteWords::sync(1, $product->id));

        $lock = siteWordsFirst($statements, '~^select .* from "?products"? .*for no key update$~');
        $read = siteWordsFirst($statements, '~^select .* from "?product_translations"?~');
        expect($lock)->toBe(0, implode("\n", $statements))
            ->and($statements[$lock])->not->toContain('product_translations')
            ->and($read)->toBeGreaterThan($lock)
            ->and($statements[$read])->not->toContain(' for ')
            ->and(siteWordsOf('product_site_words', 1, $product->id))->toBe(['vi' => ['1' => 1, 'b1' => 1, 'bi' => 1, 'thep' => 3, 'vb' => 1, 'vb1' => 1, 'vong' => 1]]);
    });
});

it('locks the entry row, then reads its translations in a statement of their own, locking none of them', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Khuôn mẫu', 'slug' => 'khuon-mau', 'status' => 'draft', 'excerpt' => 'Ép nhựa', 'body' => ['blocks' => []]]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'Moulds', 'slug' => 'moulds', 'status' => 'draft', 'body' => ['blocks' => []]]);

        $statements = siteWordsStatementsDuring(fn () => EntrySearchWords::sync(1, $entry->id));

        $lock = siteWordsFirst($statements, '~^select .* from "?entries"? .*for no key update$~');
        $read = siteWordsFirst($statements, '~^select .* from "?entry_translations"?~');
        expect($lock)->toBe(0, implode("\n", $statements))
            ->and($read)->toBeGreaterThan($lock)
            ->and($statements[$read])->not->toContain(' for ')
            ->and(siteWordsOf('entry_search_words', 1, $entry->id))->toBe(['en' => ['moulds' => 1], 'vi' => ['ep' => 3, 'khuon' => 1, 'mau' => 1, 'nhua' => 3]]);
    });
});

it('takes the category tree lock and the category row, then reads its names, locking none of them', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Khuôn Mẫu', 'slug' => 'khuon-mau']);

        $statements = siteWordsStatementsDuring(fn () => TaxonomySearchWords::sync(1, $category->id));

        $tree = siteWordsFirst($statements, '~pg_advisory_xact_lock~');
        $lock = siteWordsFirst($statements, '~^select .* from "?taxonomies"? .*for no key update$~');
        $read = siteWordsFirst($statements, '~^select .* from "?taxonomy_translations"?~');
        expect($tree)->toBe(0, implode("\n", $statements))
            ->and($lock)->toBe(1)
            ->and($read)->toBeGreaterThan($lock)
            ->and($statements[$read])->not->toContain(' for ')
            ->and(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe(['vi' => ['khuon' => 1, 'mau' => 1]]);
    });
});

it('drops the words of an item that no longer exists without reading its translations', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $statements = siteWordsStatementsDuring(function (): void {
            EntrySearchWords::sync(1, 999_999);
            ProductSiteWords::sync(1, 999_999);
            TaxonomySearchWords::sync(1, 999_999);
        });

        expect(siteWordsFirst($statements, '~_translations~'))->toBe(-1, implode("\n", $statements));
    });
});

it('reads the saved row back, taking no item lock, when a save holds only some columns', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Khuôn mẫu', 'slug' => 'khuon-mau', 'status' => 'draft', 'excerpt' => 'Ép nhựa', 'body' => ['blocks' => []]]);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Khuôn Mẫu', 'slug' => 'khuon-mau']);

        $statements = siteWordsStatementsDuring(function () use ($entry, $category): void {
            EntryTranslation::query()->where('entry_id', $entry->id)->firstOrFail(['id', 'site_id', 'entry_id', 'locale', 'title'])->update(['title' => 'Đồ gá']);
            TaxonomyTranslation::query()->where('taxonomy_id', $category->id)->firstOrFail(['id', 'site_id', 'taxonomy_id', 'locale', 'slug'])->update(['slug' => 'do-ga', 'name' => 'Đồ gá']);
        });

        expect(siteWordsFirst($statements, '~for no key update|for update|pg_advisory~'))->toBe(-1, implode("\n", $statements))
            ->and(siteWordsOf('entry_search_words', 1, $entry->id))->toBe(['vi' => ['do' => 1, 'ep' => 3, 'ga' => 1, 'nhua' => 3]])
            ->and(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe(['vi' => ['do' => 1, 'ga' => 1]]);
    });
});

it('moves the words with a translation moved to another language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
        $vi = $entry->translations()->create(['locale' => 'vi', 'title' => 'Moulds', 'slug' => 'moulds', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $product = Product::query()->create(['status' => 'draft']);
        $name = $product->translations()->create(['locale' => 'vi', 'name' => 'Bearing', 'slug' => 'bearing']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $label = $category->translations()->create(['locale' => 'vi', 'name' => 'Tools', 'slug' => 'tools']);

        $vi->update(['locale' => 'en']);
        $name->update(['locale' => 'en']);
        $label->update(['locale' => 'en']);

        expect(siteWordsOf('entry_search_words', 1, $entry->id))->toBe(['en' => ['moulds' => 1]])
            ->and(siteWordsOf('product_site_words', 1, $product->id))->toBe(['en' => ['bearing' => 1]])
            ->and(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe(['en' => ['tools' => 1]]);
    });
});

/** A draft page of site 1 titled "Khuôn mẫu" with its vi translation; returns it. */
function siteWordsLockPage(): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Khuôn mẫu', 'slug' => 'khuon-mau-'.$page->id, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh();
}

/**
 * The entry row lock comes before the first write to the entry's translations: a check of the search
 * words (sync, which locks the entry row) then waits for the save instead of writing the old title's
 * words back over the new ones.
 *
 * @param  list<string>  $statements
 */
function expectEntryLockedBeforeTranslationWrite(array $statements): void
{
    $lock = siteWordsFirst($statements, '~^select .* from "?entries"? .*for update$~');
    $write = siteWordsFirst($statements, '~^(update|insert into|delete from) "?entry_translations"?~');
    expect($write)->toBeGreaterThan(-1, implode("\n", $statements))
        ->and($lock)->toBeGreaterThan(-1, implode("\n", $statements))
        ->and($lock)->toBeLessThan($write);
}

it('locks the entry row before the list renames a title inline', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
        $page = siteWordsLockPage();
        $list = Livewire::actingAs($user->fresh())->test(EntryIndex::class);

        $statements = siteWordsStatementsDuring(fn () => $list->call('saveTitle', $page->ulid, 'Đồ gá'));

        expectEntryLockedBeforeTranslationWrite($statements);
        expect(siteWordsOf('entry_search_words', 1, $page->id))->toBe(['vi' => ['do' => 1, 'ga' => 1]]);
    });
});

it('locks the entry row before the editor writes the translation, also when no shared field changed', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
        $page = siteWordsLockPage();
        $editor = Livewire::actingAs($user->fresh())->test(EntryEditor::class, ['ulid' => $page->ulid])->set('title', 'Đồ gá');

        $statements = siteWordsStatementsDuring(fn () => $editor->call('save')->assertHasNoErrors());

        expectEntryLockedBeforeTranslationWrite($statements);
        expect(siteWordsOf('entry_search_words', 1, $page->id))->toBe(['vi' => ['do' => 1, 'ga' => 1]]);
    });
});

it('locks the entry row before a revision restore writes the translation back', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.edit.any']);
        $actor = $user->fresh();
        $page = siteWordsLockPage();
        app(RecordEntryRevision::class)->forLocale($page, $page->translationFor('vi'), RevisionKind::Edit, $actor);
        $revision = EntryRevision::query()->where('entry_id', $page->id)->sole();
        $page->translationFor('vi')->update(['title' => 'Đồ gá']);

        $statements = siteWordsStatementsDuring(fn () => app(RestoreEntryRevision::class)->handle($revision, $actor));

        expectEntryLockedBeforeTranslationWrite($statements);
        expect(siteWordsOf('entry_search_words', 1, $page->id))->toBe(['vi' => ['khuon' => 1, 'mau' => 1]]);
    });
});
