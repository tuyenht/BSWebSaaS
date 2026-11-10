<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Support\LayoutSlotBlocks;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Components\ButtonLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the block slots around a page need from the page: the blocks each slot holds for this page's
 * main category and language (LayoutSlotBlocks), and the data the slot-only blocks draw — the
 * newest posts of the post's root branch (read with the related posts, never separately), the
 * categories of that branch (two reads, only when a block asks for them) and the site's hotline and
 * email from its identity. Holds no state; every method reads only what it is handed or the
 * current site's rows.
 */
final readonly class SlotContext
{
    /** The slots of a post page: the side column and the band below the post. */
    public const array POST_SLOTS = ['post_aside', 'after_post'];

    /** The slot of a category list page (posts or products). */
    public const array LIST_SLOTS = ['after_list'];

    /** The slots of a product page: before and after its order buttons, its shared parts, the band below it. */
    public const array PRODUCT_SLOTS = ['product_before_action', 'product_after_action', 'product_tabs', 'after_product'];

    /** The most categories the category list shows (a branch is a handful; the cap bounds a bad tree). */
    public const int CATEGORY_CAP = 50;

    public function __construct(
        private LayoutSlotBlocks $slots,
        private PathBuilder $paths,
    ) {}

    /**
     * The blocks of each named slot that holds any (a slot with none is left out).
     *
     * @param  list<string>  $slots
     * @return array<string, list<array<array-key, mixed>>>
     */
    public function blocks(array $slots, string $locale, ?Taxonomy $category): array
    {
        $found = [];
        foreach ($slots as $slot) {
            $blocks = array_values(array_filter($this->slots->blocks($slot, $locale, $category), 'is_array'));
            if ($blocks !== []) {
                /** @var list<array<array-key, mixed>> $blocks */
                $found[$slot] = $blocks;
            }
        }

        return $found;
    }

    /**
     * Whether any slot holds a block of $type.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     */
    public static function has(array $blocks, string $type): bool
    {
        foreach ($blocks as $list) {
            foreach ($list as $block) {
                if (($block['type'] ?? null) === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The data slot blocks draw. `recent` is handed in (PostDetail read it with the related posts);
     * the categories are read here only when a block asks for them.
     *
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @param  array<array-key, mixed>  $recent
     * @param  array<array-key, mixed>|null  $brand  the site identity (hotline, phone, email)
     * @return array{recent: array<array-key, mixed>, categories: list<array{name: string, url: string, current: bool}>, contact: array{phone: string, call: string|null, email: string, mail: string|null}}
     */
    public function context(array $blocks, ?Taxonomy $category, string $locale, ?array $brand, array $recent = []): array
    {
        return [
            'recent' => $recent,
            'categories' => $category !== null && self::has($blocks, 'category_list') ? $this->categories($category, $locale) : [],
            'contact' => self::contact($brand),
        ];
    }

    /**
     * The hotline (else the main phone) and the email of the site, each with its safe link; a line the
     * settings leave empty (or that is no valid number or address) is empty here, and the block hides it.
     *
     * @param  array<array-key, mixed>|null  $brand
     * @return array{phone: string, call: string|null, email: string, mail: string|null}
     */
    public static function contact(?array $brand): array
    {
        $phone = '';
        $call = null;
        foreach (['hotline', 'phone'] as $field) {
            $value = Cast::string($brand[$field] ?? null);
            if (trim($value) !== '' && ($href = ButtonLink::tel($value)) !== null) {
                [$phone, $call] = [trim($value), $href];
                break;
            }
        }
        $email = trim(Cast::string($brand['email'] ?? null));
        $mail = SafeLink::mailto($email);

        return ['phone' => $phone, 'call' => $call, 'email' => $mail !== null ? $email : '', 'mail' => $mail];
    }

    /**
     * The categories below the root of $category's branch (the root itself when it has none), the
     * shallowest first, each with its address in the page's language, built from its slug the way
     * the breadcrumb builds it. Two reads (the branch, then the names and slugs of the language),
     * at most CATEGORY_CAP rows; a category without a name in the language is left out.
     *
     * @return list<array{name: string, url: string, current: bool}>
     */
    private function categories(Taxonomy $category, string $locale): array
    {
        $first = explode('.', (string) $category->path)[0];
        $rootId = ctype_digit($first) ? (int) $first : $category->id;

        $rows = Taxonomy::query()
            ->select(['taxonomies.id', 'taxonomies.path', 'taxonomies.position'])
            ->where('vocabulary', $category->vocabulary)
            ->where(fn (Builder $branch) => $branch
                ->whereKey($rootId)
                ->orWhereRaw('taxonomies.path <@ (select root.path from taxonomies root where root.id = ?)', [$rootId]))
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)->select(['taxonomy_id', 'locale', 'name', 'slug'])])
            ->orderByRaw('nlevel(taxonomies.path), taxonomies.position, taxonomies.id')
            ->limit(self::CATEGORY_CAP + 1)
            ->get();
        if ($rows->count() > 1) {
            $rows = $rows->reject(fn (Taxonomy $row): bool => $row->id === $rootId);
        }

        $items = [];
        foreach ($rows->take(self::CATEGORY_CAP) as $row) {
            $translation = $row->translationFor($locale);
            if ($translation !== null && $translation->name !== '' && $translation->slug !== '') {
                $items[] = ['name' => $translation->name, 'url' => $this->paths->publicUrl($this->paths->forTaxonomy($translation->slug), $locale), 'current' => $row->id === $category->id];
            }
        }

        return $items;
    }
}
