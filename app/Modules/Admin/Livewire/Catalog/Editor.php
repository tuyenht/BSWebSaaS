<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The catalog product editor — a STRUCTURED form (name/slug/description/category/price/sku/
 * status), not the entries block-builder: a product is commerce-typed fields, not Page-JSON.
 * Price and sku are shown plainly here; hiding them from visitors is the storefront's brochure
 * concern (catalog_mode, a later slice). Every mutation authorizes through CatalogPolicy
 * (coarse catalog.manage) and the product is addressed by ulid, never its raw id. The site's
 * default locale drives which translation row is edited (other locales arrive with full i18n).
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?string $ulid = null;

    /** Resolved product id, for the unique-rule self-ignores; server-set, never client-set. */
    #[Locked]
    public ?int $productId = null;

    /** Server-resolved from the site's default locale; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    public string $name = '';

    public string $slug = '';

    /** Rich HTML specs/prose → product_translations.description. */
    public string $description = '';

    /** Primary category id (product_cat vocabulary); optional until the P3 reconcile seeds them. */
    public ?int $categoryId = null;

    /** VND integer (D5); kept as a string for input round-tripping, cast on persist. */
    public ?string $price = null;

    public ?string $sku = null;

    public string $status = 'draft';

    public function mount(?string $ulid = null): void
    {
        $this->locale = app(SiteLocale::class)->default();

        if ($ulid === null) {
            $this->authorize('create', Product::class);

            return;
        }

        $product = $this->resolveOrFail($ulid);
        $this->authorize('update', $product);

        $this->ulid = $product->ulid;
        $this->productId = $product->id;
        $this->sku = $product->sku;
        $this->price = $product->price !== null ? (string) $product->price : null;
        $this->status = $product->status;

        $translation = $product->translationFor($this->locale);
        if ($translation instanceof ProductTranslation) {
            $this->name = $translation->name;
            $this->slug = $translation->slug;
            $this->description = $translation->description ?? '';
        }

        $this->categoryId = $product->taxonomies()->first()?->id;
    }

    public function save(): void
    {
        $this->persist();
        session()->flash('saved', true);
    }

    public function render(): View
    {
        return view('admin::livewire.catalog.editor', [
            'categories' => $this->availableCategories(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // Uniqueness is validated app-side within the current site (validator queries run under
        // RLS): the slug keys the P3 ETL and the future product route; the sku has a partial
        // UNIQUE(site_id, sku) index that would otherwise surface as a raw 500.
        $uniqueSlug = Rule::unique('product_translations', 'slug')->where('locale', $this->locale);
        $uniqueSku = Rule::unique('products', 'sku');
        if ($this->productId !== null) {
            $uniqueSlug->ignore($this->productId, 'product_id');
            $uniqueSku->ignore($this->productId);
        }

        return [
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $uniqueSlug],
            'description' => ['nullable', 'string'],
            'categoryId' => ['nullable', 'integer', Rule::exists('taxonomies', 'id')->where('vocabulary', 'product_cat')],
            'price' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:255', $uniqueSku],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => __('catalog.slug_format'),
            'slug.unique' => __('catalog.slug_taken'),
            'sku.unique' => __('catalog.sku_taken'),
            'categoryId.exists' => __('catalog.category_invalid'),
        ];
    }

    /** Validate + persist the product, its default-locale translation and category; returns it. */
    private function persist(): Product
    {
        // A cleared input arrives as '' — store NULL: an '' sku would enter the partial
        // UNIQUE(site_id, sku) index and collide with the next skuless product.
        $this->sku = $this->sku === '' ? null : $this->sku;
        $this->price = $this->price === '' ? null : $this->price;

        $this->validate();

        $attributes = [
            'sku' => $this->sku,
            'price' => $this->price !== null ? (int) $this->price : null,
            'status' => $this->status,
        ];

        if ($this->ulid === null) {
            $this->authorize('create', Product::class);
            $product = Product::query()->create($attributes);
            $this->ulid = $product->ulid;
            $this->productId = $product->id;
        } else {
            $product = $this->resolveOrFail($this->ulid);
            $this->authorize('update', $product);
            $product->update($attributes);
        }

        $product->translations()->updateOrCreate(
            ['locale' => $this->locale],
            [
                'name' => $this->name,
                'slug' => $this->slug,
                'description' => $this->description !== '' ? $this->description : null,
            ],
        );

        $product->assignCategories($this->categoryId !== null ? [$this->categoryId] : []);

        return $product;
    }

    /**
     * Product categories (product_cat) for the picker, mapped to id + localized name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(): array
    {
        $categories = Taxonomy::query()
            ->where('vocabulary', 'product_cat')
            ->with('translations:id,taxonomy_id,locale,name,slug')
            ->get()
            ->map(function (Taxonomy $taxonomy): array {
                $translation = $taxonomy->translationFor($this->locale);

                return [
                    'id' => $taxonomy->id,
                    'name' => $translation instanceof TaxonomyTranslation ? $translation->name : __('catalog.unnamed'),
                ];
            })
            ->all();

        return array_values($categories);
    }

    private function resolveOrFail(string $ulid): Product
    {
        return Product::query()->where('ulid', $ulid)->firstOrFail();
    }
}
