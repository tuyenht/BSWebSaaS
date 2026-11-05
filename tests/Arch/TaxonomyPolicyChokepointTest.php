<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Policies\TaxonomyPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

/*
 * Every change to a category tree asks TaxonomyPolicy first: the category model has the policy
 * bound, and every action under Cms/Actions/Taxonomies opens its handle() with the shared
 * authorizeManage() check (a person signed in without `taxonomies.manage` is refused there, however
 * the action was reached). A new action fails here until it does the same.
 */

test('the category model is guarded by TaxonomyPolicy', function (): void {
    expect(Gate::getPolicyFor(Taxonomy::class))->toBeInstanceOf(TaxonomyPolicy::class);
});

test('every category action asks the policy before anything else', function (): void {
    $offenders = [];
    $actions = File::files(app_path('Modules/Cms/Actions/Taxonomies'));
    expect($actions)->not->toBeEmpty();

    foreach ($actions as $file) {
        $source = File::get($file->getPathname());
        if (preg_match('/public function handle\([^{]*\{\s*(?<first>[^;]*;)/su', $source, $match) !== 1
            || trim($match['first']) !== '$this->authorizeManage();') {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
