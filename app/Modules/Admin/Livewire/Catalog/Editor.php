<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\AppRoutePaths;
use App\Modules\Catalog\Support\CatalogMode;
use App\Modules\Catalog\Support\ProductGallery;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Catalog\Support\UniqueViolation;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The catalog product editor — a STRUCTURED form (name/slug/description/summary/category/
 * price/sku/SEO), not the entries block-builder: a product is commerce-typed fields, not
 * Page-JSON. The detail description comes in as Jodit HTML and passes through RichTextSanitizer
 * ON WRITE; the short summary is plain text (the storefront prints it as text). Every action
 * authorizes through CatalogPolicy (coarse catalog.manage) and the product is addressed by ulid,
 * never its raw id. The site's default locale drives which translation row is edited.
 *
 * The status is never taken from the browser: "Lưu nháp" / "Lưu" save keeping it, "Đăng" /
 * "Cập nhật" save it published. A draft (a new product too) also saves itself a few seconds after
 * the last change (autosave, started by the browser), quietly skipped while a field still needs
 * fixing; a published product never does, its changes wait for "Cập nhật". What changed since the
 * last save is counted against the saved values (`saved`), for the save state and the leave dialog.
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

    /** The summary's length for new text; an older, longer one is kept until it is changed. */
    public const int SUMMARY_MAX = 255;

    /** The text fields a draft saving itself leaves as typed (see commit()). */
    private const array AS_TYPED = ['name', 'slug', 'summary', 'price', 'seoTitle', 'seoDescription', 'sku'];

    /** The fields counted as changes, in the order the form shows them. */
    public const array TRACKED = ['name', 'slug', 'summary', 'imageMediaIds', 'price', 'description', 'seoTitle', 'seoDescription', 'categoryId', 'sku'];

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

    /** Plain-text teaser (list cards / og:description) → product_translations.summary. */
    public string $summary = '';

    public string $seoTitle = '';

    public string $seoDescription = '';

    /** Whether the site writes in more than one language (the per-language fields then carry its code). */
    #[Locked]
    public bool $multiLocale = false;

    /** Primary category id (product_cat vocabulary); optional until the P3 reconcile seeds them. */
    public ?int $categoryId = null;

    /**
     * The Google preview's address around the slug (SeoFields::address): built on mount and again
     * when the category changes, finished in the browser as the slug is typed. Server data.
     *
     * @var array{base: string, home: string|null, homeSlug: string}
     */
    #[Locked]
    public array $address = ['base' => '', 'home' => null, 'homeSlug' => PathBuilder::HOME_SLUG];

    /** The site's primary domain, kept from the first request for the address above. */
    #[Locked]
    public string $addressHost = '';

    /** VND integer (D5); kept as a string for input round-tripping, cast on persist. */
    public ?string $price = null;

    public ?string $sku = null;

    /** Changed only by the save actions (publish() makes it published), never by the browser. */
    #[Locked]
    public string $status = 'draft';

    /**
     * Ordered gallery (product_images): index = position, index 0 = the primary image.
     * Held in component state like the entries block list, so create and edit share one
     * atomic save path.
     *
     * @var list<int>
     */
    public array $imageMediaIds = [];

    /**
     * The tracked fields as last saved (or loaded), as text: what the change count compares with.
     * The browser reads it too, to count changes as they are typed.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $saved = [];

    /** How the last save of this visit went: 'saved' (a button) or 'auto' (by itself); null before one. */
    #[Locked]
    public ?string $savedKind = null;

    /** When the product was last saved (UTC, ISO 8601); null for a new product. */
    #[Locked]
    public ?string $savedAt = null;

    /** When the product was first published (UTC, ISO 8601). */
    #[Locked]
    public ?string $publishedAt = null;

    /** The last save asked for by a button found a field to fix. */
    #[Locked]
    public bool $saveFailed = false;

    /** The address visitors open, as saved (host and path), while the product is published. */
    #[Locked]
    public ?string $liveUrl = null;

    /**
     * An older summary the plain field cannot show as stored (formatted, or longer than the limit):
     * kept exactly as stored while the field still holds what it was shown as (`summaryShown`).
     */
    #[Locked]
    public ?string $summaryStored = null;

    #[Locked]
    public string $summaryShown = '';

    /** Only the publish button writes a status; set for the length of that one request. */
    private bool $publishing = false;

    /** The status the page showed when the publish button was pressed; the button only changes a product still in it. */
    private ?string $publishFrom = null;

    /** Set while saving: the stored status differed from the one the page showed, so the button wrote none. */
    private bool $statusChangedElsewhere = false;

    /** Set while saving: a draft saving itself found the product no longer a draft; the stored product. */
    private ?Product $skippedFor = null;

    public function mount(?string $ulid = null): void
    {
        $this->locale = app(SiteLocale::class)->default();
        $this->multiLocale = count(app(SiteLocale::class)->enabled()) > 1;

        if ($ulid === null) {
            $this->authorize('create', Product::class);
            $this->address = $this->previewAddress(null);
            $this->saved = $this->tracked();

            return;
        }

        $product = $this->resolveOrFail($ulid);
        $this->authorize('update', $product);
        $this->load($product);
    }

    /** The catalog's permalink preset may put the category in the address: the preview follows the pick. */
    public function updatedCategoryId(): void
    {
        $this->authorizeEdit();
        $this->address = $this->previewAddress($this->pickedCategory());
    }

    /** "Lưu nháp" / "Lưu": save, keeping the status. */
    public function save(): void
    {
        $this->authorizeEdit();
        $this->commit(quiet: false);
    }

    /** "Đăng" / "Cập nhật": save it published. Nothing saved, the status stays as it was. */
    public function publish(): void
    {
        $this->authorizeEdit();
        $was = $this->status;
        $this->publishing = true;
        $this->publishFrom = $was;

        try {
            $saved = $this->commit(quiet: false);
        } catch (ValidationException $e) {
            $this->status = $was;

            throw $e;
        } finally {
            $this->publishing = false;
            $this->publishFrom = null;
        }

        if (! $saved) {
            $this->status = $was;
        }
    }

    /**
     * A draft saves itself a few seconds after the last change (the browser asks). A published or
     * archived product never does, nor a form with nothing changed; a form with a field still to fix
     * is left as it is, the messages already shown kept.
     */
    public function autosave(): void
    {
        $this->authorizeEdit();
        if ($this->status !== 'draft' || $this->changeCount() === 0) {
            return;
        }

        $bag = $this->getErrorBag();
        $shown = new MessageBag($bag instanceof MessageBag ? $bag->getMessages() : []);
        try {
            $this->commit(quiet: true);
        } catch (ValidationException) {
            $this->setErrorBag($shown);
        }
    }

    /** "Bỏ thay đổi": back to the saved product (a new one back to an empty form). */
    public function resetChanges(): void
    {
        $this->authorizeEdit();
        $this->resetErrorBag();
        $this->saveFailed = false;

        if ($this->ulid === null) {
            $this->reset('name', 'slug', 'description', 'summary', 'seoTitle', 'seoDescription', 'categoryId', 'price', 'sku', 'imageMediaIds', 'summaryStored', 'summaryShown');
            $this->address = $this->previewAddress(null);
            $this->saved = $this->tracked();

            return;
        }

        $this->load($this->resolveOrFail($this->ulid));
    }

    /** How many of the tracked fields differ from the saved product. */
    private function changeCount(): int
    {
        $count = 0;
        foreach ($this->tracked() as $field => $value) {
            if (($this->saved[$field] ?? '') !== $value) {
                $count++;
            }
        }

        return $count;
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
        $this->authorizeEdit();
        if (! array_key_exists($index, $this->imageMediaIds)) {
            return;
        }

        $ids = $this->imageMediaIds;
        array_splice($ids, $index, 1); // splice re-packs the keys — still a list
        $this->imageMediaIds = $ids;
    }

    public function moveImageUp(int $index): void
    {
        $this->authorizeEdit();
        $this->swapImages($index, $index - 1);
    }

    public function moveImageDown(int $index): void
    {
        $this->authorizeEdit();
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
            'actions' => $this->actions(),
            'note' => $this->saveNote(),
            'leave' => $this->leaveWords(),
            'publishCard' => $this->publishCard(),
            'summaryWarning' => $this->summaryWarning(),
            'changes' => $this->changeCount(),
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
            'name' => ['required', 'string', $this->nameIsValid(...)],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slugIsFree(...)],
            'description' => ['nullable', 'string'],
            // the limit is for new text: an older summary left as it was is kept as stored
            'summary' => ['nullable', 'string', ...($this->keepsStoredSummary() ? [] : ['max:'.self::SUMMARY_MAX])],
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
            'seoTitle.max' => __('catalog.seo_title_too_long'),
            'seoDescription.max' => __('catalog.seo_description_too_long'),
            'summary.max' => __('catalog.ed.summary_too_long', ['max' => self::SUMMARY_MAX]),
        ];
    }

    /** Every action re-checks the right to manage products (the update endpoint skips route middleware). */
    private function authorizeEdit(): void
    {
        $this->authorize($this->ulid === null ? 'create' : 'update', Product::class);
    }

    /** Fill the form from a saved product. */
    private function load(Product $product): void
    {
        $this->ulid = $product->ulid;
        $this->productId = $product->id;
        $this->sku = $product->sku;
        $this->price = $product->price !== null ? (string) $product->price : null;
        $this->status = $product->status;
        $this->publishedAt = self::iso($product->published_at);

        $translation = $product->translationFor($this->locale);
        $this->name = $this->slug = $this->description = $this->seoTitle = $this->seoDescription = '';
        $stored = '';
        if ($translation instanceof ProductTranslation) {
            $this->name = $translation->name;
            $this->slug = $translation->slug;
            $this->description = $translation->description ?? '';
            $stored = $translation->summary ?? '';
            $this->seoTitle = $translation->seo_title ?? '';
            $this->seoDescription = $translation->seo_description ?? '';
        }
        $this->showSummary($stored);

        $changed = [$product->updated_at, $translation?->updated_at];
        usort($changed, fn (?DateTimeInterface $a, ?DateTimeInterface $b): int => ($b?->getTimestamp() ?? 0) <=> ($a?->getTimestamp() ?? 0));
        $this->savedAt = self::iso($changed[0]);

        $category = $product->primaryCategory();
        $this->categoryId = $category?->id;
        $this->address = $this->previewAddress($category);
        $this->imageMediaIds = $this->normalizeIds($product->images()->pluck('media_id')->all());
        $this->saved = $this->tracked();
        $this->liveUrl = $this->status === 'published' ? $this->address['base'].$this->slug : null;
    }

    /**
     * Put a stored summary in the plain field. An older one written with the rich editor shows as
     * its text, and one longer than the limit shows whole; both are kept exactly as stored until
     * the field is changed.
     */
    private function showSummary(string $stored): void
    {
        $formatted = preg_match('~</?(?:p|br|div|ul|ol|li|h[1-6]|strong|em|b|i|u|a|span|sup|sub)\b|&(?:[a-z]+|#\d+|#x[0-9a-f]+);~i', $stored) === 1;
        $this->summary = $formatted ? self::textOf($stored) : $stored;
        $legacy = $stored !== '' && ($formatted || mb_strlen($stored) > self::SUMMARY_MAX);
        $this->summaryStored = $legacy ? $stored : null;
        $this->summaryShown = $legacy ? $this->summary : '';
    }

    /** The text of a formatted summary: one line per paragraph or line break, the marks dropped. */
    private static function textOf(string $html): string
    {
        $text = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6])>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(fn (string $line): string => trim(preg_replace('/[\h]+/u', ' ', $line) ?? $line), explode("\n", $text));

        return implode("\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function keepsStoredSummary(): bool
    {
        return $this->summaryStored !== null && $this->summary === $this->summaryShown;
    }

    /**
     * The tracked fields as text, compared the same way in the browser.
     *
     * @return array<string, string>
     */
    private function tracked(): array
    {
        $values = [];
        foreach (self::TRACKED as $field) {
            $value = $this->{$field};
            $values[$field] = match (true) {
                is_array($value) => (string) json_encode($this->normalizeIds($value)),
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return $values;
    }

    /**
     * Save in one transaction. A path or a code another row holds is said on its field and nothing
     * is kept; a field to fix throws (quiet: the caller keeps the messages it had). Returns whether
     * it saved.
     *
     * @throws ValidationException
     */
    private function commit(bool $quiet): bool
    {
        $wasNew = $this->ulid === null;
        $wasLive = $this->liveUrl !== null;
        $slugWasBlank = trim($this->slug) === '';
        // A draft saving itself while the person types stores the tidied text but leaves the fields
        // exactly as typed: a space at the end of a word or a new line must not vanish under the
        // cursor, and a blank address keeps following the name until a button save fixes it.
        $typed = $quiet ? array_intersect_key(get_object_vars($this), array_flip(self::AS_TYPED)) : [];

        try {
            $product = DB::transaction(fn (): ?Product => $this->persist($quiet));
        } catch (RouteConflictException|UniqueConstraintViolationException|ValidationException $e) {
            if ($wasNew) {
                // Rolled back: the form is still a create form.
                $this->ulid = null;
                $this->productId = null;
            }
            // A link made for a save that failed must not stay behind: it would pin the old name.
            if ($slugWasBlank) {
                $this->slug = '';
            }
            if ($quiet) {
                $this->fill($typed);

                throw $e instanceof ValidationException ? $e : ValidationException::withMessages([]);
            }
            $this->saveFailed = true;
            if ($e instanceof ValidationException) {
                throw $e;
            }
            $refusal = $e instanceof UniqueConstraintViolationException
                ? UniqueViolation::refusal($e)
                : new ProductEditRefused(__('catalog.slug_conflict'), 'slug');
            $this->addError($refusal->field ?? 'slug', $refusal->getMessage());

            return false;
        }

        if (! $product instanceof Product) {
            // The product was published or archived elsewhere while this page was open: a draft
            // saving itself writes nothing; the page learns the stored status, address and date.
            $this->fill($typed);
            if ($this->skippedFor instanceof Product) {
                $this->publishedAt = self::iso($this->skippedFor->published_at);
                $slug = $this->skippedFor->translationFor($this->locale)?->slug;
                $this->liveUrl = $this->status === 'published' && $slug !== null
                    ? $this->previewAddress($this->skippedFor->primaryCategory())['base'].$slug
                    : null;
            }

            return false;
        }

        $now = CarbonImmutable::now();
        $this->saveFailed = false;
        $this->savedKind = $quiet ? 'auto' : 'saved';
        $this->savedAt = self::iso($now);
        $this->publishedAt = self::iso($product->published_at);
        $this->fill($typed);
        $this->liveUrl = $this->status === 'published' ? $this->address['base'].$this->slug : null;
        if (! $this->keepsStoredSummary()) {
            $this->summaryStored = null;
            $this->summaryShown = '';
        }
        $this->saved = $this->tracked();

        if ($wasNew) {
            // The address becomes this product's edit address, so a reload keeps editing it; the
            // form stays as it is (no new page), the focus and the scroll where they were.
            $this->js('history.replaceState(history.state, "", '.json_encode(route('admin.catalog.edit', ['ulid' => $product->ulid])).')');
        }

        if (! $quiet) {
            $time = app(SiteTime::class)->forDisplay($now)?->format('H:i') ?? '';
            $this->dispatch('bs-toast', text: match (true) {
                $this->statusChangedElsewhere => __('catalog.ed.toast_status_elsewhere', ['status' => __('catalog.ed.chip_'.$this->status)]),
                $this->status === 'published' && ! $wasLive => __('catalog.ed.toast_published', ['url' => $this->liveUrl]),
                $this->status === 'published' => __('catalog.ed.toast_updated'),
                $this->status === 'draft' => __('catalog.ed.toast_draft', ['time' => $time]),
                default => __('catalog.ed.toast_saved', ['time' => $time]),
            });
        }

        return true;
    }

    /**
     * The two save buttons: the secondary one saves keeping the status (none on a published
     * product), the main one saves it published.
     *
     * @return array{secondary: string|null, primary: string, primaryIcon: string}
     */
    private function actions(): array
    {
        $live = $this->status === 'published';

        return [
            'secondary' => match ($this->status) {
                'published' => null,
                'archived' => (string) __('catalog.ed.save_plain'),
                default => (string) __('catalog.ed.save_draft'),
            },
            'primary' => (string) ($live ? __('catalog.ed.update') : __('catalog.ed.publish')),
            'primaryIcon' => $live ? 'ri-refresh' : 'ri-global',
        ];
    }

    /**
     * The save state beside the title while nothing is being typed: never saved, saved (by a
     * button or by itself) with its time, or a field to fix. The browser shows the typing states
     * (changes waiting, saving, no connection) over it. `text` is shown, `full` read out and in the
     * tooltip, times in the website's zone.
     *
     * @return array{key: string, icon: string, text: string, full: string, tip: string}
     */
    private function saveNote(): array
    {
        if ($this->saveFailed) {
            $text = __('catalog.ed.sv_error');

            return ['key' => 'error', 'icon' => 'ri-error-warning', 'text' => $text, 'full' => $text, 'tip' => $text];
        }

        $at = $this->savedAt !== null ? app(SiteTime::class)->forDisplay(CarbonImmutable::parse($this->savedAt)) : null;
        if (! $at instanceof CarbonImmutable) {
            $text = __('catalog.ed.sv_never');

            return ['key' => 'auto', 'icon' => 'ri-checkbox-circle', 'text' => $text, 'full' => $text, 'tip' => $text];
        }

        $today = $at->isSameDay(app(SiteTime::class)->now());
        $time = $at->format('H:i');
        $full = $today ? __('catalog.ed.sv_full_today', ['time' => $time]) : __('catalog.ed.sv_full_day', ['time' => $time, 'day' => $at->format('d/m/Y')]);
        $text = match ($this->savedKind) {
            'auto' => __('catalog.ed.sv_auto_at', ['time' => $time]),
            default => $today ? __('catalog.ed.sv_short_at', ['time' => $time]) : __('catalog.ed.sv_short', ['when' => $at->format('d/m')]),
        };

        return ['key' => $this->savedKind === null ? 'auto' : 'saved', 'icon' => 'ri-checkbox-circle', 'text' => $text, 'full' => $full, 'tip' => __('catalog.ed.sv_tip', ['text' => $full])];
    }

    /**
     * The leave dialog's sentence and its save button (editors do not name what is being edited).
     *
     * @return array{body: string, save: string}
     */
    private function leaveWords(): array
    {
        $body = match (true) {
            $this->ulid === null => __('catalog.ed.leave_new'),
            $this->status === 'draft' => __('catalog.ed.leave_draft'),
            default => __('catalog.ed.leave_other'),
        };
        $action = $this->status === 'published' ? __('catalog.ed.update') : ($this->status === 'archived' ? __('catalog.ed.save_plain') : __('catalog.ed.save_draft'));

        return ['body' => $body, 'save' => __('catalog.ed.leave_save', ['action' => $action])];
    }

    /**
     * The "Đăng" card: the status, the first publication, the last save, what visitors see.
     *
     * @return array{status: string, chip: string, published: string, updated: string, seen: string}
     */
    private function publishCard(): array
    {
        $time = app(SiteTime::class);
        $when = fn (?string $iso): ?string => $iso === null ? null : $time->forDisplay(CarbonImmutable::parse($iso))?->format('d/m/Y H:i');
        $published = $when($this->publishedAt);
        $updated = $when($this->savedAt);

        $seen = __('catalog.ed.seen_no');
        if ($this->status === 'published') {
            $price = $this->saved['price'] ?? '';
            $seen = ! app(CatalogMode::class)->isBrochure() && $price !== ''
                ? __('catalog.ed.seen_price', ['price' => number_format((int) $price, 0, ',', '.')])
                : __('catalog.ed.seen_ask');
        }

        return [
            'status' => $this->status,
            'chip' => __('catalog.ed.chip_'.$this->status),
            'published' => $published !== null ? __('catalog.ed.pub_first', ['date' => $published]) : __('catalog.ed.pub_never'),
            'updated' => $updated ?? __('catalog.ed.sv_never'),
            'seen' => $seen,
        ];
    }

    /** Why an older summary looks different in the plain field, or null. */
    private function summaryWarning(): ?string
    {
        if ($this->summaryStored === null || ! $this->keepsStoredSummary()) {
            return null;
        }

        return mb_strlen($this->summaryStored) > self::SUMMARY_MAX && $this->summaryStored === $this->summaryShown
            ? __('catalog.ed.summary_old_long', ['n' => mb_strlen($this->summaryStored), 'max' => self::SUMMARY_MAX])
            : __('catalog.ed.summary_old_html');
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->toIso8601String();
    }

    /**
     * The name rule of the products list, in one place (RenameProduct): at most its length, one line.
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function nameIsValid(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        try {
            app(RenameProduct::class)->clean($value);
        } catch (ProductEditRefused $refused) {
            $fail($refused->getMessage());
        }
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

        $action = app(ChangeProductSlug::class);

        // Another product keeps its own message; any other holder (category, page, the application's
        // own path) is said in the words every screen uses.
        if ($action->otherProduct($value, $this->locale, $this->productId) !== null) {
            $fail(__('catalog.slug_taken'));

            return;
        }

        $why = $action->refusal($value, $this->locale, $this->productId, $this->pickedCategory());
        if ($why !== null) {
            $fail($why);
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

        $prefix = $this->publicPathPrefix($this->pickedCategory());

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
                // a flat address is one path segment: the application's own paths (login, admin…) answer first
                ...($prefix === '/' ? $this->appOwned($base) : []),
            ],
        );

        return $this->slug !== '';
    }

    /**
     * Of `$base` and its numbered copies, those the application itself answers on.
     *
     * @return list<string>
     */
    private function appOwned(string $base): array
    {
        $paths = app(AppRoutePaths::class);
        $owned = [];
        foreach ([$base, ...array_map(fn (int $n): string => $base.'-'.$n, range(2, Slugger::PROBE))] as $candidate) {
            if ($paths->owns($candidate)) {
                $owned[] = $candidate;
            }
        }

        return $owned;
    }

    /** The picked primary category with its slugs, or null when none is picked. */
    private function pickedCategory(): ?Taxonomy
    {
        return $this->categoryId !== null
            ? Taxonomy::query()->with('translations')->find($this->categoryId)
            : null;
    }

    /** The part of the public path before the slug, composed by PathBuilder as publishing will. */
    private function publicPathPrefix(?Taxonomy $category): string
    {
        $path = app(PathBuilder::class)->forProduct($category, 'x', $this->locale);

        return str_ends_with($path, 'x') ? substr($path, 0, -1) : '/';
    }

    /**
     * The Google preview's address around the slug: the site's primary domain, the language prefix
     * and the path before the slug.
     *
     * @return array{base: string, home: string|null, homeSlug: string}
     */
    private function previewAddress(?Taxonomy $category): array
    {
        if ($this->addressHost === '') {
            $this->addressHost = SeoFields::publicHost();
        }

        return SeoFields::address($this->addressHost, $this->publicPathPrefix($category), $this->locale, false);
    }

    /**
     * Validate + persist the product, its default-locale translation, category, gallery and — when
     * it is live — its public address; returns it. Runs inside commit()'s transaction.
     *
     * The status is the stored one (read under the row lock) unless this is the publish button; a
     * draft saving itself finds a product no longer a draft and writes nothing (null).
     *
     * @throws RouteConflictException the composed path belongs to another page or category
     */
    private function persist(bool $quiet): ?Product
    {
        $this->statusChangedElsewhere = false;
        $this->skippedFor = null;
        $untouched = ['description' => $this->description, 'imageMediaIds' => $this->imageMediaIds];
        if ($this->ulid === null) {
            $this->status = $this->publishing ? 'published' : 'draft';
        }

        // A cleared input arrives as '' — store NULL: an '' sku would enter the partial
        // UNIQUE(site_id, sku) index and collide with the next skuless product.
        $this->sku = $this->sku !== null ? PlainText::line($this->sku) : null;
        $this->sku = $this->sku === '' ? null : $this->sku;
        $this->price = $this->price === '' ? null : $this->price;

        // Editor HTML is sanitized BEFORE validation so length checks and what the admin sees
        // re-rendered are exactly what gets stored (junk-only input collapses to '').
        $this->description = app(RichTextSanitizer::class)->full(PlainText::lines($this->description, trim: false));

        // An older summary left as it was is kept as stored; new text is plain, line breaks kept.
        $keepSummary = $this->keepsStoredSummary();
        if (! $keepSummary) {
            $this->summary = PlainText::lines($this->summary);
        }

        // No control character is stored (PostgreSQL refuses a NUL); Google shows its two fields on
        // one line, so a line break or an invisible direction mark never reaches the head either.
        // Stored exactly as the name rule measured it: the list trims the same spaces.
        $this->name = RenameProduct::tidy($this->name);
        $this->slug = PlainText::line($this->slug);
        $this->seoTitle = SeoFields::singleLine($this->seoTitle);
        $this->seoDescription = SeoFields::singleLine($this->seoDescription);

        // Gallery ids arrive from the wire payload — coerce + dedup before validating each
        // against the site's media (non-numeric junk becomes 0 and fails the exists rule).
        $this->imageMediaIds = $this->normalizeIds($this->imageMediaIds);

        $filled = $this->fillBlankSlug();
        // Always in this order: the address, then the product row (the slug change and the search
        // words take their locks the same way), then the writes.
        app(ChangeProductSlug::class)->lock($this->slug, $this->locale);
        $stored = null;
        if ($this->ulid !== null) {
            // The stored status is read inside this transaction, under the row lock.
            $stored = Product::query()->where('ulid', $this->ulid)->lock('for no key update')->firstOrFail();
            $shown = $this->publishing ? $this->publishFrom : $this->status;
            if (! $quiet && $stored->status !== $shown) {
                // Changed elsewhere since the page loaded: a button saves the content only, and says so.
                $this->publishing = false;
                $this->statusChangedElsewhere = true;
            }
            if (! $this->publishing) {
                $this->status = $stored->status;
                if ($quiet && $stored->status !== 'draft') {
                    // A draft saving itself writes nothing: the page keeps what it had typed.
                    $this->skippedFor = $stored;
                    $this->description = $untouched['description'];
                    $this->imageMediaIds = $untouched['imageMediaIds'];
                    if ($filled) {
                        $this->slug = '';
                    }

                    return null;
                }
            }
        }
        if ($this->publishing) {
            $this->status = 'published';
        }
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
        ];

        if ($this->ulid === null) {
            $this->authorize('create', Product::class);
            $product = Product::query()->create($attributes + ['status' => $this->status]);
            $this->ulid = $product->ulid;
            $this->productId = $product->id;
        } else {
            $product = $stored;
            $this->authorize('update', $product);
            // the product row before its translation, like every other write of a name or a SKU
            ProductSearchWords::lock($product->site_id, $product->id);
            $product->update($this->publishing ? $attributes + ['status' => 'published'] : $attributes);
        }

        $summary = $keepSummary ? $this->summaryStored : $this->summary;
        $product->translations()->updateOrCreate(
            ['locale' => $this->locale],
            [
                'name' => $this->name,
                'slug' => $this->slug,
                'description' => $this->description !== '' ? $this->description : null,
                'summary' => $summary !== '' ? $summary : null,
                'seo_title' => $this->seoTitle !== '' ? $this->seoTitle : null,
                'seo_description' => $this->seoDescription !== '' ? $this->seoDescription : null,
            ],
        );

        $product->assignCategories($this->categoryId !== null ? [$this->categoryId] : []);
        app(ProductGallery::class)->sync($product, $this->imageMediaIds);

        if ($product->status === 'published') {
            // Drafts and archived products keep whatever address they had — the storefront renders
            // only published ones, so their route rows are harmless and keep old links stable.
            app(ChangeProductStatus::class)->publishEffects($product, $this->locale, $this->slug);
        }

        return $product;
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
