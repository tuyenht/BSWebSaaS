<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\Facades\Validator;

/*
 * W5 — Page-JSON server-side validation (ADR-08). The allow-list is the shared
 * ComponentRegistry, so render and validation can never drift: a type the renderer
 * would skip is a type validation rejects.
 */

/**
 * @param  array<int, mixed>  $blocks
 */
function validateBlocks(array $blocks): Illuminate\Validation\Validator
{
    return Validator::make(['blocks' => $blocks], ['blocks' => [app(ValidPageJson::class)]]);
}

it('passes a page composed only of registered components', function () {
    $validator = validateBlocks([
        ['type' => 'hero', 'props' => ['heading' => 'Xin chào']],
        ['type' => 'latest_posts', 'props' => ['limit' => 3]],
        ['type' => 'cta', 'props' => []],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects an unknown block type (block lạ) and names it', function () {
    $validator = validateBlocks([['type' => 'evil_script', 'props' => []]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('blocks'))->toContain('evil_script');
});

it('rejects a block missing its type', function () {
    expect(validateBlocks([['props' => ['x' => 1]]])->fails())->toBeTrue();
});

it('rejects malformed props that are not an object', function () {
    expect(validateBlocks([['type' => 'hero', 'props' => 'not-an-object']])->fails())->toBeTrue();
});

it('rejects a non-array block entry', function () {
    expect(validateBlocks(['just-a-string'])->fails())->toBeTrue();
});

it('stays in lockstep with the renderer allow-list (DRY)', function () {
    $registry = app(ComponentRegistry::class);

    // Every type the renderer can dispatch also validates …
    foreach ($registry->types() as $type) {
        expect(validateBlocks([['type' => $type, 'props' => []]])->passes())
            ->toBeTrue("registered type '{$type}' should validate");
    }

    // … and a type it cannot dispatch is rejected — one source of truth.
    expect(validateBlocks([['type' => 'definitely_not_registered', 'props' => []]])->fails())->toBeTrue();
});

it('rejects a dangerous URL scheme in a url prop (stored-XSS guard)', function () {
    expect(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'javascript:alert(document.cookie)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => "java\tscript:alert(1)"]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'data:text/html,<script>']]])->fails())->toBeTrue();
});

it('allows safe URL schemes in url props', function () {
    expect(validateBlocks([['type' => 'cta', 'props' => ['button_url' => '/lien-he']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'https://example.test/x']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'mailto:a@b.test']]])->passes())->toBeTrue();
});

it('rejects a body with too many blocks', function () {
    expect(validateBlocks(array_fill(0, 101, ['type' => 'hero', 'props' => []]))->fails())->toBeTrue();
});

it('rejects an oversized body', function () {
    expect(validateBlocks([['type' => 'hero', 'props' => ['heading' => str_repeat('x', 300_000)]]])->fails())->toBeTrue();
});
