<?php

declare(strict_types=1);

namespace App\Modules\Cms\Casts;

use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaHtml;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The JSON cast of the two columns that hold a page or post body (ADR-19): entry_translations.body
 * (`{"blocks": […]}`) and entry_drafts.content (the same body per language under
 * `locales.<locale>.body`, cast as `SanitizedPageBody::class.':draft'`). Reading is the plain
 * `array` cast. Writing runs every `html` prop of every registered block — found through the
 * component registry, so a new html prop is covered the day it is declared — through
 * RichTextSanitizer::cms before the JSON is stored. Because the cast sits on the model, every road
 * that saves through it (editor, pending change, approval, restore, duplicate, content pack,
 * seeders, a future AI writer or import) stores only clean HTML without having to remember to;
 * the roads that would skip the model are banned by PageBodyWriteChokepointTest.
 *
 * A block of a type the registry does not know is left untouched: it never renders, and the
 * editor's validation (ValidPageJson) refuses it. Holds no state, so it is safe under Octane.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
final readonly class SanitizedPageBody implements CastsAttributes, ComparesCastableAttributes
{
    public function __construct(private string $shape = 'body') {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $decoded = self::decode($value);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // A JSON string is cleaned like the array it holds, so raw JSON cannot slip past the cast.
        $decoded = is_string($value) ? self::decode($value) : $value;
        if (is_array($decoded)) {
            $decoded = $this->shape === 'draft' ? self::cleanDraft($decoded) : self::cleanBody($decoded);
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    /** Same rule as the `array` cast: equal when the stored JSON decodes to the same value. */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return self::decode($firstValue) === self::decode($secondValue);
    }

    /**
     * A body with each html prop of each registered block sanitized.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function cleanBody(array $body): array
    {
        $blocks = $body['blocks'] ?? null;
        if (! is_array($blocks)) {
            return $body;
        }

        $registry = app(ComponentRegistry::class);
        $sanitizer = app(RichTextSanitizer::class);

        foreach ($blocks as $i => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! $registry->has($type) || ! is_array($block['props'] ?? null)) {
                continue;
            }

            $schema = $registry->schemaFor($type);
            if (SchemaHtml::declares($schema)) {
                $block['props'] = SchemaHtml::map($block['props'], $schema, $sanitizer->cms(...));
                $blocks[$i] = $block;
            }
        }
        $body['blocks'] = $blocks;

        return $body;
    }

    /**
     * A pending change with the body of each of its languages sanitized.
     *
     * @param  array<array-key, mixed>  $content
     * @return array<array-key, mixed>
     */
    public static function cleanDraft(array $content): array
    {
        $locales = $content['locales'] ?? null;
        if (! is_array($locales)) {
            return $content;
        }

        foreach ($locales as $locale => $text) {
            if (is_array($text) && is_array($text['body'] ?? null)) {
                $text['body'] = self::cleanBody($text['body']);
                $locales[$locale] = $text;
            }
        }
        $content['locales'] = $locales;

        return $content;
    }

    private static function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
