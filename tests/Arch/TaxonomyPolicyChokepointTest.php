<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Policies\TaxonomyPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

/*
 * Every change to a category tree asks TaxonomyPolicy first: the category model has the policy
 * bound, and every public method of every action under Cms/Actions/Taxonomies opens with the shared
 * authorizeManage() check (a person signed in without `taxonomies.manage` is refused there, however
 * the action was reached). A new action, or a new public method on one, fails here until it does
 * the same.
 */

test('the category model is guarded by TaxonomyPolicy', function (): void {
    expect(Gate::getPolicyFor(Taxonomy::class))->toBeInstanceOf(TaxonomyPolicy::class);
});

/**
 * The public methods of each category action (its constructor aside) whose body does not open
 * with `$this->authorizeManage();`, as "File::method".
 *
 * @return list<string>
 */
function taxonomyActionsWithoutPolicyCheck(): array
{
    $offenders = [];
    $actions = File::files(app_path('Modules/Cms/Actions/Taxonomies'));
    expect($actions)->not->toBeEmpty();

    foreach ($actions as $file) {
        $class = 'App\\Modules\\Cms\\Actions\\Taxonomies\\'.$file->getFilenameWithoutExtension();
        $lines = file($file->getPathname()) ?: [];
        $methods = array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class && ! $method->isConstructor(),
        );
        expect($methods)->not->toBeEmpty();

        foreach ($methods as $method) {
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            if (preg_match('/\{\s*(?<first>[^;]*;)/su', substr($body, (int) strpos($body, ')')), $match) !== 1
                || trim($match['first']) !== '$this->authorizeManage();') {
                $offenders[] = $file->getFilename().'::'.$method->getName();
            }
        }
    }

    return $offenders;
}

test('every public method of every category action asks the policy before anything else', function (): void {
    expect(taxonomyActionsWithoutPolicyCheck())->toBe([]);
});
