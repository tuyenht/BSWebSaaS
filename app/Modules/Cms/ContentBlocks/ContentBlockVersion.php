<?php

declare(strict_types=1);

namespace App\Modules\Cms\ContentBlocks;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;

/**
 * The version token of a reusable block as the screen saw it: a hash of its code, name, region,
 * status and every language's body. Whatever changes the block (this screen in another tab, another
 * editor, a publish) changes the token, with no writer having to bump a counter; a rename or a body
 * saved against an older token is refused (version_stale). JSON objects are hashed with their keys
 * sorted, so a body reads the same before and after the database stores it.
 */
final class ContentBlockVersion
{
    /**
     * @param  iterable<ContentBlockTranslation>  $translations  the block's languages
     */
    public static function of(ContentBlock $block, iterable $translations): string
    {
        $bodies = [];
        foreach ($translations as $translation) {
            $bodies[$translation->locale] = self::sorted($translation->body);
        }
        ksort($bodies);

        return hash('xxh128', (string) json_encode([$block->key, $block->name, $block->region, $block->status, $bodies]));
    }

    /** The token of the block as it is stored now (one query for its languages). */
    public static function current(ContentBlock $block): string
    {
        return self::of($block, ContentBlockTranslation::query()
            ->where('content_block_id', $block->id)
            ->get(['locale', 'body']));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::sorted($inner);
            }
        }

        return $value;
    }
}
