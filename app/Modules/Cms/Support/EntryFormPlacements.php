<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormPlacement;
use App\Modules\Shared\Support\Cast;

/**
 * Keeps `form_placements` (which pages show which form) equal to the «Biểu mẫu» blocks of each page
 * language, in the caller's transaction: EntryTranslation's model events call it as the body is
 * saved, created or deleted, and `forms:placements` checks every page language again. Lives in Cms,
 * which owns the bodies and may depend on Forms (Forms never names Cms).
 *
 * A form block names its form by `props.form_key`; an empty or missing key is the website's
 * contact form, as FormComponent renders it. A key no form of the site has (yet) gives no row.
 */
final class EntryFormPlacements
{
    public const string BLOCK_TYPE = 'form';

    /**
     * The keys of the forms a page body's blocks show, sorted, each once.
     *
     * @return list<string>
     */
    public static function keysIn(mixed $body): array
    {
        $blocks = is_array($body) ? ($body['blocks'] ?? null) : null;
        if (! is_array($blocks)) {
            return [];
        }

        $keys = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== self::BLOCK_TYPE) {
                continue;
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $key = $props['form_key'] ?? null;
            $keys[is_string($key) && $key !== '' ? $key : Form::SYSTEM_KEY] = true;
        }
        $keys = array_map('strval', array_keys($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Write the rows of one language of one page of $siteId to match $keys: the rows of forms it no
     * longer shows go, the forms it now shows get one. Reads first and writes only what differs, so
     * a save that changes nothing about its forms writes nothing.
     *
     * @param  list<string>  $keys
     * @return bool whether a row was added or removed
     */
    public static function put(int $siteId, int $entryId, string $locale, array $keys): bool
    {
        $want = $keys === [] ? [] : array_map(
            fn (mixed $id): int => Cast::int($id),
            Form::query()->whereIn('key', $keys)->pluck('id')->all(),
        );
        $have = array_map(
            fn (mixed $id): int => Cast::int($id),
            FormPlacement::query()->where('entry_id', $entryId)->where('locale', $locale)->pluck('form_id')->all(),
        );

        $gone = array_values(array_diff($have, $want));
        $new = array_values(array_diff($want, $have));
        if ($gone !== []) {
            FormPlacement::query()->where('entry_id', $entryId)->where('locale', $locale)->whereIn('form_id', $gone)->delete();
        }
        if ($new !== []) {
            $now = now();
            FormPlacement::query()->insertOrIgnore(array_map(fn (int $formId): array => [
                'site_id' => $siteId, 'form_id' => $formId, 'entry_id' => $entryId, 'locale' => $locale,
                'created_at' => $now, 'updated_at' => $now,
            ], $new));
        }

        return $gone !== [] || $new !== [];
    }

    /** One page language gone (deleted, or moved to another language or page): its rows go. */
    public static function forget(int $entryId, string $locale): void
    {
        FormPlacement::query()->where('entry_id', $entryId)->where('locale', $locale)->delete();
    }

    /**
     * The current site's rows of a page language that no longer exists (for `forms:placements`; a
     * delete of a language through the model forgets its rows itself). Returns how many went.
     */
    public static function dropOrphans(): int
    {
        // the languages still there, read through their model (its site scope); the delete is the placements' own
        $languages = EntryTranslation::query()->toBase()->selectRaw('1')
            ->whereColumn('entry_translations.entry_id', 'form_placements.entry_id')
            ->whereColumn('entry_translations.locale', 'form_placements.locale');

        return Cast::int(FormPlacement::query()->whereNotExists($languages)->delete());
    }
}
