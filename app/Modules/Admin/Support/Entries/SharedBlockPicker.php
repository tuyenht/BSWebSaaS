<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Queries\ContentBlockList;
use App\Modules\Cms\Queries\ContentBlockUsages;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\BlockReference;
use Illuminate\Support\Collection;

/**
 * What «Trình dựng» shows of the website's shared blocks (Khối dùng chung, board 27), read within
 * the current site (BelongsToSite + RLS), every block named by its ulid, never its id:
 *  - choices(): the «Chèn khối dùng chung» list, published blocks first, then drafts (a draft may be
 *    placed: it shows on the website once published), by name; at most the blocks a website may
 *    keep (ContentBlockRules::max), so the list is bounded. Three reads whatever the count: the
 *    blocks, their languages, how many places use each.
 *  - placed(): what the blocks a layout already places say on their card (name, code, status,
 *    languages, places), the same three reads, none when the layout places no shared block. A ulid
 *    the site has no block for is absent: the card says the block is gone.
 *
 * The language a visitor sees follows the storefront (ReusableBlockExpander): the page's language,
 * else the website's default, else nothing; fallback() says which, for the line on a row or a card.
 */
final readonly class SharedBlockPicker
{
    public function __construct(
        private ContentBlockList $list,
        private ContentBlockUsages $usages,
        private SiteLocale $locales,
    ) {}

    /**
     * @return list<array{ulid: string, key: string, name: string, region: string, draft: bool, locales: list<string>, places: int}>
     */
    public function choices(): array
    {
        $rows = ContentBlock::query()
            ->orderByRaw('case when status = ? then 0 else 1 end', [ContentBlock::PUBLISHED])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(max(1, ContentBlockRules::max()))
            ->get(['id', 'ulid', 'key', 'name', 'region', 'status']);

        return $this->describe($rows);
    }

    /**
     * @param  list<string>  $ulids  upper-cased (BlockReference::ulids)
     * @return array<string, array{ulid: string, key: string, name: string, region: string, draft: bool, locales: list<string>, places: int}>
     */
    public function placed(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }
        $rows = ContentBlock::query()->whereIn('ulid', $ulids)->get(['id', 'ulid', 'key', 'name', 'region', 'status']);
        $out = [];
        foreach ($this->describe($rows) as $row) {
            $out[$row['ulid']] = $row;
        }

        return $out;
    }

    /**
     * How many shared blocks a layout places, against the most one body may hold.
     *
     * @param  array<array-key, mixed>  $blocks
     */
    public static function referenceCount(array $blocks): int
    {
        return count(array_filter($blocks, BlockReference::is(...)));
    }

    /**
     * What a visitor of the page in $locale sees of a block that has content in $has: null when the
     * block has that language, 'default' when the website's default stands in, 'none' when nothing does.
     *
     * @param  list<string>  $has
     */
    public function fallback(array $has, string $locale): ?string
    {
        if (in_array($locale, $has, true)) {
            return null;
        }

        return $locale !== $this->locales->default() && in_array($this->locales->default(), $has, true) ? 'default' : 'none';
    }

    /** The website's default language, by name ("Tiếng Việt"). */
    public function defaultLabel(): string
    {
        return Locales::label($this->locales->default());
    }

    /**
     * @param  Collection<int, ContentBlock>  $rows
     * @return list<array{ulid: string, key: string, name: string, region: string, draft: bool, locales: list<string>, places: int}>
     */
    private function describe(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = array_values(array_map(Cast::int(...), $rows->pluck('id')->all()));
        $locales = $this->list->locales($ids);
        $places = $this->usages->countsFor($ids);

        return array_values($rows->map(fn (ContentBlock $block): array => [
            'ulid' => strtoupper($block->ulid),
            'key' => $block->key,
            'name' => $block->name,
            'region' => $block->region,
            'draft' => $block->status !== ContentBlock::PUBLISHED,
            'locales' => $locales[$block->id] ?? [],
            'places' => $places[$block->id] ?? 0,
        ])->all());
    }
}
