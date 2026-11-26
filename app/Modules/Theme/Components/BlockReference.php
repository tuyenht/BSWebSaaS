<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

/**
 * A place in a block list that shows one of the website's shared blocks (Khối dùng chung):
 * `{"type": "reusable_ref", "props": {"block": "<ulid>"}}`, the block named by its ulid, never by
 * its id. It carries no look of its own: the shared block's own blocks keep theirs.
 *
 * The storefront replaces each reference with the shared block's blocks before the page is drawn
 * (Cms\Support\ReusableBlockExpander), so the theme registers the type with a view that draws
 * nothing: a road that forgot to expand shows nothing, as for a block that is gone. One level only:
 * a shared block's own body may hold no reference (ValidPageJson::forBlockBody).
 *
 * Pure data, no state.
 */
final class BlockReference
{
    public const string TYPE = 'reusable_ref';

    public const string PROP = 'block';

    /** The most references one body or one slot's list may hold (checked on save). */
    public const int LIST_MAX = 20;

    /** The most blocks one page draws once its references are expanded (the rest is left out). */
    public const int PAGE_MAX = 300;

    /** @var array<string, array<string, mixed>> */
    public const array SCHEMA = [
        self::PROP => ['type' => 'text', 'pattern' => SectionProps::ULID_PATTERN],
    ];

    public static function is(mixed $block): bool
    {
        return is_array($block) && ($block['type'] ?? null) === self::TYPE;
    }

    /** The ulid a reference names, upper-cased, or null (not a reference, or no well-formed ulid). */
    public static function ulid(mixed $block): ?string
    {
        if (! self::is($block)) {
            return null;
        }
        $props = is_array($block) && is_array($block['props'] ?? null) ? $block['props'] : [];
        $ulid = $props[self::PROP] ?? null;

        return is_string($ulid) && preg_match(SectionProps::ULID_PATTERN, $ulid) === 1 && strlen($ulid) === 26 ? strtoupper($ulid) : null;
    }

    /**
     * The ulids the references of one block list name, at its top level (a reference is never
     * nested), each once, in order.
     *
     * @param  iterable<mixed>  $blocks
     * @return list<string>
     */
    public static function ulids(iterable $blocks): array
    {
        $found = [];
        foreach ($blocks as $block) {
            $ulid = self::ulid($block);
            if ($ulid !== null && ! in_array($ulid, $found, true)) {
                $found[] = $ulid;
            }
        }

        return $found;
    }

    /**
     * The ulids the references of several block lists name, each once.
     *
     * @param  iterable<mixed>  $lists
     * @return list<string>
     */
    public static function ulidsIn(iterable $lists): array
    {
        $found = [];
        foreach ($lists as $list) {
            if (is_iterable($list)) {
                array_push($found, ...self::ulids($list));
            }
        }

        return array_values(array_unique($found));
    }
}
