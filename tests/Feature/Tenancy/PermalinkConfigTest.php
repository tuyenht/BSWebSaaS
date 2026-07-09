<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * PermalinkConfig reads the per-site permalink pattern from site_settings (group 'permalinks').
 * Coverage: every section defaults to flat; a preset is honoured only when valid for the section
 * type (the validity matrix); the `base` segment read; the fail-safe fallback for an explicit-flat /
 * empty / malformed / unknown / cross-type value; and per-site memo isolation (one worker serving
 * two sites must not leak a pattern across them).
 */

beforeEach(function (): void {
    seedTenancy();
});

/**
 * Write a permalinks row for a section of the current site (call inside tenancy context).
 *
 * @param  array<mixed>  $value
 */
function setPermalinkRow(string $section, array $value): void
{
    SiteSetting::query()->updateOrCreate(
        ['group_name' => 'permalinks', 'key' => $section],
        ['value' => $value],
    );
}

/** A fresh (un-memoized) reader so an assertion re-reads the current stored value. */
function freshPermalinks(): PermalinkConfig
{
    return new PermalinkConfig(app(CurrentSite::class));
}

it('defaults every section to flat when no permalinks row exists', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $reader = freshPermalinks();
        expect($reader->pattern('pages', 'pages'))->toBe('flat')
            ->and($reader->pattern('tin-tuc', 'posts'))->toBe('flat')
            ->and($reader->pattern('san-pham', 'products'))->toBe('flat')
            ->and($reader->pagesNested())->toBeFalse();
    });
});

it('honours a preset that is valid for the section type', function (string $section, string $type, string $pattern): void {
    app(Tenancy::class)->enter(1, function () use ($section, $type, $pattern): void {
        setPermalinkRow($section, ['pattern' => $pattern]);
        expect(freshPermalinks()->pattern($section, $type))->toBe($pattern);
    });
})->with([
    'pages · nested' => ['pages', 'pages', 'nested'],
    'pages · base' => ['pages', 'pages', 'base'],
    'posts · base' => ['tin-tuc', 'posts', 'base'],
    'posts · category' => ['tin-tuc', 'posts', 'category'],
    'posts · category_path' => ['tin-tuc', 'posts', 'category_path'],
    'products · category' => ['san-pham', 'products', 'category'],
    'products · category_path' => ['san-pham', 'products', 'category_path'],
]);

it('ignores a preset that is not valid for the section type → flat', function (string $type, string $pattern): void {
    app(Tenancy::class)->enter(1, function () use ($type, $pattern): void {
        setPermalinkRow('sec', ['pattern' => $pattern]);
        expect(freshPermalinks()->pattern('sec', $type))->toBe('flat');
    });
})->with([
    'category on a page' => ['pages', 'category'],
    'category_path on a page' => ['pages', 'category_path'],
    'nested on a post' => ['posts', 'nested'],
    'nested on a product' => ['products', 'nested'],
    'any preset on an unknown type' => ['widgets', 'category'],
]);

it('activates page nesting only for an explicit nested pattern', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        setPermalinkRow('pages', ['pattern' => 'nested']);
        $reader = freshPermalinks();
        expect($reader->pattern('pages', 'pages'))->toBe('nested')
            ->and($reader->pagesNested())->toBeTrue();
    });
});

it('falls back to flat for an explicit-flat, empty, malformed, or unknown stored value', function (array $value): void {
    app(Tenancy::class)->enter(1, function () use ($value): void {
        setPermalinkRow('pages', $value);
        $reader = freshPermalinks();
        expect($reader->pattern('pages', 'pages'))->toBe('flat')
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

it('exposes the validity matrix per section type', function (): void {
    $reader = app(PermalinkConfig::class);
    expect($reader->allowedPatterns('pages'))->toBe(['flat', 'nested', 'base'])
        ->and($reader->allowedPatterns('posts'))->toBe(['flat', 'base', 'category', 'category_path'])
        ->and($reader->allowedPatterns('products'))->toBe(['flat', 'base', 'category', 'category_path'])
        ->and($reader->allowedPatterns('widgets'))->toBe(['flat']);
});

it('reads the base segment for the base preset, trimming slashes', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        setPermalinkRow('san-pham', ['pattern' => 'base', 'base' => '/san-pham/']);
        expect(freshPermalinks()->base('san-pham'))->toBe('san-pham');
    });
});

it('returns null base for a blank, absent, or non-string base value', function (array $value): void {
    app(Tenancy::class)->enter(1, function () use ($value): void {
        setPermalinkRow('sec', $value);
        expect(freshPermalinks()->base('sec'))->toBeNull();
    });
})->with([
    'blank base' => [['pattern' => 'base', 'base' => '   ']],
    'slash-only base' => [['pattern' => 'base', 'base' => '///']],
    'absent base' => [['pattern' => 'base']],
    'non-string base' => [['pattern' => 'base', 'base' => 42]],
]);

it('returns the section default with no site context', function (): void {
    expect(app(PermalinkConfig::class)->pattern('pages', 'pages'))->toBe('flat')
        ->and(app(PermalinkConfig::class)->base('pages'))->toBeNull();
});

it('keeps each site memo isolated — one worker never leaks a pattern across sites', function (): void {
    // One scoped reader reused across two sites (mimics an Octane worker with no flush between
    // operations). The memo is keyed by site id, so site B must not inherit site A's pattern.
    $reader = app(PermalinkConfig::class);

    app(Tenancy::class)->enter(1, function () use ($reader): void {
        setPermalinkRow('pages', ['pattern' => 'nested']);
        expect($reader->pagesNested())->toBeTrue();
    });

    app(Tenancy::class)->enter(2, function () use ($reader): void {
        expect($reader->pagesNested())->toBeFalse();
    });
});
