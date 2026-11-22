<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;

/**
 * Every stored version of one post or page as the language bar needs it (LangBar), in one query that
 * reads no body: how many of the translatable fields are filled, when the version's content last
 * changed, and whether it is published.
 *
 * The translatable fields are the board's (07): title, link, short description, the Google title,
 * the Google description and the body. A body counts as filled when it holds a block other than
 * written text, or written text with a word or a picture in it (a space, a non-breaking one included, is not a word). When the content changed is the
 * version's content mark (TracksContentChange: a change of status or of the author's name leaves
 * it), or its updated_at while it has none.
 */
final class EntryLangStates
{
    public const int FIELDS = 6;

    /**
     * @return array<string, array{done: int, mark: CarbonImmutable|null, live: bool}>
     */
    public static function read(int $entryId): array
    {
        $prose = EntryProseBody::TYPE;
        $filled = "(btrim(title) <> '')::int + (btrim(slug) <> '')::int"
            ." + (btrim(coalesce(excerpt, '')) <> '')::int + (btrim(coalesce(seo_title, '')) <> '')::int"
            ." + (btrim(coalesce(seo_description, '')) <> '')::int"
            ." + (case when jsonb_typeof(body->'blocks') = 'array' and exists (select 1 from jsonb_array_elements(body->'blocks') as b(block)"
            ." where block->>'type' is distinct from '{$prose}'"
            ." or regexp_replace(coalesce(block->'props'->>'html', ''), '<[^>]*>|&nbsp;|\\s|\\u00a0', '', 'g') <> ''"
            ." or coalesce(block->'props'->>'html', '') ~* '<(img|video|iframe)') then 1 else 0 end)";

        $rows = EntryTranslation::query()
            ->where('entry_id', $entryId)
            ->select('locale')
            ->selectRaw($filled.' as filled')
            ->selectRaw('coalesce(content_changed_at, updated_at) as changed')
            ->selectRaw("status = 'published' as live")
            ->toBase()
            ->get();

        $states = [];
        foreach ($rows as $row) {
            $changed = Cast::stringOrNull($row->changed ?? null);
            $states[Cast::string($row->locale ?? null)] = [
                'done' => Cast::int($row->filled ?? null),
                'mark' => $changed !== null ? CarbonImmutable::parse($changed) : null,
                'live' => Cast::bool($row->live ?? null),
            ];
        }

        return $states;
    }
}
