<?php

use Illuminate\Support\Facades\File;
use Tests\Fixtures\ModelWriteBypasses;

/*
 * The storefront search words (entry_search_words, product_site_words, taxonomy_search_words) are
 * rewritten by the model events of EntryTranslation (title, excerpt, a language added or removed),
 * Product (SKU), ProductTranslation (name, summary, a language) and TaxonomyTranslation (name, a
 * language), in the transaction of the save. A write that skips the events leaves the words behind,
 * and the item is then missing from the storefront search (or found by an old title). So the writes of
 * those columns that skip the models are banned here, as ProductSearchWordsChokepointTest bans them
 * for the admin words: query-builder writes that name the columns or hide their values, raw SQL that
 * writes them, bulk updates/inserts/upserts on the models or the translations relation, bulk deletes
 * of translations, and quiet saves in a file that names the models. It reads app/, the seeders,
 * routes/ and the migrations that come after the word tables' own. `search:words` corrects what still
 * slips through, and its nightly run logs it.
 *
 * Listed bypasses (each writes none of the columns): `cms:resanitize-bodies` saves a translation
 * quietly after reading only its id and body, so only the body is written.
 */

/** @var array<string, string> file => the one rule allowed there */
const SITE_WORDS_ALLOWED = [
    'app/Modules/Cms/Console/ResanitizePageBodies.php' => 'save without events',
];

/**
 * @return array<string, ModelWriteBypasses>
 */
function siteWordSpecs(): array
{
    return [
        'entry' => new ModelWriteBypasses(
            tables: '(?:entry_translations)',
            columns: 'title|excerpt',
            models: 'EntryTranslation',
            relations: 'translations',
            collection: 'translations',
            columnAware: true,
            deleteAnchor: '~\bEntryTranslation::(?!class\b)|->translations\(\)~',
            quietSaves: true,
        ),
        'product' => new ModelWriteBypasses(
            tables: '(?:products|product_translations)',
            columns: 'name|sku|summary',
            models: 'Product|ProductTranslation',
            relations: 'translations',
            collection: 'translations',
            columnAware: true,
            deleteAnchor: '~\bProductTranslation::(?!class\b)|->translations\(\)~',
            quietSaves: true,
        ),
        'taxonomy' => new ModelWriteBypasses(
            tables: '(?:taxonomy_translations)',
            columns: 'name',
            models: 'TaxonomyTranslation',
            relations: 'translations',
            collection: 'translations',
            columnAware: true,
            deleteAnchor: '~\bTaxonomyTranslation::(?!class\b)|->translations\(\)~',
            quietSaves: true,
        ),
    ];
}

/**
 * @return list<string>
 */
function siteWordBypasses(string $kind, string $source): array
{
    return siteWordSpecs()[$kind]->find($source);
}

it('writes a title, excerpt, name, SKU or summary only through the models, which keep the storefront words', function (): void {
    $files = [];
    foreach ([app_path(), database_path('seeders'), base_path('routes')] as $root) {
        foreach (File::allFiles($root) as $file) {
            $files[] = $file->getPathname();
        }
    }
    $creation = '2062_01_01_000000_create_site_search_word_tables.php';
    expect(File::exists(database_path('migrations/'.$creation)))->toBeTrue();
    foreach (File::files(database_path('migrations')) as $file) {
        if (strcmp($file->getFilename(), $creation) > 0) {
            $files[] = $file->getPathname();
        }
    }

    $violations = [];
    $scanned = 0;
    foreach ($files as $path) {
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        $scanned++;
        $relative = str_replace(base_path().'/', '', $path);
        $source = File::get($path);
        foreach (array_keys(siteWordSpecs()) as $kind) {
            foreach (siteWordBypasses($kind, $source) as $hit) {
                if (str_starts_with($hit, (SITE_WORDS_ALLOWED[$relative] ?? "\0").' @ ')) {
                    continue;
                }
                $violations[] = "{$relative} ({$kind}): {$hit}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(500)
        ->and($violations)->toBe([]);
});

it('still sees each listed bypass, so a stale entry is noticed', function (): void {
    foreach (SITE_WORDS_ALLOWED as $file => $rule) {
        $hits = siteWordBypasses('entry', File::get(base_path($file)));
        expect($hits)->not->toBe([])
            ->and(array_filter($hits, fn (string $hit): bool => ! str_starts_with($hit, $rule.' @ ')))->toBe([]);
    }
});

it('catches each banned way of writing the words\' columns', function (string $kind, string $source): void {
    expect(siteWordBypasses($kind, "<?php\n".$source))->not->toBe([]);
})->with([
    'DB::table update of a title' => ['entry', "DB::table('entry_translations')->where('id', \$id)->update(['title' => \$title]);"],
    'DB::table update of an excerpt' => ['entry', "DB::table('entry_translations')->whereIn('entry_id', \$ids)->update(['excerpt' => null]);"],
    'raw SQL update of a title' => ['entry', "DB::update('UPDATE entry_translations SET title = ? WHERE id = ?', [\$t, \$id]);"],
    'entry translations bulk update' => ['entry', "EntryTranslation::query()->where('entry_id', \$id)->update(['title' => \$t]);"],
    'entry translations insert' => ['entry', 'EntryTranslation::insert($rows);'],
    'entry translations deleted in bulk' => ['entry', "EntryTranslation::query()->whereIn('entry_id', \$ids)->delete();"],
    'entry translation saved quietly' => ['entry', "\$row = EntryTranslation::query()->first();\n\$row->saveQuietly();"],
    'a product summary in bulk' => ['product', "ProductTranslation::query()->whereIn('product_id', \$ids)->update(['summary' => \$s]);"],
    'raw SQL update of a summary' => ['product', "DB::update('update product_translations set summary = ? where id = ?', [\$s, \$id]);"],
    'a product SKU in bulk' => ['product', "Product::query()->whereKey(\$ids)->update(['sku' => \$sku]);"],
    'a category renamed in bulk' => ['taxonomy', "TaxonomyTranslation::query()->where('taxonomy_id', \$id)->update(['name' => \$n]);"],
    'raw SQL rename of a category' => ['taxonomy', "DB::update('update taxonomy_translations set name = ? where id = ?', [\$n, \$id]);"],
    'category translations deleted in bulk' => ['taxonomy', "TaxonomyTranslation::query()->where('locale', 'en')->delete();"],
    'category translation without events' => ['taxonomy', "TaxonomyTranslation::withoutEvents(fn () => \$row->update(['name' => 'x']));"],
]);

it('lets the model roads and the other columns through', function (string $kind, string $source): void {
    expect(siteWordBypasses($kind, "<?php\n".$source))->toBe([]);
})->with([
    'entry translation create' => ['entry', "\$entry->translations()->create(['locale' => 'vi', 'title' => \$t, 'slug' => \$s]);"],
    'entry translation updateOrCreate' => ['entry', "\$entry->translations()->updateOrCreate(['locale' => \$l], ['title' => \$t]);"],
    'entry translation instance update' => ['entry', "\$translation->update(['title' => \$t, 'excerpt' => \$e]);"],
    'entry translation status in bulk' => ['entry', "EntryTranslation::query()->whereIn('entry_id', \$ids)->update(['status' => 'published']);"],
    'the content mark' => ['entry', "EntryTranslation::query()->whereIn('entry_id', \$ids)->update(['content_changed_at' => now()]);"],
    'a product summary through the model' => ['product', "\$product->translations()->updateOrCreate(['locale' => \$l], ['summary' => \$s]);"],
    'a category renamed through the model' => ['taxonomy', "\$taxonomy->translations()->updateOrCreate(['locale' => \$l], ['name' => \$n]);"],
    'a category description in bulk' => ['taxonomy', "TaxonomyTranslation::query()->where('taxonomy_id', \$id)->update(['description' => \$d]);"],
    'the word table itself' => ['entry', "DB::statement('insert into entry_search_words (site_id, locale, word, entry_id, weight) values (?, ?, ?, ?, ?)', [1, 'vi', 'a', 2, 1]);"],
]);
