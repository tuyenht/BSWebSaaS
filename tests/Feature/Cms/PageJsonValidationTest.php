<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionComponent;
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

/**
 * Register an ad-hoc block whose declared schema drives validation — lets us assert that
 * URL-safety follows the schema's type:url, independent of the prop NAME.
 *
 * @param  array<string, array<string, mixed>>  $schema
 */
function registerSchemaBlock(string $type, array $schema): void
{
    app(ComponentRegistry::class)->register($type, new SectionComponent('theme::noop', $schema));
}

/**
 * True when $key is declared `type:url` inside any list `of` field-map in the schema
 * (handles object lists + nesting) — used by the sink sentinel for $item['…'] sinks.
 *
 * @param  array<array-key, mixed>  $schema
 */
function schemaHasUrlField(array $schema, string $key): bool
{
    foreach ($schema as $spec) {
        if (! is_array($spec) || ($spec['type'] ?? null) !== 'list' || ! is_array($spec['of'] ?? null)) {
            continue;
        }
        $of = $spec['of'];
        if (($of[$key]['type'] ?? null) === 'url' || schemaHasUrlField($of, $key)) {
            return true;
        }
    }

    return false;
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

it('rejects a dangerous scheme in a typed-url prop regardless of its NAME (schema-driven)', function () {
    // §2 invariant: URL-safety must follow the schema's type:url, NOT a hand-maintained key
    // list. A url prop named 'link' (not in url/href/src/*_url) must STILL be sniffed.
    registerSchemaBlock('test_link', ['link' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'JaVaScRiPt:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'https://example.test/ok']]])->passes())->toBeTrue();
});

it('sniffs typed-url props nested 2+ levels deep via the schema walk (object lists + scalar url lists)', function () {
    registerSchemaBlock('test_deep', [
        'rows' => ['type' => 'list', 'of' => [
            'cta_link' => ['type' => 'url'],
            'subs' => ['type' => 'list', 'of' => ['type' => 'url']],
        ]],
    ]);

    expect(validateBlocks([['type' => 'test_deep', 'props' => ['rows' => [['cta_link' => 'javascript:alert(1)']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_deep', 'props' => ['rows' => [['subs' => ['vbscript:msgbox(1)']]]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_deep', 'props' => ['rows' => [['cta_link' => '/ok', 'subs' => ['https://x.test']]]]]])->passes())->toBeTrue();
});

it('rejects a non-string value under a typed-url prop (no render-time TypeError/500)', function () {
    registerSchemaBlock('test_arr', ['cta_url' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_arr', 'props' => ['cta_url' => ['javascript:alert(1)']]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_arr', 'props' => ['cta_url' => 42]]])->fails())->toBeTrue();
});

it('treats url-encoded and protocol-relative URLs as non-XSS (documented: not a §2 scheme violation)', function () {
    // Browsers do not percent-decode the scheme position, so %6a%61… is an inert relative URL;
    // // is external navigation (open-redirect surface) by a trusted tenant admin, not script
    // execution. Both pass the §2 (no script-scheme) guard by design.
    registerSchemaBlock('test_doc', ['link' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_doc', 'props' => ['link' => '%6a%61vascript:alert(1)']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_doc', 'props' => ['link' => '//evil.example']]])->passes())->toBeTrue();
});

it('every author href/src sink in a section partial is a schema-declared url prop (drift sentinel)', function () {
    // The schema-driven validator scheme-checks props it types `url`. This sentinel keeps the
    // statically-visible SINKS in lockstep: `{{ $props['k'] }}` / `{{ $item['k'] }}` in an href/src
    // must be a type:url prop. It cannot see alias vars or non-href/src URL attributes, so it ALSO
    // forbids `{!! !!}` in section partials — every author value must render through e(), the
    // assumption the rest of the URL-safety story rests on.
    $registry = app(ComponentRegistry::class);

    foreach (glob(base_path('themes/default/sections/*.blade.php')) as $file) {
        $type = basename($file, '.blade.php');
        if (! $registry->has($type)) {
            continue;
        }
        $schema = $registry->get($type)->schema();
        $blade = (string) file_get_contents($file);

        // No unescaped output in a section partial — author props must always render through e().
        expect(str_contains($blade, '{!!'))->toBeFalse("{$type}.blade: section partials must not use {!! !!} (unescaped author output)");

        // Top-level author sinks: href/src reading $props['KEY'] must be schema type:url.
        preg_match_all('/(?:href|src)\s*=\s*["\']\{\{[^}]*\$props\[[\'"](\w+)[\'"]\]/', $blade, $top);
        foreach ($top[1] as $key) {
            expect($schema[$key]['type'] ?? null)->toBe('url', "{$type}.blade: \$props['{$key}'] feeds href/src but isn't type:url in the schema");
        }

        // List-item author sinks: href/src reading $item['KEY'] must be a type:url list field.
        preg_match_all('/(?:href|src)\s*=\s*["\']\{\{[^}]*\$item\[[\'"](\w+)[\'"]\]/', $blade, $item);
        foreach ($item[1] as $key) {
            expect(schemaHasUrlField($schema, $key))->toBeTrue("{$type}.blade: \$item['{$key}'] feeds href/src but isn't a type:url list field");
        }
    }
});

it('rejects a dangerous URL scheme inside a partners logo item (list recursion)', function () {
    // §2: ValidPageJson recurses into the logos[] list, so an item's url/image_url is sniffed
    // for script schemes — the stored-XSS guard reaches array-item URLs, not just scalar props.
    expect(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['url' => 'javascript:alert(1)']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => 'javascript:alert(1)']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => 'https://cdn.test/p.png', 'url' => '/ok']]]]])->passes())->toBeTrue();
});

it('rejects a non-scalar value under a text/textarea prop (no render-time TypeError/500)', function () {
    // ADR-08 boundary completeness: a text prop renders through e(); a non-scalar (array) would
    // crash htmlspecialchars and 500 the published page, so the wire-payload guard rejects it —
    // at top level, inside an object-list item, and inside a scalar list.
    expect(validateBlocks([['type' => 'hero', 'props' => ['heading' => ['x']]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => '/ok.png', 'name' => ['x']]]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'richtext', 'props' => ['paragraphs' => [['nested']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'hero', 'props' => ['heading' => 'Tiêu đề hợp lệ']]])->passes())->toBeTrue();
});

it('rejects a dangerous URL scheme in a contact phone/email/map prop', function () {
    // phone/email render into tel:/mailto: hrefs and map_embed into an iframe src — all type:url.
    expect(validateBlocks([['type' => 'contact', 'props' => ['phone' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['email' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['map_embed' => 'data:text/html,<script>']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['phone' => '0901234567', 'email' => 'a@b.test', 'map_embed' => 'https://maps.example/e']]])->passes())->toBeTrue();
});
