<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;

/**
 * Which categories the Chuyên mục screen shows for its `?section=` key — the same keys the admin
 * menu already links with (AdminNav), so no new data is needed:
 *
 *  - a content section built on a category (`content_sections.taxonomy_id`: Tin tức, Dịch vụ, Dự
 *    án…) shows that root category and the post categories under it (vocabulary `post_cat`);
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

    /** @var list<array{key: string, label: string, vocabulary: string, root: int, prefix: string}>|null */
    private ?array $posts = null;

    public function __construct(private readonly Entitlement $entitlement) {}

    /**
     * The section with this key, or null when there is none (or the site may not use it).
     *
     * @return array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}|null
     */
    public function find(string $key): ?array
    {
        if ($key === self::CATALOG) {
            return in_array('catalog', $this->entitlement->entitledModuleKeys(), true)
                ? ['key' => self::CATALOG, 'label' => __('admin.catalog'), 'vocabulary' => self::PRODUCT_VOCABULARY, 'root' => null, 'prefix' => 'catalog']
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
     * content is gated by (`entries` unless the section names its own).
     *
     * @return list<array{key: string, label: string, vocabulary: string, root: int, prefix: string}>
     */
    public function postSections(): array
    {
        if ($this->posts !== null) {
            return $this->posts;
        }

        $sections = ContentSection::query()
            ->whereNotNull('taxonomy_id')
            ->where('key', '!=', self::LEGACY_PRODUCTS)
            ->where(fn ($query) => $query->whereNull('entry_type')->orWhere('entry_type', '!=', 'page'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'key', 'label', 'taxonomy_id', 'permission_prefix']);

        $found = [];
        $roots = [];
        foreach ($sections as $section) {
            $root = Cast::int($section->taxonomy_id);
            if (isset($roots[$root])) {
                continue; // two sections on one root: the first by display order owns it (ContentSections::boundTo)
            }
            $roots[$root] = true;
            $found[] = [
                'key' => $section->key,
                'label' => $section->localizedLabel(),
                'vocabulary' => self::POST_VOCABULARY,
                'root' => $root,
                'prefix' => $section->permission_prefix ?: 'entries',
            ];
        }

        return $this->posts = $found;
    }
}
