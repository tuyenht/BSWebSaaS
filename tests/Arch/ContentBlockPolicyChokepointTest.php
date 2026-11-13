<?php

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Policies\ContentBlockPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

/*
 * Every change to the block library asks ContentBlockPolicy first, however it was reached: the
 * block model has the policy bound, and every public method of every action under
 * Cms/Actions/ContentBlocks opens with `Gate::forUser($actor)->authorize(<a write ability>, …)`
 * for the person acting. A check for a read ability (view, viewAny) would let anyone who may place
 * a block change it. A new action, or a new public method on one, fails here until it does the same.
 */

const CONTENT_BLOCK_WRITE_ABILITIES = ['create', 'update', 'publish', 'duplicate', 'delete'];

test('the block model is guarded by ContentBlockPolicy', function (): void {
    expect(Gate::getPolicyFor(ContentBlock::class))->toBeInstanceOf(ContentBlockPolicy::class);
});

/**
 * The public methods of each block action (its constructor aside) whose body does not open with a
 * write-ability policy check for $actor, as "File::method".
 *
 * @return list<string>
 */
function contentBlockActionsWithoutPolicyCheck(): array
{
    $offenders = [];
    $actions = File::files(app_path('Modules/Cms/Actions/ContentBlocks'));
    expect($actions)->not->toBeEmpty();
    $abilities = implode('|', CONTENT_BLOCK_WRITE_ABILITIES);

    foreach ($actions as $file) {
        $class = 'App\\Modules\\Cms\\Actions\\ContentBlocks\\'.$file->getFilenameWithoutExtension();
        $lines = file($file->getPathname()) ?: [];
        $methods = array_filter(
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class && ! $method->isConstructor(),
        );
        expect($methods)->not->toBeEmpty();

        foreach ($methods as $method) {
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $opening = substr($body, (int) strpos($body, '{', (int) strpos($body, ')')));
            if (preg_match('/^\{\s*(?<first>[^;]*;)/su', $opening, $match) !== 1
                || preg_match("/^Gate::forUser\\(\\\$actor\\)->authorize\\('(?:{$abilities})',/", trim($match['first'])) !== 1) {
                $offenders[] = $file->getFilename().'::'.$method->getName();
            }
        }
    }

    return $offenders;
}

test('every public method of every block action asks the policy for a write before anything else', function (): void {
    expect(contentBlockActionsWithoutPolicyCheck())->toBe([]);
});
