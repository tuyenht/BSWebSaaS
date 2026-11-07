<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

/**
 * The places around a post, a list and a product where a site puts blocks of its own: the post's
 * side column, the band at the end of a list, of a post and of a product, and the three places on
 * a product page (before and after the order buttons, and its content tabs). The one list of them:
 * the stores, the resolver and the usage index all name a slot from here.
 *
 * Each slot's value is one block list per language, `{locale: {"blocks": […]}}`, the same blocks a
 * page body takes. The site's default lives in one setting (SETTING_GROUP / SETTING_KEY, slot →
 * value); a category may replace it in its `meta.layout_blocks.<slot>`, or hide the slot with NONE
 * (every language). Pure data, no state.
 */
final class LayoutSlots
{
    /** @var list<string> */
    public const array SLOTS = [
        'post_aside',
        'after_list',
        'after_post',
        'after_product',
        'product_before_action',
        'product_after_action',
        'product_tabs',
    ];

    /** The slots that hold at most so many blocks (a product's tabs: three). */
    public const array MAX_BLOCKS = ['product_tabs' => 3];

    /** A category's value that hides the slot in every language. */
    public const string NONE = 'none';

    /** The site's default: one setting row in the group the storefront already reads in one go. */
    public const string SETTING_GROUP = 'storefront';

    public const string SETTING_KEY = 'layout_blocks';

    /** Where a category keeps its own choice per slot, inside `meta`. */
    public const string META_KEY = 'layout_blocks';

    public static function has(string $slot): bool
    {
        return in_array($slot, self::SLOTS, true);
    }

    public static function maxBlocks(string $slot): ?int
    {
        return self::MAX_BLOCKS[$slot] ?? null;
    }

    /**
     * Every block list a stored slot map holds (slot → NONE or locale → {"blocks": […]}), all
     * languages, for the usage index. Anything that is not a block list is skipped.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function blockLists(mixed $slots): array
    {
        $lists = [];
        if (! is_array($slots)) {
            return $lists;
        }

        foreach ($slots as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $text) {
                if (is_array($text) && is_array($text['blocks'] ?? null)) {
                    $lists[] = $text['blocks'];
                }
            }
        }

        return $lists;
    }
}
