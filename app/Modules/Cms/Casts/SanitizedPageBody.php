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

    /**
     * Equal when the two bodies show the same page, so a save that keeps the body writes no body and
     * leaves its content mark (TracksContentChange). The column is jsonb: PostgreSQL hands an object's
     * keys back in its own order, while the editor writes them in the order it built them, so the
     * keys of an object are compared in any order (a list keeps its order: it is the order on the
     * page). The editor also turns a stored "6" for a number prop into 6; a number and a string
     * that spells exactly that number count as the same value. Nothing else is equated.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return self::same(self::decode($firstValue), self::decode($secondValue));
    }

    /** One decoded JSON value against another, by the rule of compare(). */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }
            if (array_is_list($a)) {
                foreach ($a as $i => $value) {
                    if (! self::same($value, $b[$i])) {
                        return false;
                    }
                }

                return true;
            }
            foreach ($a as $k => $value) {
                if (! array_key_exists($k, $b) || ! self::same($value, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        if ($a === $b) {
            return true;
        }

        return self::spells($a, $b) || self::spells($b, $a);
    }

    /** Whether $text is exactly how PHP writes the number $number ("6" for 6, "1.5" for 1.5). */
    private static function spells(mixed $number, mixed $text): bool
    {
        return (is_int($number) || is_float($number)) && is_string($text) && $text === (string) $number;
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
