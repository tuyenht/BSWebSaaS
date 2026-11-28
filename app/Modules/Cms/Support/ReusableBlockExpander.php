<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\BlockReference;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Log;

/**
 * Puts the website's shared blocks in the places a page holds for them (BlockReference): the
 * storefront hands it a page's body and its slots' blocks before PageRenderer draws them, and each
 * reference becomes the shared block's own blocks. So everything the renderer works out from the
 * blocks (the block that leads the page, the scripts, the FAQ data, the one lookup per kind of
 * picture, page and file) sees the shared block's blocks as if the page held them. Lives in Cms
 * because Theme may not read Cms (module boundaries); the theme draws an unexpanded reference as
 * nothing.
 *
 * One query for all the references of a page (body and slots together): the published blocks among
 * them, in the page's language, else the website's default language. A draft block, one that is
 * gone, one of another website (BelongsToSite + RLS find nothing) or one with neither language: its
 * place stays empty, never an error. The answers are kept for the request (registered scoped, keyed
 * by site), so a block read once is not read again.
 *
 * The limits: one level (a reference inside a shared block is left out, as is a block only a slot
 * may hold when the place is in a body, and in a slot a block the slot does not take); no reference
 * in a slot that takes none (LayoutSlots::NO_REFERENCES); at most BlockReference::PAGE_MAX blocks on
 * the page once expanded (the page's own blocks always stay; the shared blocks' extra ones are left
 * out, with a warning in the log); and a shared block's link anchor that the page already uses (its
 * own blocks', or the same block placed earlier) is left off (an id is never printed twice).
 */
final class ReusableBlockExpander
{
    /** @var array<int, array<string, array<string, list<array<array-key, mixed>>|null>>> site → locale → ulid → blocks (null: shows nothing) */
    private array $loaded = [];

    public function __construct(
        private readonly CurrentSite $site,
        private readonly SiteLocale $locales,
        private readonly ComponentRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $body  a Page-JSON body ({"blocks": […]}), or [] for a page without one
     * @param  array<string, list<array<array-key, mixed>>>  $slots  slot → its blocks
     * @return array{body: array<string, mixed>, slots: array<string, list<array<array-key, mixed>>>}
     */
    public function expand(array $body, array $slots, string $locale): array
    {
        $own = is_array($body['blocks'] ?? null) ? array_values($body['blocks']) : [];
        $ulids = BlockReference::ulids($own);
        foreach ($slots as $slot => $list) {
            if (LayoutSlots::accepts($slot, BlockReference::TYPE)) {
                $ulids = [...$ulids, ...BlockReference::ulids($list)];
            }
        }
        if ($ulids === [] && ! $this->holdsReference($own, $slots)) {
            return ['body' => $body, 'slots' => $slots];
        }
        $found = $this->load(array_values(array_unique($ulids)), $locale);

        // The page's own blocks count first, so a shared block's blocks take only what room is left.
        $ownCount = self::ownBlocks($own);
        foreach ($slots as $list) {
            $ownCount += self::ownBlocks($list);
        }
        $state = ['count' => $ownCount, 'anchors' => self::anchors([$own, ...array_values($slots)]), 'dropped' => 0];
        if (array_key_exists('blocks', $body)) {
            $body['blocks'] = $this->replace($own, $found, null, $state);
        }
        foreach ($slots as $slot => $list) {
            $slots[$slot] = $this->replace($list, $found, $slot, $state);
        }
        if ($state['dropped'] > 0) {
            Log::warning('A page holds more blocks than it may draw once its shared blocks are in: the rest are left out.', [
                'site_id' => $this->site->id(), 'max' => BlockReference::PAGE_MAX, 'left_out' => $state['dropped'],
            ]);
        }

        return ['body' => $body, 'slots' => array_filter($slots, static fn (array $list): bool => $list !== [])];
    }

    /**
     * The blocks the large panels of a menu show, for each of these shared blocks that has something
     * to show (a draft, one gone, one of another website or one with neither language is left out:
     * its item stays a plain link). Read with the page's references (the same request-wide answers,
     * so a block a panel and the body both show is read once). As in a body: no reference inside, no
     * block only a slot may hold. A panel is no place for a link anchor (the page's own may use the
     * same one) nor for the scroll-in motion (it opens on demand), so both are left off. At most
     * BlockReference::PAGE_MAX blocks for all the panels together.
     *
     * @param  list<string>  $ulids  upper-cased (BlockReference::ulid)
     * @return array<string, list<array<array-key, mixed>>> ulid → its blocks
     */
    public function panels(array $ulids, string $locale): array
    {
        $ulids = array_values(array_unique($ulids));
        if ($ulids === []) {
            return [];
        }
        $panels = [];
        $count = 0;
        foreach ($this->load($ulids, $locale) as $ulid => $blocks) {
            $out = [];
            foreach ($blocks ?? [] as $one) {
                $type = $one['type'] ?? null;
                if (! is_string($type) || $type === BlockReference::TYPE || ($this->registry->has($type) && $this->registry->slotOnly($type))) {
                    continue;
                }
                if ($count >= BlockReference::PAGE_MAX) {
                    break 2;
                }
                $props = is_array($one['props'] ?? null) ? $one['props'] : [];
                unset($props['anchor_id']);
                $props['motion'] = 'none';
                $one['props'] = $props;
                $out[] = $one;
                $count++;
            }
            if ($out !== []) {
                $panels[(string) $ulid] = $out;
            }
        }

        return $panels;
    }

    /**
     * The list with each reference replaced by the shared block's blocks: in a body ($slot null) none
     * that only a slot may hold, in a slot only those the slot accepts (LayoutSlots::accepts), and
     * nothing in a slot that takes no reference. An anchor the page already uses (its own blocks', or
     * one an earlier shared block brought) is left off.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<string, list<array<array-key, mixed>>|null>  $found
     * @param  array{count: int, anchors: array<string, true>, dropped: int}  $state
     * @return list<array<array-key, mixed>>
     */
    private function replace(array $blocks, array $found, ?string $slot, array &$state): array
    {
        $takesReferences = $slot === null || LayoutSlots::accepts($slot, BlockReference::TYPE);
        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (! BlockReference::is($block)) {
                $out[] = $block;

                continue;
            }
            $ulid = BlockReference::ulid($block);
            $inner = $takesReferences && $ulid !== null ? ($found[$ulid] ?? null) : null;
            if ($inner === null) {
                continue;
            }
            foreach ($inner as $one) {
                $type = $one['type'] ?? null;
                if (! is_string($type) || $type === BlockReference::TYPE) {
                    continue;
                }
                $refused = $slot === null
                    ? $this->registry->has($type) && $this->registry->slotOnly($type)
                    : ! LayoutSlots::accepts($slot, $type);
                if ($refused) {
                    continue;
                }
                if ($state['count'] >= BlockReference::PAGE_MAX) {
                    $state['dropped']++;

                    continue;
                }
                $anchor = is_array($one['props'] ?? null) ? ($one['props']['anchor_id'] ?? null) : null;
                if (is_string($anchor) && $anchor !== '') {
                    if (isset($state['anchors'][$anchor])) {
                        unset($one['props']['anchor_id']);
                    } else {
                        $state['anchors'][$anchor] = true;
                    }
                }
                $out[] = $one;
                $state['count']++;
            }
        }

        return $out;
    }

    /**
     * The anchors the page's own blocks use (references left out).
     *
     * @param  list<array<array-key, mixed>>  $lists
     * @return array<string, true>
     */
    private static function anchors(array $lists): array
    {
        $anchors = [];
        foreach ($lists as $list) {
            foreach ($list as $block) {
                $anchor = is_array($block) && ! BlockReference::is($block) && is_array($block['props'] ?? null) ? ($block['props']['anchor_id'] ?? null) : null;
                if (is_string($anchor) && $anchor !== '') {
                    $anchors[$anchor] = true;
                }
            }
        }

        return $anchors;
    }

    /**
     * The blocks of each of these shared blocks for $locale (null: nothing to show), read once per
     * request for whatever was not read yet.
     *
     * @param  list<string>  $ulids
     * @return array<string, list<array<array-key, mixed>>|null>
     */
    private function load(array $ulids, string $locale): array
    {
        $siteId = $this->site->id();
        $known = $this->loaded[$siteId][$locale] ?? [];
        $missing = array_values(array_filter($ulids, static fn (string $ulid): bool => ! array_key_exists($ulid, $known)));

        if ($missing !== []) {
            $default = $this->locales->default();
            $rows = ContentBlock::query()
                ->join('content_block_translations', function (JoinClause $join): void {
                    $join->on('content_block_translations.site_id', '=', 'content_blocks.site_id')
                        ->on('content_block_translations.content_block_id', '=', 'content_blocks.id');
                })
                ->whereIn('content_blocks.ulid', $missing)
                ->where('content_blocks.status', ContentBlock::PUBLISHED)
                ->whereIn('content_block_translations.locale', array_values(array_unique([$locale, $default])))
                ->toBase()
                ->get(['content_blocks.ulid', 'content_block_translations.locale', 'content_block_translations.body']);

            $bodies = [];
            foreach ($rows as $row) {
                $decoded = is_string($row->body ?? null) ? json_decode($row->body, true) : null;
                $blocks = is_array($decoded) && is_array($decoded['blocks'] ?? null) ? array_values(array_filter($decoded['blocks'], 'is_array')) : [];
                $bodies[Cast::string($row->ulid)][Cast::string($row->locale)] = $blocks;
            }
            foreach ($missing as $ulid) {
                $known[$ulid] = $bodies[$ulid][$locale] ?? $bodies[$ulid][$default] ?? null;
            }
            $this->loaded[$siteId][$locale] = $known;
        }

        return array_intersect_key($known, array_flip($ulids));
    }

    /**
     * How many of a list's blocks are the page's own (not references).
     *
     * @param  array<array-key, mixed>  $blocks
     */
    private static function ownBlocks(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            if (is_array($block) && ! BlockReference::is($block)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether a list holds a reference that names no well-formed ulid (it is dropped too).
     *
     * @param  list<mixed>  $own
     * @param  array<string, list<array<array-key, mixed>>>  $slots
     */
    private function holdsReference(array $own, array $slots): bool
    {
        foreach ([$own, ...array_values($slots)] as $list) {
            foreach ($list as $block) {
                if (BlockReference::is($block)) {
                    return true;
                }
            }
        }

        return false;
    }
}
