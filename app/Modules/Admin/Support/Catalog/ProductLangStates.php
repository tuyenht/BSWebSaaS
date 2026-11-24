<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Catalog;

use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;

/**
 * Every stored version of one product as the language bar needs it (LangBar), in one query that
 * reads no description: how many of the translatable fields are filled and when the version's
 * content last changed.
 *
 * The translatable fields are the product's own (board 09): name, link, short description, the
 * description, the Google title and the Google description. A description counts as filled when it
 * holds a word or a picture, a video or an embed; tags and spaces alone, non-breaking ones included,
 * fill no field. When the content changed is the version's content mark (TracksContentChange), or
 * its updated_at while it has none. A product is published as a whole: whether a version is on the
 * website is the product's status, which the screen knows already.
 */
final class ProductLangStates
{
    public const int FIELDS = 6;

    /** How many of the six are filled: each plain field holds a character other than a space; the description a word or a medium. */
    private const string FILLED = "(regexp_replace(coalesce(name, ''), '\\s|\\u00a0', '', 'g') <> '')::int"
        ." + (regexp_replace(coalesce(slug, ''), '\\s|\\u00a0', '', 'g') <> '')::int"
        ." + (regexp_replace(coalesce(summary, ''), '\\s|\\u00a0', '', 'g') <> '')::int"
        ." + (regexp_replace(coalesce(seo_title, ''), '\\s|\\u00a0', '', 'g') <> '')::int"
        ." + (regexp_replace(coalesce(seo_description, ''), '\\s|\\u00a0', '', 'g') <> '')::int"
        ." + (regexp_replace(coalesce(description, ''), '<[^>]*>|&nbsp;|\\s|\\u00a0', '', 'g') <> ''"
        ." or coalesce(description, '') ~* '<(img|video|iframe)')::int";

    /**
     * @return array<string, array{done: int, mark: CarbonImmutable|null, live: bool}>
     */
    public static function read(int $productId, bool $live): array
    {
        $rows = ProductTranslation::query()
            ->where('product_id', $productId)
            ->select('locale')
            ->selectRaw(self::FILLED.' as filled')
            ->selectRaw('coalesce(content_changed_at, updated_at) as changed')
            ->toBase()
            ->get();

        $states = [];
        foreach ($rows as $row) {
            $changed = Cast::stringOrNull($row->changed ?? null);
            $states[Cast::string($row->locale ?? null)] = [
                'done' => Cast::int($row->filled ?? null),
                'mark' => $changed !== null ? CarbonImmutable::parse($changed) : null,
                'live' => $live,
            ];
        }

        return $states;
    }
}
