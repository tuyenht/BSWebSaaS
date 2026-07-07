<?php

use App\Modules\Theme\Components\SchemaImages;

/*
 * Slice 1: content references images by media_id. SchemaImages is the single walk that both
 * BlockRenderer (id → URL for render) and ValidPageJson (collect ids to validate) share, so the
 * traversal rule can't drift between them. Covers the two real shapes — a scalar image leaf (hero)
 * and a list `of` an object with an image field (partners.logos[]) — plus the fail-safe cases.
 */

// hero-shaped: a scalar image leaf beside non-image props (text/url) that must stay untouched.
$heroSchema = [
    'heading' => ['type' => 'text'],
    'image_url' => ['type' => 'image'],
    'cta_url' => ['type' => 'url'],
];

// partners-shaped: a list `of` an object carrying an image field + non-image fields.
$partnersSchema = [
    'logos' => ['type' => 'list', 'of' => [
        'image_url' => ['type' => 'image'],
        'name' => ['type' => 'text'],
    ]],
];

it('collects media ids from a scalar image leaf, ignoring non-image props', function () use ($heroSchema): void {
    $props = ['heading' => 'Hi', 'image_url' => 5, 'cta_url' => '/x'];

    expect(SchemaImages::ids($props, $heroSchema))->toBe([5]);
});

it('collects media ids from every object in a list, de-duplicated', function () use ($partnersSchema): void {
    $props = ['logos' => [
        ['image_url' => 7, 'name' => 'A'],
        ['image_url' => 8, 'name' => 'B'],
        ['image_url' => 7, 'name' => 'C'],   // dup id
    ]];

    expect(SchemaImages::ids($props, $partnersSchema))->toBe([7, 8]);
});

it('ignores non-int image values (a legacy URL string) and absent props', function () use ($heroSchema): void {
    expect(SchemaImages::ids(['image_url' => 'https://old/x.png'], $heroSchema))->toBe([]);
    expect(SchemaImages::ids(['heading' => 'Hi'], $heroSchema))->toBe([]);
});

it('replaces the scalar image leaf with its resolved URL, leaving other props intact', function () use ($heroSchema): void {
    $props = ['heading' => 'Hi', 'image_url' => 5, 'cta_url' => '/x'];

    expect(SchemaImages::replace($props, $heroSchema, [5 => 'https://cdn/5.webp']))
        ->toBe(['heading' => 'Hi', 'image_url' => 'https://cdn/5.webp', 'cta_url' => '/x']);
});

it('passes a legacy URL-string image through unchanged (expand-phase back-compat)', function () use ($heroSchema): void {
    $props = ['heading' => 'Hi', 'image_url' => 'https://legacy.cdn/about.jpg'];

    expect(SchemaImages::replace($props, $heroSchema, []))
        ->toBe(['heading' => 'Hi', 'image_url' => 'https://legacy.cdn/about.jpg']);
});

it('replaces each image inside a list and nulls an unresolved id (fail-safe)', function () use ($partnersSchema): void {
    $props = ['logos' => [
        ['image_url' => 7, 'name' => 'A'],
        ['image_url' => 9, 'name' => 'B'],   // 9 absent from map → null
    ]];

    expect(SchemaImages::replace($props, $partnersSchema, [7 => 'https://cdn/7.webp']))
        ->toBe(['logos' => [
            ['image_url' => 'https://cdn/7.webp', 'name' => 'A'],
            ['image_url' => null, 'name' => 'B'],
        ]]);
});
