<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormPlacement;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

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

        return self::write($siteId, $entryId, $locale, array_values($want), array_values($have));
    }

    /**
     * Write the rows of one page language from the form ids it shows ($want) and has rows for ($have),
     * both already read (the reconcile reads them for a whole chunk at once).
     *
     * @param  list<int>  $want
     * @param  list<int>  $have
     * @return bool whether a row was added or removed
     */
    public static function write(int $siteId, int $entryId, string $locale, array $want, array $have): bool
    {
        $gone = array_values(array_diff($have, $want));
        $new = array_values(array_unique(array_diff($want, $have)));
        $removed = $gone === [] ? 0 : Cast::int(FormPlacement::query()->where('entry_id', $entryId)->where('locale', $locale)->whereIn('form_id', $gone)->delete());
        $added = 0;
        if ($new !== []) {
            // The rows come from the site's forms as they are now, each row-locked (FOR KEY SHARE, the
            // lock the foreign-key check takes): a form deleted at the same moment is skipped, or waits
            // for this save and then takes its rows with it — never a foreign-key error that aborts the
            // page save. A row already there is left as it is.
            $now = now();
            $added = DB::affectingStatement(
                'insert into form_placements (site_id, form_id, entry_id, locale, created_at, updated_at)
                 select f.site_id, f.id, ?, ?, ?, ? from forms f where f.site_id = ? and f.id = any(?::bigint[]) for key share
                 on conflict do nothing',
                [$entryId, $locale, $now, $now, $siteId, '{'.implode(',', $new).'}'],
            );
        }

        return $removed > 0 || $added > 0;
    }

    /**
     * A form just made (PlaceNewForm, queued after its save): the page languages of its site whose
     * blocks already name its key — a form deleted and made again under the same key, or the contact
     * form made after pages that show it — get their rows now, as no page save would write them. The
     * match is keysIn()'s: a key that is not a non-empty string is the contact form, and `.type()` keeps
     * lax mode from unwrapping an array where keysIn() would not. Reads the site's
     * bodies once, filtered by the database; forms are made rarely (a handful per site).
     */
    public static function formCreated(Form $form): void
    {
        $match = $form->key === Form::SYSTEM_KEY
            ? '$.blocks[*] ? (@.type.type() == "string" && @.type == "form" && !(@.props.type() == "object" && @.props.form_key.type() == "string" && @.props.form_key != "" && @.props.form_key != $key))'
            : '$.blocks[*] ? (@.type.type() == "string" && @.type == "form" && @.props.type() == "object" && @.props.form_key.type() == "string" && @.props.form_key == $key)';
        $now = now();
        $rows = EntryTranslation::query()->where('site_id', $form->site_id)
            ->whereRaw('jsonb_path_exists(body, ?::jsonpath, jsonb_build_object(\'key\', ?::text))', [$match, $form->key])
            ->get(['entry_id', 'locale'])
            ->map(fn (EntryTranslation $row): array => [
                'site_id' => $form->site_id, 'form_id' => $form->id, 'entry_id' => $row->entry_id, 'locale' => $row->locale,
                'created_at' => $now, 'updated_at' => $now,
            ])->all();
        if ($rows !== []) {
            FormPlacement::query()->insertOrIgnore($rows);
        }
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
