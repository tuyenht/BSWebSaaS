<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Query\JoinClause;

/**
 * The post editor's «Chuyên mục» card (board 07): every post category of the website, read once,
 * grouped by the post section it belongs to — the section the post is in first, then the others in
 * menu order, then categories no section is built on. A section's own category is not a row: a post
 * filed on it reads as «no category ticked» (it shows only on the section's page), and its children
 * are the top of that section's tree.
 *
 * A category belongs to the nearest section built on it or above it (SectionResolver's walk), so
 * every row knows which section — and so which approval workflow — filing a post under it means.
 * Two statements: the sections (CategorySections) and the categories with their names in the
 * editor's language, the website's default as the fallback. Keys only reach the page.
 */
final class PostCategoryChoices
{
    /** A website's categories are few; the read stops here whatever the data. */
    public const int LIMIT = 2000;

    /** @var array<int, array{ulid: string|null, parent: int|null, name: string}> */
    private array $nodes = [];

    /** @var array<int, list<int>> parent id (0: the top) → child ids, in menu order */
    private array $children = [];

    /** @var array<string, int> ulid → id */
    private array $byUlid = [];

    /** @var array<int, string> every category a section covers → that section's key */
    private array $rootKey = [];

    /** @var array<int, string> a section's first category (it is the section itself, not a row) → that section's key */
    private array $homeRoot = [];

    /** @var array<string, array{key: string, label: string, root: int, roots: list<int>}> section key → section, in menu order */
    private array $sections = [];

    /** @var array<int, string|null> memo: category id → its section key */
    private array $sectionMemo = [];

    public static function read(int $siteId, string $locale, string $defaultLocale, CategorySections $sections): self
    {
        $choices = new self;
        foreach ($sections->postSections() as $section) {
            $choices->sections[$section['key']] = ['key' => $section['key'], 'label' => $section['label'], 'root' => $section['root'], 'roots' => $section['roots']];
            foreach ($section['roots'] as $root) {
                $choices->rootKey[$root] ??= $section['key'];
            }
            $choices->homeRoot[$section['root']] ??= $section['key'];
        }

        $rows = Taxonomy::query()
            ->where('taxonomies.vocabulary', 'post_cat')
            ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 'taxonomies.id')
                ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
            ->leftJoin('taxonomy_translations as td', fn (JoinClause $join) => $join->on('td.taxonomy_id', '=', 'taxonomies.id')
                ->where('td.site_id', '=', $siteId)->where('td.locale', '=', $defaultLocale))
            ->orderBy('taxonomies.position')->orderBy('taxonomies.id')
            ->limit(self::LIMIT)
            ->toBase()->get(['taxonomies.id', 'taxonomies.ulid', 'taxonomies.parent_id', 'tt.name as name', 'td.name as fallback']);

        foreach ($rows as $row) {
            $row = (array) $row;
            $id = Cast::int($row['id'] ?? 0);
            $name = Cast::string($row['name'] ?? '') ?: Cast::string($row['fallback'] ?? '') ?: Cast::string(__('entries.unnamed'));
            $parent = $row['parent_id'] ?? null;
            $choices->nodes[$id] = ['ulid' => Cast::stringOrNull($row['ulid'] ?? null), 'parent' => $parent === null ? null : Cast::int($parent), 'name' => $name];
        }
        foreach ($choices->nodes as $id => $node) {
            // a parent outside the read (or itself) leaves the category at the top
            $parent = $node['parent'] !== null && $node['parent'] !== $id && isset($choices->nodes[$node['parent']]) ? $node['parent'] : 0;
            $choices->nodes[$id]['parent'] = $parent === 0 ? null : $parent;
            $choices->children[$parent][] = $id;
            if ($node['ulid'] !== null) {
                $choices->byUlid[$node['ulid']] = $id;
            }
        }

        return $choices;
    }

    /** A post category of this website by its key (null: none — another website's key is never read). */
    public function idOf(string $ulid): ?int
    {
        return $this->byUlid[$ulid] ?? null;
    }

    /** A category's key by its id (null: not in the read, or not given a key yet). */
    public function ulidOf(int $id): ?string
    {
        return $this->nodes[$id]['ulid'] ?? null;
    }

    public function has(int $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** The category's name in the editor's language (the default language's when it has none). */
    public function name(int $id): ?string
    {
        return $this->nodes[$id]['name'] ?? null;
    }

    /**
     * Whether this is a section's first category (it is the section, not a row). Another category a
     * section covers beside it («Tuyển dụng» in «Tin tức») is a row like any other.
     */
    public function isSectionRoot(int $id): bool
    {
        return isset($this->homeRoot[$id]);
    }

    /** A section's first category, or null. */
    public function rootOf(?string $sectionKey): ?int
    {
        return $sectionKey !== null ? ($this->sections[$sectionKey]['root'] ?? null) : null;
    }

    /** The section's name as the menu shows it, or null. */
    public function sectionLabel(?string $sectionKey): ?string
    {
        return $sectionKey !== null ? ($this->sections[$sectionKey]['label'] ?? null) : null;
    }

    /** The section a category files a post under: the nearest one built on it or above it; null when none. */
    public function sectionOf(int $id): ?string
    {
        if (array_key_exists($id, $this->sectionMemo)) {
            return $this->sectionMemo[$id];
        }
        $at = $id;
        $seen = [];
        while ($at !== null && isset($this->nodes[$at]) && ! isset($seen[$at])) {
            if (isset($this->rootKey[$at])) {
                return $this->sectionMemo[$id] = $this->rootKey[$at];
            }
            $seen[$at] = true;
            $at = $this->nodes[$at]['parent'];
        }

        return $this->sectionMemo[$id] = null;
    }

    /** The category's level in the vocabulary, a top-level one being 1 (TaxonomyRules::MAX_DEPTH counts the same way). */
    public function level(int $id): int
    {
        $level = 0;
        $at = $id;
        $seen = [];
        while ($at !== null && isset($this->nodes[$at]) && ! isset($seen[$at])) {
            $seen[$at] = true;
            $level++;
            $at = $this->nodes[$at]['parent'];
        }

        return $level;
    }

    /**
     * The first category of a section, in menu order, that $inside accepts (a limited member's
     * default for a new post when the section's own category lies outside their limits).
     *
     * @param  callable(int): bool  $inside
     */
    public function firstIn(?string $sectionKey, callable $inside): ?int
    {
        foreach ($this->ordered($sectionKey) as [$id]) {
            if ($this->sectionOf($id) === $sectionKey && $inside($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The card's rows: every category that can be chosen (a key, not a section's own category), the
     * home section's first, parents before children (indented two levels at most), with the names
     * above it (for a search result), whether it is ticked or the main one, the section it belongs to
     * when that is not the home one, and whether it is locked — outside the member's limits ($inside:
     * whether they may write in a category, null when not limited; such a row shows only when ticked), or
     * in another section while an approval workflow governs the home one ($governed).
     *
     * @param  list<int>  $categoryIds  the post's categories, the main one first
     * @param  (callable(int): bool)|null  $inside
     * @param  string  $unit  what the post is called in the sentences ("tin tức")
     * @return list<array{ulid: string, label: string, depth: int, path: string, on: bool, main: bool, section: string|null, locked: bool, why: string|null, moves: bool}>
     */
    public function rows(?string $homeKey, array $categoryIds, ?callable $inside, bool $governed, string $unit): array
    {
        $on = array_flip($categoryIds);
        $main = $categoryIds[0] ?? null;
        $rows = [];
        foreach ($this->ordered($homeKey) as [$id, $depth]) {
            $ulid = $this->nodes[$id]['ulid'];
            $ticked = isset($on[$id]);
            $outside = $inside !== null && ! $inside($id);
            if ($ulid === null || ($outside && ! $ticked)) {
                continue;
            }
            $section = $this->sectionOf($id);
            $other = $section !== $homeKey;
            $locked = $outside || ($governed && $other);
            $above = $this->nodes[$id]['parent'];
            $rows[] = [
                'ulid' => $ulid,
                'label' => $this->nodes[$id]['name'],
                'depth' => min(2, $depth),
                'path' => $above === null || isset($this->homeRoot[$above]) ? '' : $this->pathOf($above).' › ',
                'on' => $ticked,
                'main' => $id === $main,
                'section' => $other ? $this->sectionLabel($section) : null,
                'locked' => $locked,
                'why' => match (true) {
                    $outside => Cast::string(__('entries.ed.cat_why_scope')),
                    $locked => Cast::string(__('entries.ed.cat_why_workflow', ['section' => $this->sectionLabel($homeKey) ?? '', 'unit' => $unit])),
                    default => null,
                },
                // the main category in another section: saving moves the post there
                'moves' => $other && ! $locked && $id === $main,
            ];
        }

        return $rows;
    }

    /**
     * Every category in card order with its depth below its section's own category: the home
     * section, the other sections in menu order, then the categories no section is built on.
     *
     * @return list<array{int, int}>
     */
    private function ordered(?string $homeKey): array
    {
        $out = [];
        $seen = [];
        $keys = array_keys($this->sections);
        if ($homeKey !== null && isset($this->sections[$homeKey])) {
            $keys = [$homeKey, ...array_values(array_filter($keys, static fn (string $key): bool => $key !== $homeKey))];
        }
        foreach ($keys as $key) {
            $root = $this->sections[$key]['root'];
            if ($this->rootKey[$root] === $key) {
                $this->walk($root, 0, $out, $seen);
            }
            // the other categories it covers sit beside the first one's children, their own below them
            foreach (array_slice($this->sections[$key]['roots'], 1) as $also) {
                if (isset($this->nodes[$also]) && ! isset($seen[$also]) && $this->rootKey[$also] === $key) {
                    $seen[$also] = true;
                    $out[] = [$also, 0];
                    $this->walk($also, 1, $out, $seen);
                }
            }
        }
        // no section above: from the top of the tree, sections' own branches already listed
        foreach ($this->children[0] ?? [] as $id) {
            if (! isset($seen[$id]) && ! isset($this->rootKey[$id])) {
                $seen[$id] = true;
                $out[] = [$id, 0];
                $this->walk($id, 1, $out, $seen);
            }
        }

        return $out;
    }

    /**
     * @param  list<array{int, int}>  $out
     * @param  array<int, true>  $seen
     */
    private function walk(int $parent, int $depth, array &$out, array &$seen): void
    {
        foreach ($this->children[$parent] ?? [] as $id) {
            // a section built on a category below another section's lists its own branch
            if (isset($seen[$id]) || isset($this->rootKey[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [$id, $depth];
            $this->walk($id, $depth + 1, $out, $seen);
        }
    }

    /** The names from below the section's own category down to $id ("Kiến thức › Gia công CNC"). */
    private function pathOf(int $id): string
    {
        $names = [];
        $at = $id;
        $seen = [];
        while ($at !== null && isset($this->nodes[$at]) && ! isset($seen[$at]) && ! isset($this->homeRoot[$at])) {
            $seen[$at] = true;
            array_unshift($names, $this->nodes[$at]['name']);
            $at = $this->nodes[$at]['parent'];
        }

        return implode(' › ', $names);
    }
}
