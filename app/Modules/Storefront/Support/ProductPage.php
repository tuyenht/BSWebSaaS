<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\ContactPageSetting;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A catalog product's page: the one place that loads a product with what its page shows and draws it
 * through the theme's product layout. The public product page and the admin's preview of a product
 * both come through here, so the preview is the real page (price, badges, photos, description, the
 * contact box, the related products and the slots around it), not a lookalike.
 *
 * The public page loads a published product by the id its route points at; the preview loads any
 * product of the site by its ulid, whatever its status (one in the Trash is never found). Both run
 * in the site's own context: a product of another site is never found (RLS).
 */
final readonly class ProductPage
{
    public function __construct(
        private PageRenderer $renderer,
        private PathBuilder $paths,
        private Breadcrumbs $crumbs,
        private ProductDetail $products,
        private SlotContext $slots,
        private StorefrontSettings $settings,
    ) {}

    /** The published product a public route points at, with what its page shows; null when there is none. */
    public function published(int $id, string $locale): ?Product
    {
        return $this->query($locale)->whereKey($id)->where('status', 'published')->first();
    }

    /** Any product of the site by its ulid (draft, published or taken down), for the admin's preview; null when there is none. */
    public function forPreview(string $ulid, string $locale): ?Product
    {
        return $this->query($locale)->where('ulid', $ulid)->first();
    }

    /**
     * The unprefixed address the product has (or will have once published) in $locale, built by
     * PathBuilder from the site's permalink settings and its main category — the address its
     * breadcrumb names, for a product that has no route yet.
     */
    public function path(Product $product, ProductTranslation $translation, string $locale): string
    {
        return $this->paths->forProduct($product->primaryCategory(), $translation->slug, $locale);
    }

    /**
     * Draw the product's page. Brochure mode: the page tells search engines what the product is
     * (canonical, share tags, Product JSON-LD with no offers: this catalog sells nothing online),
     * handed to the Theme as primitives, with the main category's name, the summary, the contact
     * box's links and the other products of the same category.
     *
     * A preview prints none of what tells search engines or link previews about a page (no
     * canonical, no JSON-LD, no share tags), asks not to be indexed, and says on the page that it is
     * a preview.
     *
     * @param  string  $path  the product's unprefixed address in $locale
     * @param  array<string, mixed>  $chrome  page chrome (PageChrome)
     */
    public function render(Product $product, ProductTranslation $translation, string $locale, string $path, array $chrome, bool $preview = false): string
    {
        $extra = $chrome;
        $canonical = url($this->paths->publicUrl($path, $locale));
        if ($preview) {
            $extra['preview'] = true;
            $extra['previewLive'] = $product->status === 'published';
            $extra['robots'] = 'noindex, nofollow';
            $extra['hreflang'] = [];
        } else {
            $extra['canonical'] = $canonical;
            $extra['ogType'] = 'product';
        }
        $category = $product->primaryCategory();
        $extra['breadcrumb'] = [
            $this->crumbs->home($locale),
            ...($category !== null ? $this->crumbs->category($category, $locale) : []),
            $this->crumbs->crumb($translation->name, $this->paths->publicUrl($path, $locale)),
        ];
        $extra['crumbsShown'] = $this->crumbs->visible($extra['breadcrumb'], true);

        $images = $this->products->photos($product, $translation->name);
        if ($images !== [] && ! $preview) {
            $extra['ogImage'] = $images[0]['url'];
        }
        // The thumbnails work as plain links; the switcher script only swaps them in place.
        $extra['galleryScript'] = count($images) > 1;
        $extra['product'] = [
            'group' => $category?->translationFor($locale)->name ?? '',
            'summary' => trim((string) $translation->summary),
            'featured' => $product->is_featured,
            'bestseller' => $product->is_bestseller,
            'quote' => $this->products->quoteLink($locale, ContactPageSetting::ulid($this->settings->get(ContactPageSetting::GROUP, ContactPageSetting::KEY))),
            'contact' => ProductDetail::contact(is_array($chrome['brand'] ?? null) ? $chrome['brand'] : null),
        ];
        $extra['related'] = $category !== null ? $this->products->related($product, $category, $locale) : [];
        $extra['slotBlocks'] = $this->slots->blocks(SlotContext::PRODUCT_SLOTS, $locale, $category);
        $extra['slotContext'] = $this->slots->context($extra['slotBlocks'], $category, $locale, is_array($chrome['brand'] ?? null) ? $chrome['brand'] : null);

        if (! $preview) {
            // Product JSON-LD (brochure mode: no offers/price — this catalog sells nothing online).
            // Description must be plain text, so the sanitized HTML body is stripped; the dedicated
            // SEO description wins when the editor wrote one.
            $ldDescription = ! empty($translation->seo_description)
                ? $translation->seo_description
                : str((string) $translation->description)->stripTags()->squish()->limit(300)->toString();
            $extra['productLd'] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $translation->name,
                'description' => $ldDescription !== '' ? $ldDescription : null,
                'image' => array_column($images, 'url') ?: null,
                'url' => $canonical,
                'brand' => ($brandName = data_get($chrome, 'brand.name')) !== null && $brandName !== ''
                    ? ['@type' => 'Brand', 'name' => $brandName]
                    : null,
            ], fn ($value) => $value !== null);
        }

        return $this->renderer->renderProduct(
            $translation->name,
            (string) $translation->description,
            $images,
            ['title' => $translation->seo_title, 'description' => $translation->seo_description],
            $extra,
        );
    }

    /** @return Builder<Product> */
    private function query(string $locale): Builder
    {
        return Product::query()->with([
            'translations',
            'images.media',
            // The main category (and its name) for the breadcrumb.
            'taxonomies' => fn (BelongsToMany $query) => $query->wherePivot('position', 0),
            'taxonomies.translations' => fn (Relation $query) => $query->where('locale', $locale),
        ]);
    }
}
