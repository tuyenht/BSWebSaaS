<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The catalog product editor — a STRUCTURED form (name/slug/description/summary/category/
 * price/sku/SEO/status), not the entries block-builder: a product is commerce-typed fields,
 * not Page-JSON. The two rich-text fields (detail description + short summary) come in as
 * Jodit HTML and pass through RichTextSanitizer ON WRITE, so the DB only ever stores
 * allowlisted markup. Price and sku are shown plainly here; hiding them from visitors is the
 * storefront's brochure concern (catalog_mode, a later slice). Every mutation authorizes
 * through CatalogPolicy (coarse catalog.manage) and the product is addressed by ulid, never
 * its raw id. The site's default locale drives which translation row is edited.
 *
 * A published product is saved together with its public address (the `routes` row the storefront
 * resolves, composed by the site's permalink preset): created on first publish, moved with a 301
 * from the old path when the slug or primary category changes. A path another page or category
 * already owns undoes the whole save and says so on the slug field.
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use AuthorizesRequests;
    use WithMediaLibraryPicker;

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

    /** Rich HTML detail/specs (full toolbar) → product_translations.description. */
    public string $description = '';

    /** Rich-basic teaser (list cards / og:description) → product_translations.summary. */
    public string $summary = '';

    public string $seoTitle = '';

    public string $seoDescription = '';

    /** Primary category id (product_cat vocabulary); optional until the P3 reconcile seeds them. */
    public ?int $categoryId = null;

    /** VND integer (D5); kept as a string for input round-tripping, cast on persist. */
    public ?string $price = null;

    public ?string $sku = null;

    public string $status = 'draft';

    /**
     * Ordered gallery (product_images): index = position, index 0 = the primary image.
     * Held in component state like the entries block list, so create and edit share one
     * atomic save path.
     *
     * @var list<int>
     */
    public array $imageMediaIds = [];

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
            $this->summary = $translation->summary ?? '';
            $this->seoTitle = $translation->seo_title ?? '';
            $this->seoDescription = $translation->seo_description ?? '';
        }

        $this->categoryId = $product->primaryCategory()?->id;
        $this->imageMediaIds = $this->normalizeIds($product->images()->pluck('media_id')->all());
    }

    public function save(): void
    {
        $wasNew = $this->ulid === null;

        try {
            $product = DB::transaction(fn (): Product => $this->persist());
        } catch (RouteConflictException) {
            if ($wasNew) {
                // Rolled back: the form is still a create form.
                $this->ulid = null;
                $this->productId = null;
            }
            $this->addError('slug', __('catalog.slug_conflict'));

            return;
        }

        session()->flash('saved', true);

        if ($wasNew) {
            // A freshly created product gets its durable edit URL — refreshing the page keeps
            // editing THIS product instead of opening a blank create form again.
            $this->redirectRoute('admin.catalog.edit', ['ulid' => $product->ulid], navigate: true);
        }
    }

    /**
     * Gallery hook for the shared picker (gallery mode): append once — duplicates are the
     * UNIQUE(product_id, media_id) guard, app-side. The picker stays open for multi-pick.
     */
    protected function addGalleryImage(int $mediaId): void
    {
        if (! in_array($mediaId, $this->imageMediaIds, true)) {
            $this->imageMediaIds[] = $mediaId;
        }
    }

    public function removeImage(int $index): void
    {
        if (! array_key_exists($index, $this->imageMediaIds)) {
            return;
        }

        $ids = $this->imageMediaIds;
        array_splice($ids, $index, 1); // splice re-packs the keys — still a list
        $this->imageMediaIds = $ids;
    }

    public function moveImageUp(int $index): void
    {
        $this->swapImages($index, $index - 1);
    }

    public function moveImageDown(int $index): void
    {
        $this->swapImages($index, $index + 1);
    }

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);

        return view('admin::livewire.catalog.editor', [
            'categories' => $this->availableCategories(),
            'galleryImages' => $this->galleryImages($urls),
            'pickerMedia' => $this->pickerMedia(),
            'urls' => $urls,
        ]);
    }

    /**
     * The shared "name" label means a person's name (the contact form); here it is the product's.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['name' => Str::lower(__('catalog.name_label'))];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // Uniqueness is validated app-side within the current site (validator queries run under
        // RLS): the slug keys the P3 ETL and the future product route; the sku has a partial
        // UNIQUE(site_id, sku) index that would otherwise surface as a raw 500.
        $uniqueSku = Rule::unique('products', 'sku');
        if ($this->productId !== null) {
            $uniqueSku->ignore($this->productId);
        }

        return [
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slugIsFree(...)],
            'description' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'seoTitle' => ['nullable', 'string', 'max:255'],
            'seoDescription' => ['nullable', 'string', 'max:255'],
            'categoryId' => ['nullable', 'integer', Rule::exists('taxonomies', 'id')->where('vocabulary', 'product_cat')],
            'price' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:255', $uniqueSku],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'imageMediaIds' => ['array'],
            // RLS scopes the exists to this site. Deliberately NOT whereNull(deleted_at): an
            // already attached image whose media went to Trash must not block saving (it renders
            // as "missing"; restoring the media heals it) — the ADD path (pickImage) already
            // refuses trashed media via the model's global scope.
            'imageMediaIds.*' => ['integer', Rule::exists('media', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'slug.regex' => __('catalog.slug_format'),
            'sku.unique' => __('catalog.sku_taken'),
            'categoryId.exists' => __('catalog.category_invalid'),
            'imageMediaIds.*.exists' => __('catalog.gallery_invalid'),
            'imageMediaIds.*.integer' => __('catalog.gallery_invalid'),
        ];
    }

    /**
     * No other product of this site holds the slug in this language. Rule::unique would send
     * `slug = ?`, a citext comparison RLS keeps out of the index (no citext operator is leakproof),
     * so it read every product link of the language; as text against the lower-cased value it is
     * served by the (site_id, locale, (slug::text)) index. Same refusal and message as before.
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function slugIsFree(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = ProductTranslation::query()
            ->where('locale', $this->locale)
            ->whereRaw('slug::text = ?', [mb_strtolower($value)])
            ->when($this->productId !== null, fn ($q) => $q->where('product_id', '!=', $this->productId))
            ->exists();

        if ($taken) {
            $fail(__('catalog.slug_taken'));
        }
    }

    /**
     * A blank link is made from the name (any writing system) and made free: no other product link
     * in this language and no other route may hold the public path it would get. A typed link is
     * kept. Returns whether it filled one.
     */
    private function fillBlankSlug(): bool
    {
        if (trim($this->slug) !== '') {
            return false;
        }

        $category = $this->categoryId !== null
            ? Taxonomy::query()->with('translations')->find($this->categoryId)
            : null;
        $path = app(PathBuilder::class)->forProduct($category, 'x', $this->locale);
        $prefix = str_ends_with($path, 'x') ? substr($path, 0, -1) : '/';

        $this->slug = Slugger::fromTitle(
            $this->name,
            $this->locale,
            fn (string $base): array => [
                ...Slugger::takenAmong(
                    ProductTranslation::query()
                        ->where('locale', $this->locale)
                        ->when($this->productId !== null, fn ($q) => $q->where('product_id', '!=', $this->productId)),
                    'slug',
                    $base,
                ),
                ...Slugger::takenAmong(
                    SiteRoute::query()
                        ->where('locale', $this->locale)
                        ->when($this->productId !== null, fn ($q) => $q->where(
                            fn ($own) => $own->whereNull('product_id')->orWhere('product_id', '!=', $this->productId),
                        )),
                    'path',
                    $base,
                    $prefix,
                ),
            ],
        );

        return $this->slug !== '';
    }

    /**
     * Validate + persist the product, its default-locale translation, category, gallery and — when
     * it is live — its public address; returns it. Runs inside save()'s transaction.
     *
     * @throws RouteConflictException the composed path belongs to another page or category
     */
    private function persist(): Product
    {
        // A cleared input arrives as '' — store NULL: an '' sku would enter the partial
        // UNIQUE(site_id, sku) index and collide with the next skuless product.
        $this->sku = $this->sku === '' ? null : $this->sku;
        $this->price = $this->price === '' ? null : $this->price;

        // Editor HTML is sanitized BEFORE validation so length checks and what the admin sees
        // re-rendered are exactly what gets stored (junk-only input collapses to '').
        $sanitizer = app(RichTextSanitizer::class);
        $this->description = $sanitizer->full($this->description);
        $this->summary = $sanitizer->basic($this->summary);

        // Gallery ids arrive from the wire payload — coerce + dedup before validating each
        // against the site's media (non-numeric junk becomes 0 and fails the exists rule).
        $this->imageMediaIds = $this->normalizeIds($this->imageMediaIds);

        $filled = $this->fillBlankSlug();
        try {
            $this->validate();
        } catch (ValidationException $e) {
            // A link made for a save that failed must not stay behind: it would pin the old name.
            if ($filled) {
                $this->slug = '';
            }

            throw $e;
        }

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
                'summary' => $this->summary !== '' ? $this->summary : null,
                'seo_title' => $this->seoTitle !== '' ? $this->seoTitle : null,
                'seo_description' => $this->seoDescription !== '' ? $this->seoDescription : null,
            ],
        );

        $product->assignCategories($this->categoryId !== null ? [$this->categoryId] : []);
        $this->syncImages($product);

        if ($product->status === 'published') {
            // First time live: the storefront lists order by this date.
            if ($product->published_at === null) {
                $product->forceFill(['published_at' => now()])->save();
            }

            // Drafts and archived products keep whatever address they had — the storefront renders
            // only published ones, so their route rows are harmless and keep old links stable.
            app(RouteSync::class)->syncProduct($product->id, $product->primaryCategory(), $this->locale, $this->slug);
        }

        return $product;
    }

    /**
     * Sync product_images to the ordered id list (position = index). updateOrCreate rides the
     * UNIQUE(product_id, media_id) index instead of tripping it; BelongsToSite stamps site_id
     * on the created rows.
     */
    private function syncImages(Product $product): void
    {
        if ($this->imageMediaIds === []) {
            $product->images()->delete();

            return;
        }

        $product->images()->whereNotIn('media_id', $this->imageMediaIds)->delete();

        foreach ($this->imageMediaIds as $position => $mediaId) {
            $product->images()->updateOrCreate(
                ['media_id' => $mediaId],
                ['position' => $position],
            );
        }
    }

    /**
     * The gallery for the view, in state order. The Media global scope filters other sites AND
     * trashed rows, so an attached image whose media was trashed renders as `missing` (kept in
     * state — restoring the media heals it; the admin can also remove it explicitly).
     *
     * @return list<array{id: int, url: string|null, name: string|null, missing: bool}>
     */
    private function galleryImages(MediaUrlResolver $urls): array
    {
        if ($this->imageMediaIds === []) {
            return [];
        }

        $media = Media::query()->whereIn('id', $this->imageMediaIds)->get()->keyBy('id');

        $items = [];
        foreach ($this->imageMediaIds as $id) {
            $item = $media->get($id);
            if ($item instanceof Media) {
                $items[] = ['id' => $id, 'url' => $urls->url($item->storage_key, 320), 'name' => $item->original_filename, 'missing' => false];
            } else {
                $items[] = ['id' => $id, 'url' => null, 'name' => null, 'missing' => true];
            }
        }

        return $items;
    }

    private function swapImages(int $a, int $b): void
    {
        if (! array_key_exists($a, $this->imageMediaIds) || ! array_key_exists($b, $this->imageMediaIds)) {
            return;
        }

        $ids = $this->imageMediaIds;
        [$ids[$a], $ids[$b]] = [$ids[$b], $ids[$a]];
        $this->imageMediaIds = array_values($ids); // keys unchanged by the swap — re-proves the list shape
    }

    /**
     * Coerce wire-payload values (a client can send anything into an array property) into a
     * deduped id list; non-scalar junk becomes 0 and fails the exists rule at validation.
     *
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $ints = [];
        foreach ($ids as $id) {
            $ints[] = is_scalar($id) ? (int) $id : 0;
        }

        return array_values(array_unique($ints));
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
