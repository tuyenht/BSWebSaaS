<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Query\JoinClause;

/**
 * Which categories the Chuyên mục screen shows for its `?section=` key — the same keys the admin
 * menu already links with (AdminNav), so no new data is needed:
 *
 *  - a content section built on a category (`content_sections.taxonomy_id`: Tin tức, Dịch vụ, Dự
 *    án…) shows that root category and the post categories under it (vocabulary `post_cat`), and
 *    beside it every other category the section covers (content_section_categories: «Tin tức»
 *    with «Tuyển dụng»), each with its branch;
 *  - `catalog` (Sản phẩm, when the site may use the catalog) shows every product category
 *    (`product_cat`), which has no root of its own.
 *
 * The legacy `products` content section is left out, as in the admin menu: products live in the
 * catalog now. Read-only; each call is at most one query (scoped to the current site).
 */
final class CategorySections
{
    public const string CATALOG = 'catalog';

    public const string POST_VOCABULARY = 'post_cat';

    public const string PRODUCT_VOCABULARY = 'product_cat';

    /** Filtered out of the admin menu too (AdminNav), until the data that still names it is gone. */
    private const string LEGACY_PRODUCTS = 'products';

    /** @var list<array{key: string, label: string, vocabulary: string, root: int, roots: non-empty-list<int>, prefix: string}>|null */
    private ?array $posts = null;

    public function __construct(private readonly Entitlement $entitlement) {}

    /**
     * The section with this key, or null when there is none (or the site may not use it).
     *
     * @return array{key: string, label: string, vocabulary: string, root: int|null, roots: list<int>, prefix: string}|null
     */
    public function find(string $key): ?array
    {
        if ($key === self::CATALOG) {
            return in_array('catalog', $this->entitlement->entitledModuleKeys(), true)
                ? ['key' => self::CATALOG, 'label' => __('admin.catalog'), 'vocabulary' => self::PRODUCT_VOCABULARY, 'root' => null, 'roots' => [], 'prefix' => 'catalog']
                : null;
        }

        foreach ($this->postSections() as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Every section built on a post category, in display order, with the permission prefix its
     * content is gated by (`entries` unless the section names its own). `root` is its first
     * category (content_sections.taxonomy_id); `roots` every category it covers, the first one
     * first, the others by id. A section whose first category another section covers (two named
     * the same one before a category could belong to one section only) is left out, as the
     * resolver leaves it out.
     *
     * @return list<array{key: string, label: string, vocabulary: string, root: int, roots: non-empty-list<int>, prefix: string}>
     */
    public function postSections(): array
    {
        if ($this->posts !== null) {
            return $this->posts;
        }

        $rows = ContentSection::query()
            ->join('content_section_categories as csc', fn (JoinClause $join) => $join->on('csc.content_section_id', '=', 'content_sections.id')
                ->on('csc.site_id', '=', 'content_sections.site_id'))
            ->whereNotNull('content_sections.taxonomy_id')
            ->where('content_sections.key', '!=', self::LEGACY_PRODUCTS)
            ->where(fn ($query) => $query->whereNull('content_sections.entry_type')->orWhere('content_sections.entry_type', '!=', 'page'))
            ->orderBy('content_sections.sort_order')->orderBy('content_sections.id')->orderBy('csc.taxonomy_id')
            ->get(['content_sections.id', 'content_sections.key', 'content_sections.label', 'content_sections.taxonomy_id', 'content_sections.permission_prefix', 'csc.taxonomy_id as covered']);

        /** @var array<int, array{section: ContentSection, roots: list<int>}> $bySection */
        $bySection = [];
        foreach ($rows as $row) {
            $bySection[$row->id] ??= ['section' => $row, 'roots' => []];
            $bySection[$row->id]['roots'][] = Cast::int($row->getAttribute('covered'));
        }

        $found = [];
        foreach ($bySection as ['section' => $section, 'roots' => $covered]) {
            $root = Cast::int($section->taxonomy_id);
            if (! in_array($root, $covered, true)) {
                continue;
            }
            $found[] = [
                'key' => $section->key,
                'label' => $section->localizedLabel(),
                'vocabulary' => self::POST_VOCABULARY,
                'root' => $root,
                'roots' => [$root, ...array_values(array_filter($covered, static fn (int $id): bool => $id !== $root))],
                'prefix' => $section->permission_prefix ?: 'entries',
            ];
        }

        return $this->posts = $found;
    }
}
