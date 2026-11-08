<?php

use Illuminate\Support\Facades\File;
use Tests\Fixtures\ModelWriteBypasses;

/*
 * A product's search words (product_search_words) are rewritten by the model events of Product (its
 * SKU) and ProductTranslation (its name, a language removed), in the transaction of the save. A write
 * that skips the events leaves the words behind, and the product is then missing from the admin search
 * (or found by an old name). So the writes of products.sku and product_translations.name that skip
 * the models are banned here: query-builder writes that name the columns or hide their values, raw SQL
 * that writes them, bulk updates/inserts/upserts on the models or the translations relation, bulk
 * deletes of translations, and quiet saves (…Quietly, withoutEvents) in a file that names the models.
 * `catalog:search-words` corrects what still slips through, and its nightly run logs it.
 *
 * Coarse on purpose: `->translations()` of any model counts for a bulk update of a name (a taxonomy's
 * is flagged too, and is better written through its model as well); a bulk delete of translations
 * counts in a file that names the product models or tables.
 */

/**
 * @return list<string>
 */
function productWordBypasses(string $source): array
{
    return (new ModelWriteBypasses(
        tables: '(?:products|product_translations)',
        columns: 'name|sku',
        models: 'Product|ProductTranslation',
        relations: 'translations',
        collection: 'translations',
        columnAware: true,
        deleteAnchor: '~\bProductTranslation::(?!class\b)|->translations\(\)~',
        quietSaves: true,
    ))->find($source);
}

it('writes a product name or SKU only through the models, which keep the search words', function (): void {
    $violations = [];
    $scanned = 0;
    foreach ([app_path(), database_path('seeders')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getPathname(), '.php')) {
                continue;
            }
            $scanned++;
            foreach (productWordBypasses(File::get($file->getPathname())) as $hit) {
                $violations[] = str_replace(base_path().'/', '', $file->getPathname()).": {$hit}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(500)
        ->and($violations)->toBe([]);
});

it('catches each banned way of writing a name or a SKU', function (string $source): void {
    expect(productWordBypasses("<?php\n".$source))->not->toBe([]);
})->with([
    'DB::table update of the name' => "DB::table('product_translations')->where('id', \$id)->update(['name' => \$name]);",
    'DB::table update of the SKU' => "DB::table('products')->whereIn('id', \$ids)->update(['sku' => null]);",
    'DB::table insert of rows kept in a variable' => "DB::table('product_translations')->insert(\$rows);",
    'raw SQL update' => "DB::update('UPDATE product_translations SET name = ? WHERE id = ?', [\$name, \$id]);",
    'raw SQL insert' => "DB::statement(\"insert into products (ulid, site_id, sku) values ('a', 1, 'X')\");",
    'model bulk update' => "ProductTranslation::query()->where('product_id', \$id)->update(['name' => \$name]);",
    'product bulk update' => "Product::query()->whereKey(\$ids)->update(['sku' => \$sku]);",
    'relation bulk update' => "\$product->translations()->update(['name' => \$name]);",
    'model upsert' => "ProductTranslation::query()->upsert(\$rows, ['product_id', 'locale'], ['name']);",
    'model insert' => 'Product::insert($rows);',
    'toBase' => "\$query->toBase()->update(['name' => \$name]);",
    'column in a variable' => 'ProductTranslation::query()->update([$column => $name]);',
    'translations deleted in bulk' => "\$product->translations()->where('locale', 'en')->delete(); // Product",
    'translations deleted in bulk by the model' => "ProductTranslation::query()->whereIn('product_id', \$ids)->delete();",
    'quiet save' => "\$translation = ProductTranslation::query()->first();\n\$translation->saveQuietly();",
    'without events' => "Product::withoutEvents(fn () => \$product->update(['sku' => 'X']));",
]);

it('lets the model roads and the other columns through', function (string $source): void {
    expect(productWordBypasses("<?php\n".$source))->toBe([]);
})->with([
    'relation create' => "\$product->translations()->create(['locale' => 'vi', 'name' => \$name, 'slug' => \$slug]);",
    'updateOrCreate' => "\$product->translations()->updateOrCreate(['locale' => \$locale], ['name' => \$name]);",
    'instance update' => "\$translation->update(['name' => \$name]);",
    'instance update when found' => "ProductTranslation::query()->where('id', \$id)->first()?->update(['name' => \$name]);",
    'product create' => "Product::query()->create(['status' => 'draft', 'sku' => \$sku]);",
    'a read through the table' => "DB::table('product_translations')->where('product_id', \$id)->pluck('name');",
    'the content mark' => "ProductTranslation::query()->whereIn('product_id', \$ids)->update(['content_changed_at' => now()]);",
    'a status change in bulk' => "Product::query()->whereKey(\$ids)->update(['status' => 'archived']);",
    'the edit time' => "Product::query()->whereKey(\$id)->toBase()->update(['updated_at' => \$at]);",
    'raw SQL on another column' => "DB::update('UPDATE products SET stock = stock - ? WHERE id = ?', [\$n, \$id]);",
    'one translation deleted' => "\$product->translations()->where('locale', 'en')->first()?->delete(); // Product",
    'a menu item\'s translations deleted' => "\$item->translations()->where('locale', \$locale)->delete();",
    'the word table itself' => "DB::statement('insert into product_search_words (site_id, product_id, word) values (?, ?, ?)', [1, 2, 'a']);",
]);
