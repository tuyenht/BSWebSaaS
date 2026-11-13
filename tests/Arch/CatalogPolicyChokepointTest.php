<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CatalogPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

/*
 * Every product change asks CatalogPolicy first, however it was reached: the product model has the
 * policy bound, and every public method of every action under Catalog/Actions that can write opens
 * with the shared authorizeCatalog() check (a person signed in without the permission is refused
 * there; with nobody signed in — the console, the nightly Trash purge — there is nobody to ask). The
 * methods that only read or tidy text are named below; a new action, or a new public method on one,
 * fails here until it either asks the policy first or is added to that list as read-only.
 */

/** Public methods that write nothing: text tidying, lookups, an advisory lock, a read of the loaded list. */
const CATALOG_ACTION_READ_ONLY = [
    'ChangeProductMainCategory::categoryIds',
    'ChangeProductMainCategory::routePaths',
    'ChangeProductSlug::clean',
    'ChangeProductSlug::otherProduct',
    'ChangeProductSlug::refusal',
    'ChangeProductSlug::lock',
    'RenameProduct::clean',
    'RenameProduct::tidy',
];

test('the product model is guarded by CatalogPolicy', function (): void {
    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(CatalogPolicy::class);
});

/**
 * The public methods of each catalog action (its constructor and the read-only list aside) whose
 * body does not open with `$this->authorizeCatalog(…);`, as "Class::method".
 *
 * @return list<string>
 */
function catalogActionsWithoutPolicyCheck(): array
{
    $offenders = [];
    $actions = File::files(app_path('Modules/Catalog/Actions'));
    expect($actions)->not->toBeEmpty();

    foreach ($actions as $file) {
        $short = $file->getFilenameWithoutExtension();
        $class = 'App\\Modules\\Catalog\\Actions\\'.$short;
        $lines = file($file->getPathname()) ?: [];
        $methods = array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class && ! $method->isConstructor(),
        );
        expect($methods)->not->toBeEmpty();

        foreach ($methods as $method) {
            $name = $short.'::'.$method->getName();
            if (in_array($name, CATALOG_ACTION_READ_ONLY, true)) {
                continue;
            }
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $opening = (int) strpos($body, '{', (int) strpos($body, ')'));
            if (preg_match('/^\{\s*\$this->authorizeCatalog\([^;]*\);/su', substr($body, $opening)) !== 1) {
                $offenders[] = $name;
            }
        }
    }

    return $offenders;
}

test('every public method of every catalog action that writes asks the policy before anything else', function (): void {
    expect(catalogActionsWithoutPolicyCheck())->toBe([]);
});

test('the read-only list names methods that still exist', function (): void {
    foreach (CATALOG_ACTION_READ_ONLY as $name) {
        [$short, $method] = explode('::', $name);
        expect(method_exists('App\\Modules\\Catalog\\Actions\\'.$short, $method))->toBeTrue($name);
    }
});
