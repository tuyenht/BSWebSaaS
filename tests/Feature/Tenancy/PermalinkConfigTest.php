<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * PermalinkConfig reads the per-site permalink pattern from site_settings (group 'permalinks').
 * These cover resolve() directly: the section default when absent, the recognised patterns, the
 * fail-safe fallback for an explicit-flat / empty / malformed / unknown stored value, and the
 * per-site memo isolation (one worker serving two sites must not leak a pattern across them).
 */

beforeEach(function (): void {
    seedTenancy();
});

/**
 * Write the pages permalink pattern row for the current site (call inside tenancy context).
 *
 * @param  array<string, mixed>  $value
 */
function setPagesPattern(array $value): void
{
    SiteSetting::query()->updateOrCreate(
        ['group_name' => 'permalinks', 'key' => 'pages'],
        ['value' => $value],
    );
}

/** A fresh (un-memoized) reader so an assertion re-reads the current stored value. */
function freshPermalinks(): PermalinkConfig
{
    return new PermalinkConfig(app(CurrentSite::class));
}

it('defaults pages to flat when no permalinks row exists', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $reader = freshPermalinks();
        expect($reader->pattern('pages'))->toBe('flat')
            ->and($reader->pagesNested())->toBeFalse();
    });
});

it('activates nesting only for an explicit nested pattern', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        setPagesPattern(['pattern' => 'nested']);
        $reader = freshPermalinks();
        expect($reader->pattern('pages'))->toBe('nested')
            ->and($reader->pagesNested())->toBeTrue();
    });
});

it('falls back to flat for an explicit-flat, empty, malformed, or unknown stored value', function (array $value): void {
    app(Tenancy::class)->enter(1, function () use ($value): void {
        setPagesPattern($value);
        $reader = freshPermalinks();
        expect($reader->pattern('pages'))->toBe('flat')
            ->and($reader->pagesNested())->toBeFalse();
    });
})->with([
    'explicit flat' => [['pattern' => 'flat']],
    'unknown token' => [['pattern' => 'garbage']],
    'empty string' => [['pattern' => '']],
    'non-string pattern' => [['pattern' => 123]],
    'missing pattern key' => [['nested' => true]],
    'empty object' => [[]],
]);

it('returns the section default with no site context', function (): void {
    expect(app(PermalinkConfig::class)->pattern('pages'))->toBe('flat');
});

it('keeps each site memo isolated — one worker never leaks a pattern across sites', function (): void {
    // One scoped reader reused across two sites (mimics an Octane worker with no flush between
    // operations). The memo is keyed by site id, so site B must not inherit site A's pattern.
    $reader = app(PermalinkConfig::class);

    app(Tenancy::class)->enter(1, function () use ($reader): void {
        setPagesPattern(['pattern' => 'nested']);
        expect($reader->pagesNested())->toBeTrue();
    });

    app(Tenancy::class)->enter(2, function () use ($reader): void {
        expect($reader->pagesNested())->toBeFalse();
    });
});
