<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CatalogPolicy;
use Symfony\Component\Finder\Finder;

/*
 * Chokepoints for the product-category limits of a member (CategoryScopes::PRODUCTS). A member
 * limited to some product categories may see and change only the products whose main category is
 * inside them, so every road to a product write has to pass through a place that asks:
 *
 *  - the catalog actions (they ask CatalogPolicy about the product, and about where it is filed);
 *  - in the admin, only the products list and the product editor touch products for writing, and
 *    the list looks a product up only through its one narrowed road (products()) or the list query;
 *  - the editor asks the policy about the stored product, never only about the class.
 *
 * Adding a file to a list here means checking it keeps those rules.
 */

/** @return list<string> the app files whose contents match $pattern, relative to the project */
function catalogScopeFilesMatching(string $pattern): array
{
    $found = [];
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $found[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
        }
    }
    sort($found);

    return $found;
}

it('calls the catalog write actions only from the products list, the product editor and the Trash purge', function (): void {
    $callers = array_values(array_filter(
        catalogScopeFilesMatching('/^use App\\\\Modules\\\\Catalog\\\\Actions\\\\(ChangeProductMainCategory|ForceDeleteProducts|ChangeProductStatus|RenameProduct|ChangeProductSlug);$/m'),
        static fn (string $file): bool => ! str_starts_with($file, 'app/Modules/Catalog/Actions/'),
    ));

    expect($callers)->toBe([
        'app/Modules/Admin/Livewire/Catalog/Editor.php',
        'app/Modules/Admin/Livewire/Catalog/Index.php',
        'app/Modules/Catalog/Console/PurgeTrashedProducts.php',     // nobody signed in: nobody to limit
    ]);
});

it('touches products in the admin only in the known places', function (): void {
    // any road to the products: a product model however it is named (use line, group use, a fully
    // qualified name, its translations or images), or the products table in a query builder or SQL
    $admin = array_values(array_filter(
        catalogScopeFilesMatching("/Catalog\\\\Models\\\\(Product\\w*\\b|\\{)|(table|join|leftJoin|rightJoin|from)\\(\\s*'products(\\s+as\\s+\\w+)?'|\\b(from|join)\\s+products\\b/i"),
        static fn (string $file): bool => str_starts_with($file, 'app/Modules/Admin/'),
    ));

    expect($admin)->toBe([
        'app/Modules/Admin/Livewire/Catalog/Editor.php',            // writes: asks the policy about the product
        'app/Modules/Admin/Livewire/Catalog/Index.php',             // writes: through products() / the list query
        'app/Modules/Admin/Support/Catalog/ProductListQuery.php',  // reads the list, narrowed by `within`
        'app/Modules/Admin/Support/MediaUsageLocator.php',         // reads names only
        'app/Modules/Admin/Support/Menus/MenuSourceSearch.php',    // reads names to pick a menu target, writes nothing
        'app/Modules/Admin/Support/Menus/MenuSources.php',         // reads menu targets only
        'app/Modules/Admin/Support/Redirects/RedirectDirectory.php', // reads names only: what a redirect leads to
    ]);
});

it('looks a product up in the products list only through its narrowed road', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/Livewire/Catalog/Index.php'));
    preg_match_all('/Product::(query|onlyTrashed|withTrashed)\(\)/', $source, $calls);

    // exactly the two in products() itself
    expect($calls[0])->toBe(['Product::onlyTrashed()', 'Product::query()'])
        ->and($source)->toContain('$query = $trashed ? Product::onlyTrashed() : Product::query();')
        ->and($source)->toMatch('/new ProductListQuery\([^;]*within: \$this->within\(\)\)/');
});

it('asks the policy about the stored product in the product editor, never only about the class', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Admin/Livewire/Catalog/Editor.php'));

    expect(preg_match("/authorize\\([^;]*'(update|delete|restore|forceDelete)'[^;]*Product::class/", $source))->toBe(0)
        ->and($source)->toContain("\$this->authorize('fileUnder', [Product::class, \$this->categoryId]);");
});

it('lets the policy be asked about a product for every write on one', function (): void {
    foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
        $parameters = (new ReflectionMethod(CatalogPolicy::class, $ability))->getParameters();
        $type = $parameters[1]->getType();

        expect($type)->toBeInstanceOf(ReflectionNamedType::class)
            ->and($type instanceof ReflectionNamedType ? $type->getName() : null)->toBe(Product::class);
    }
    expect(method_exists(CatalogPolicy::class, 'fileUnder'))->toBeTrue();
});
