<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaHtml;
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
 * True when $key is declared `type:url` or `type:image` inside any list `of` field-map in the
 * schema (handles object lists + nesting) — the two schema types ValidPageJson proves safe for an
 * href/src sink. Used by the sink sentinel for $item['…'] sinks.
 *
 * @param  array<array-key, mixed>  $schema
 */
function schemaHasSafeSinkField(array $schema, string $key): bool
{
    foreach ($schema as $spec) {
        if (! is_array($spec) || ($spec['type'] ?? null) !== 'list' || ! is_array($spec['of'] ?? null)) {
            continue;
        }
        $of = $spec['of'];
        if (in_array($of[$key]['type'] ?? null, ['url', 'image'], true) || schemaHasSafeSinkField($of, $key)) {
            return true;
        }
    }

    return false;
}

it('passes a page composed only of registered components', function (): void {
    $validator = validateBlocks([
        ['type' => 'hero', 'props' => ['heading' => 'Xin chào']],
        ['type' => 'latest_posts', 'props' => ['limit' => 3]],
        ['type' => 'cta', 'props' => []],
    ]);

    expect($validator->passes())->toBeTrue();
});

it('rejects an unknown block type (block lạ) and names it', function (): void {
    $validator = validateBlocks([['type' => 'evil_script', 'props' => []]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('blocks'))->toContain('evil_script');
});

it('rejects a block missing its type', function (): void {
    expect(validateBlocks([['props' => ['x' => 1]]])->fails())->toBeTrue();
});

it('rejects malformed props that are not an object', function (): void {
    expect(validateBlocks([['type' => 'hero', 'props' => 'not-an-object']])->fails())->toBeTrue();
});

it('rejects a non-array block entry', function (): void {
    expect(validateBlocks(['just-a-string'])->fails())->toBeTrue();
});

it('stays in lockstep with the renderer allow-list (DRY)', function (): void {
    $registry = app(ComponentRegistry::class);

    // Every type the renderer can dispatch also validates (a type only a block slot may hold validates through the slot guard) …
    foreach ($registry->types() as $type) {
        $guard = $registry->slotOnly($type) ? app(ValidPageJson::class)->forSlots() : app(ValidPageJson::class);
        expect(Validator::make(['blocks' => [['type' => $type, 'props' => []]]], ['blocks' => [$guard]])->passes())
            ->toBeTrue("registered type '{$type}' should validate");
    }
    expect(validateBlocks([['type' => 'recent_posts', 'props' => []]])->fails())->toBeTrue();

    // … and a type it cannot dispatch is rejected — one source of truth.
    expect(validateBlocks([['type' => 'definitely_not_registered', 'props' => []]])->fails())->toBeTrue();
});

it('rejects a dangerous URL scheme in a url prop (stored-XSS guard)', function (): void {
    expect(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'javascript:alert(document.cookie)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => "java\tscript:alert(1)"]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'data:text/html,<script>']]])->fails())->toBeTrue();
});

it('refuses a look that is not in its list and accepts a listed one or none', function (): void {
    expect(validateBlocks([['type' => 'entry_grid', 'props' => ['variant' => 'icon-cards', 'bg' => 'tint']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['variant' => null]]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'features', 'props' => []]])->passes())->toBeTrue();

    foreach ([['entry_grid', 'variant', 'carousel'], ['product_grid', 'bg', 'neon'], ['contact', 'variant', 'Cards'], ['cta', 'variant', 1]] as [$type, $prop, $value]) {
        $validator = validateBlocks([['type' => $type, 'props' => [$prop => $value]]]);
        expect($validator->fails())->toBeTrue("{$type}.{$prop}={$value} should fail")
            ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_block_option_invalid', ['type' => $type]));
    }
});

it('checks a choice inside a list item too', function (): void {
    registerSchemaBlock('test_enum_list', ['items' => ['type' => 'list', 'of' => ['look' => ['type' => 'enum', 'options' => ['a', 'b'], 'default' => 'a']]]]);

    expect(validateBlocks([['type' => 'test_enum_list', 'props' => ['items' => [['look' => 'b']]]]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_enum_list', 'props' => ['items' => [['look' => 'c']]]]])->fails())->toBeTrue();
});

it('allows safe URL schemes in url props', function (): void {
    expect(validateBlocks([['type' => 'cta', 'props' => ['button_url' => '/lien-he']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'https://example.test/x']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'cta', 'props' => ['button_url' => 'mailto:a@b.test']]])->passes())->toBeTrue();
});

it('rejects a body with too many blocks', function (): void {
    expect(validateBlocks(array_fill(0, 101, ['type' => 'hero', 'props' => []]))->fails())->toBeTrue();
});

it('rejects an oversized body', function (): void {
    expect(validateBlocks([['type' => 'hero', 'props' => ['heading' => str_repeat('x', 300_000)]]])->fails())->toBeTrue();
});

it('measures the body in UTF-8 bytes, so a long Vietnamese article fits', function (): void {
    // 60,000 accented letters: 180 KB as UTF-8, 360 KB with every letter escaped to \uXXXX.
    $article = '<p>'.str_repeat('ệ', 60_000).'</p>';

    expect(strlen((string) json_encode($article)))->toBeGreaterThan(262_144)
        ->and(validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $article]]])->passes())->toBeTrue();
});

it('refuses a body over 256 KB of UTF-8 in plain words, even when each prop is within its own cap', function (): void {
    // 90,000 letters: under the 100,000-character html cap, 270 KB as UTF-8.
    $article = str_repeat('ệ', 90_000);
    $validator = validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $article]]]);

    expect(mb_strlen($article))->toBeLessThan(ValidPageJson::MAX_HTML_CHARS)
        ->and($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_body_too_large'));
});

it('refuses a body that is not valid UTF-8 instead of letting it past the size check', function (): void {
    $validator = validateBlocks([['type' => 'hero', 'props' => ['heading' => "Ti\xC3\x28u đề"]]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_blocks_invalid'));
});

it('rejects a dangerous scheme in a typed-url prop regardless of its NAME (schema-driven)', function (): void {
    // §2 invariant: URL-safety must follow the schema's type:url, NOT a hand-maintained key
    // list. A url prop named 'link' (not in url/href/src/*_url) must STILL be sniffed.
    registerSchemaBlock('test_link', ['link' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'JaVaScRiPt:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_link', 'props' => ['link' => 'https://example.test/ok']]])->passes())->toBeTrue();
});

it('sniffs typed-url props nested 2+ levels deep via the schema walk (object lists + scalar url lists)', function (): void {
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

it('rejects a non-string value under a typed-url prop (no render-time TypeError/500)', function (): void {
    registerSchemaBlock('test_arr', ['cta_url' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_arr', 'props' => ['cta_url' => ['javascript:alert(1)']]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_arr', 'props' => ['cta_url' => 42]]])->fails())->toBeTrue();
});

it('treats url-encoded and protocol-relative URLs as non-XSS (documented: not a §2 scheme violation)', function (): void {
    // Browsers do not percent-decode the scheme position, so %6a%61… is an inert relative URL;
    // // is external navigation (open-redirect surface) by a trusted tenant admin, not script
    // execution. Both pass the §2 (no script-scheme) guard by design.
    registerSchemaBlock('test_doc', ['link' => ['type' => 'url']]);

    expect(validateBlocks([['type' => 'test_doc', 'props' => ['link' => '%6a%61vascript:alert(1)']]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'test_doc', 'props' => ['link' => '//evil.example']]])->passes())->toBeTrue();
});

it('every author href/src sink in a section partial is a schema-declared url prop (drift sentinel)', function (): void {
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
        // The ONE exception is the formatted-text block: its `html` prop is sanitized on every write
        // (the body casts, ADR-19) and prepared by RichHtml before the partial sees it, so the
        // partial may print exactly that one value raw, once.
        if ($type === 'richtext_html') {
            expect(substr_count($blade, '{!!'))->toBe(1)
                ->and($blade)->toContain('{!! $html !!}')
                ->and($blade)->toContain("\$html = is_string(\$props['html'] ?? null)");
        } else {
            expect(str_contains($blade, '{!!'))->toBeFalse("{$type}.blade: section partials must not use {!! !!} (unescaped author output)");
        }

        // Top-level author sinks: href/src reading $props['KEY'] must be schema type:url.
        preg_match_all('/(?:href|src)\s*=\s*["\']\{\{[^}]*\$props\[[\'"](\w+)[\'"]\]/', $blade, $top);
        foreach ($top[1] as $key) {
            expect(in_array($schema[$key]['type'] ?? null, ['url', 'image'], true))
                ->toBeTrue("{$type}.blade: \$props['{$key}'] feeds href/src but isn't a type:url|image sink in the schema");
        }

        // List-item author sinks: href/src reading $item['KEY'] must be a type:url list field.
        preg_match_all('/(?:href|src)\s*=\s*["\']\{\{[^}]*\$item\[[\'"](\w+)[\'"]\]/', $blade, $item);
        foreach ($item[1] as $key) {
            expect(schemaHasSafeSinkField($schema, $key))->toBeTrue("{$type}.blade: \$item['{$key}'] feeds href/src but isn't a type:url|image list field");
        }
    }
});

it('recurses into a partners logo item: url scheme-checks, image must be a media_id (no static URL)', function (): void {
    // §2: ValidPageJson recurses into logos[]. The `url` field sniffs script schemes (stored-XSS
    // guard); the `image_url` field is a media_id — ANY string is rejected (no static URL), even a
    // "safe" one — so the picker's rule holds against a crafted wire payload. A valid logo carries an
    // int image id + a safe url.
    expect(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['url' => 'javascript:alert(1)']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => 'https://cdn.test/p.png']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => 123, 'url' => '/ok']]]]])->passes())->toBeTrue();
});

it('rejects a static URL string in an image prop, accepting a media_id int or null', function (): void {
    // The "choose from library, no static URL" rule, enforced at the save boundary (not only in the
    // editor UI): an image prop is a media_id or unset — a URL string is never valid there.
    expect(validateBlocks([['type' => 'hero', 'props' => ['image_url' => 'https://cdn.test/hero.jpg']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'hero', 'props' => ['image_url' => 42]]])->passes())->toBeTrue()
        ->and(validateBlocks([['type' => 'hero', 'props' => ['image_url' => null]]])->passes())->toBeTrue();
});

it('rejects a non-scalar value under a text/textarea prop (no render-time TypeError/500)', function (): void {
    // ADR-08 boundary completeness: a text prop renders through e(); a non-scalar (array) would
    // crash htmlspecialchars and 500 the published page, so the wire-payload guard rejects it —
    // at top level, inside an object-list item, and inside a scalar list.
    expect(validateBlocks([['type' => 'hero', 'props' => ['heading' => ['x']]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'partners', 'props' => ['logos' => [['image_url' => 123, 'name' => ['x']]]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'richtext', 'props' => ['paragraphs' => [['nested']]]]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'hero', 'props' => ['heading' => 'Tiêu đề hợp lệ']]])->passes())->toBeTrue();
});

it('rejects a dangerous URL scheme in a contact phone/email/map prop', function (): void {
    // phone/email render into tel:/mailto: hrefs and map_embed into an iframe src — all type:url.
    expect(validateBlocks([['type' => 'contact', 'props' => ['phone' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['email' => 'javascript:alert(1)']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['map_embed' => 'data:text/html,<script>']]])->fails())->toBeTrue()
        ->and(validateBlocks([['type' => 'contact', 'props' => ['phone' => '0901234567', 'email' => 'a@b.test', 'map_embed' => 'https://maps.example/e']]])->passes())->toBeTrue();
});

describe('formatted text (html props)', function (): void {
    it('declares the html prop type on the formatted-text block only', function (): void {
        // html is printed without escaping: a second block declaring it would be a second raw sink
        // the sink sentinel and the write-path test were not written for.
        $registry = app(ComponentRegistry::class);
        $declaring = array_values(array_filter($registry->types(), fn (string $type): bool => SchemaHtml::declares($registry->schemaFor($type))));

        expect($declaring)->toBe(['richtext_html'])
            ->and($registry->schemaFor('richtext_html')['html'])->toBe(['type' => 'html']);
    });

    it('accepts a string or nothing in an html prop', function (mixed $html): void {
        expect(validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $html]]])->passes())->toBeTrue();
    })->with([
        'formatted text' => '<h2>Câu chuyện</h2><p>Thành lập <strong>2009</strong>.</p>',
        'empty' => '',
        'unset' => null,
    ]);

    it('refuses an html prop that is not text, in plain words', function (mixed $html): void {
        $validator = validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $html]]]);

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_block_html_invalid'));
    })->with([
        'a list' => [['<p>x</p>']],
        'an object' => [['tag' => 'p']],
        'a number' => 42,
        'true' => true,
    ]);

    it('refuses formatted text over the length cap, saying how long it may be', function (): void {
        $atCap = str_repeat('a', ValidPageJson::MAX_HTML_CHARS);
        $validator = validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $atCap.'a']]]);

        expect(validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $atCap]]])->passes())->toBeTrue()
            ->and($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_block_html_too_long', ['max' => ValidPageJson::MAX_HTML_CHARS]));
    });

    it('counts the cap in characters, so Vietnamese text is not cut short by its bytes', function (): void {
        $vietnamese = str_repeat('ệ', ValidPageJson::MAX_HTML_CHARS);

        expect(strlen($vietnamese))->toBeGreaterThan(ValidPageJson::MAX_HTML_CHARS)
            ->and(validateBlocks([['type' => 'richtext_html', 'props' => ['html' => $vietnamese]]])->errors()->first('blocks'))
            ->not->toBe(__('entries.err_block_html_too_long', ['max' => ValidPageJson::MAX_HTML_CHARS]));
    });

    it('checks only the structure: markup that the write path will clean is not refused here', function (): void {
        // Sanitizing is the body casts' job (ADR-19 pt 3), never the validator's.
        expect(validateBlocks([['type' => 'richtext_html', 'props' => ['html' => '<p onclick="x">a</p><script>1</script>']]])->passes())->toBeTrue();
    });
});
