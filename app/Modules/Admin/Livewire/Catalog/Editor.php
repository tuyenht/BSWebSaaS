<?php

namespace App\Modules\Admin\Livewire\Catalog;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\Catalog\ProductLangStates;
use App\Modules\Admin\Support\CategoryTree;
use App\Modules\Admin\Support\Guide\EditorGuide;
use App\Modules\Admin\Support\LangBar;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Catalog\Actions\ChangeProductExpiry;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Actions\FlagProduct;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\CatalogMode;
use App\Modules\Catalog\Support\ProductGallery;
use App\Modules\Catalog\Support\ProductPrice;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Catalog\Support\SkuCode;
use App\Modules\Catalog\Support\UniqueViolation;
use App\Modules\Cms\Exceptions\ExpiredContentException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Validation\ExpiryTime;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PlainText;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
 * authorizes through CatalogPolicy (catalog.manage, asked about the stored product itself) and the
 * product is addressed by ulid, never its raw id. A member limited to some product categories
 * (CategoryScopes) opens and saves only products whose main category is inside the limits, picks
 * only such a category (one is required for them), and never files a product under no category.
 *
 * Each language has its own version of the product's words (product_translations: name, link,
 * short description, description, the Google title and description), opened with `?locale=` when it
 * is one of the website's enabled languages (else the website's default language) and saved in that
 * language only; a version not written yet is created by its first save. Everything else (pictures,
 * price, stock, sale, SKU, labels, categories, status) is the product's, one for every language: it
 * is changed in the default language only. Another language shows it locked («Chung») and a save
 * there writes nothing of it. A new product is always written in the default language first.
 *
 * The status is never taken from the browser: "Lưu nháp" / "Lưu" save keeping it, "Đăng" /
 * "Cập nhật" save it published. A draft (a new product too) also saves itself a few seconds after
 * the last change (autosave, started by the browser), quietly skipped while a field still needs
 * fixing; a published product never does, its changes wait for "Cập nhật". What changed since the
 * last save is counted against the saved values (`saved`), for the save state and the leave dialog.
 *
 * The price card follows the board: the price, then (in the group) the stock and the sale with its
 * start and end, all whole đồng typed the Vietnamese way and read strictly (ProductPrice), checked
 * when a field is left and again on save; a message stays until the field is fixed. A website that
 * only presents its products (CatalogMode brochure) folds the card behind a note: the amounts are kept
 * and shown to visitors once the website sells. The labels «Nổi bật» / «Bán chạy» are form fields
 * saved with the rest, written through the list's own action (FlagProduct). A save a field stops
 * says how many fields need fixing and puts the cursor in the first one.
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
    use WithMediaLibraryPicker {
        insertPicked as private insertLibraryPicked;
        openImagePicker as private openLibraryPicker;
    }

    /** The summary's length for new text; an older, longer one is kept until it is changed. */
    public const int SUMMARY_MAX = 255;

    /** The text fields a draft saving itself leaves as typed (see commit()). */
    private const array AS_TYPED = ['name', 'slug', 'summary', 'price', 'salePrice', 'stock', 'seoTitle', 'seoDescription', 'sku'];

    /** The fields counted as changes, in the order the form shows them. */
    public const array TRACKED = ['name', 'slug', 'summary', 'imageMediaIds', 'price', 'stock', 'salePrice', 'saleStartsAt', 'saleEndsAt', 'description', 'seoTitle', 'seoDescription', 'categoryIds', 'sku', 'featured', 'bestseller', 'expiresAt'];

    /** A sale's start or end as the site's wall clock, the way a datetime-local field gives it. */
    private const string MOMENT = 'Y-m-d\\TH:i';

    #[Locked]
    public ?string $ulid = null;

    /** Resolved product id, for the unique-rule self-ignores; server-set, never client-set. */
    #[Locked]
    public ?int $productId = null;

    /** The ⋮ offers «Chuyển vào thùng rác»: a saved product the member may delete (the policy, with their category limits), as of the last open or save. */
    #[Locked]
    public bool $mayTrash = false;

    /**
     * What the screen shows in place of the form, in the board's order: 'plan' (the website's plan has
     * no catalog), 'noperm' (no right to see products, or to add one), 'error' (the product could not
     * be read: «Thử lại»), 'trash' (the product is in the Trash: «Khôi phục»); 'form' otherwise.
     */
    #[Locked]
    public string $screen = 'form';

    /**
     * The member may change this product (catalog.manage, the product inside their category limits).
     * Without it a member who may see products gets the form to read only («Chỉ được xem»): every field
     * locked, no save, no «Xem trước» (its page is for who may manage products), the ⋮ holding only
     * «Cỡ chữ» — and every write below refuses them on the server as well.
     */
    #[Locked]
    public bool $canEdit = true;

    /** The Trash card offers «Khôi phục» (the policy's restore, asked about the product). */
    #[Locked]
    public bool $mayRestore = false;

    /**
     * The message at the bottom of the screen that carries «Hoàn tác» (components/toast): its words,
     * its counter (a new number for every message) and what «Hoàn tác» puts back — 'trash' (the product
     * moved to the Trash from here) or 'image' (a picture taken off the product, `undoImage`).
     */
    #[Locked]
    public string $toastMsg = '';

    #[Locked]
    public int $toastSeq = 0;

    #[Locked]
    public ?string $undo = null;

    /**
     * The picture «Hoàn tác» puts back, and where it stood.
     *
     * @var array{id: int, at: int}|null
     */
    #[Locked]
    public ?array $undoImage = null;

    /** The guide is printed with the first draw only (the browser keeps it; later round trips skip it). */
    private bool $guideDue = false;

    /** The language whose version is edited: `?locale=` when the site has it enabled, else its default; never client-set. */
    #[Locked]
    public string $locale = 'vi';

    /**
     * The language bar of a saved product on a site with more than one language (LangBar): one item per
     * enabled language, read in one query when the editor opens and again after each save. Empty: no bar.
     *
     * @var list<array{code: string, label: string, kind: string, done: int, total: int, href: string, current: bool, source: bool, public: bool, live: bool}>
     */
    #[Locked]
    public array $langBar = [];

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

    /** The main category's id (product_cat vocabulary): the first of categoryIds, kept in step with it. */
    public ?int $categoryId = null;

    /**
     * Every category the product is filed under, its main one («Mục chính») first — the pivot's
     * positions 0..n-1. Changed only by the list's boxes and stars (toggleCategory, setMainCategory,
     * asked by key), never sent by the browser.
     *
     * @var list<int>
     */
    #[Locked]
    public array $categoryIds = [];

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

    /** VND integer (D5), shown and typed the Vietnamese way (1.250.000); read by ProductPrice on save. */
    public ?string $price = null;

    /** The sale price, like the price; below it. */
    public ?string $salePrice = null;

    /** When the sale starts and ends, the site's wall clock (MOMENT); '' leaves that side open. */
    public ?string $saleStartsAt = '';

    public ?string $saleEndsAt = '';

    /** «Gỡ khỏi website lúc» (board 09, the «Đăng» card): the end time in the site's wall clock (MOMENT); '' = it stays. */
    public ?string $expiresAt = '';

    /** The end time «Bỏ giờ gỡ» emptied, which «Hoàn tác» puts back. */
    #[Locked]
    public string $undoExpiry = '';

    /** Blank = the stock is not tracked; 0 = sold out. */
    public ?string $stock = null;

    /** The labels «Nổi bật» / «Bán chạy». */
    public bool $featured = false;

    public bool $bestseller = false;

    /**
     * The main category as last drawn (its id and name), so a save after another tab deleted it can
     * name it. Server data.
     *
     * @var array{id: int, name: string}|null
     */
    #[Locked]
    public ?array $categoryShown = null;

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

    /** The website's clock the sale times are typed in, as the price card names it (read once, on opening). */
    #[Locked]
    public string $clock = '';

    /**
     * Whether the website sells (CatalogMode commerce), as the list and the storefront decide it: the
     * price card is open, else folded behind a note. Read once, on opening.
     */
    #[Locked]
    public bool $selling = false;

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

    /** A product's published status (a product, not an entry: no entry revision road applies). */
    private const string PRODUCT_LIVE = 'published';

    /** Only the status buttons (Đăng, and the ⋮'s «Gỡ khỏi website» / «Đưa về nháp») write a status; set for the length of that one request. */
    private bool $publishing = false;

    /** The status that button writes. */
    private string $target = self::PRODUCT_LIVE;

    /** The status the page showed when a status button was pressed; the button only changes a product still in it. */
    private ?string $publishFrom = null;

    /** Set while saving: the stored status differed from the one the page showed, so the button wrote none. */
    private bool $statusChangedElsewhere = false;

    /** Set while saving: a draft saving itself found the product no longer a draft; the stored product. */
    private ?Product $skippedFor = null;

    /** The stored product this request's checks were asked about (authorizeEdit), read once per request. */
    private ?Product $checked = null;

    public function mount(?string $ulid = null, ?string $locale = null): void
    {
        // a new product is written in the default language first: the shared fields are set there
        $this->locale = $ulid === null ? app(SiteLocale::class)->default() : $this->resolveLocale($locale ?? request()->query('locale'));
        $zone = app(SiteTime::class)->zone();
        $this->clock = $zone === SiteTime::DEFAULT_ZONE ? Cast::string(__('catalog.ed.zone_vn')) : $zone;
        $this->selling = ! app(CatalogMode::class)->isBrochure();
        $this->multiLocale = count(app(SiteLocale::class)->enabled()) > 1;
        $this->open($ulid);
    }

    /**
     * What the screen opens on, in the board's order: the plan first (no catalog: its card, nothing
     * read), then the right to see products (none: the no-permission card; a new product needs the
     * right to add one), then the product — a product of another website or one gone for good is a
     * 404, one outside the member's category limits a 403 — and a product in the Trash its card. A
     * read that fails (the database busy) gives the card with «Thử lại», nothing lost. Otherwise the
     * form, to change or (without the right to manage products) to read only.
     */
    private function open(?string $ulid): void
    {
        $this->screen = 'form';
        if (! $this->entitled()) {
            $this->screen = 'plan';

            return;
        }

        if ($ulid === null) {
            if (! Gate::allows('create', Product::class)) {
                $this->screen = 'noperm';

                return;
            }
            $this->address = $this->previewAddress(null);
            $this->saved = $this->tracked();
            $this->guideDue = true;

            return;
        }

        if (! Gate::allows('viewAny', Product::class)) {
            $this->screen = 'noperm';

            return;
        }

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $product = DB::transaction(function () use ($ulid): Product {
                $product = Product::withTrashed()->where('ulid', $ulid)->firstOrFail();
                // its categories read once: the policy's limits (main category) and the form both use them
                $product->setRelation('taxonomies', $product->taxonomies()->wherePivot('position', '<', Product::FACET_POSITION_BASE)->get());
                $this->authorize('view', $product);
                $this->load($product);
                $this->readLangBar();

                return $product;
            });
        } catch (QueryException $e) {
            report($e);
            $this->ulid = $ulid;
            $this->screen = 'error';

            return;
        }

        $this->canEdit = Gate::allows('update', $product);
        $this->mayTrash = $this->canEdit && $this->mayDelete($product);
        $this->guideDue = true;
        if ($product->trashed()) {
            $this->screen = 'trash';
            $this->mayRestore = Gate::allows('restore', $product);
        }
    }

    /** «Thử lại» on the card of a product that could not be read: open it again. */
    public function retry(): void
    {
        if ($this->screen !== 'error' || $this->ulid === null) {
            return;
        }
        $this->open($this->ulid);
    }

    /**
     * «Khôi phục» on the Trash card: the product comes back as a draft (not on the website until it is
     * published again), its SKU and address kept — the list's restore, through the same checks (the
     * plan, the policy asked about the product with the member's category limits). The form shows
     * again with what it held. A product restored meanwhile elsewhere just shows the form; one deleted
     * for good is said in words.
     */
    public function restore(): void
    {
        // only from the Trash card: the error card holds a key nothing has checked yet
        abort_unless($this->screen === 'trash', 404);
        // it comes back as a draft: the status is the product's, moved in the default language only
        if ($this->sharedRefused()) {
            return;
        }
        $this->bringBack(asDraft: true);
    }

    /**
     * «Hoàn tác» on the message at the bottom: after «Chuyển vào thùng rác» the product comes back
     * exactly as it was (its status kept, like the list's undo) and the form shows again with what was
     * typed; after «Bỏ khỏi sản phẩm» the picture goes back where it stood. A message without one (or
     * already closed) does nothing.
     */
    public function undoLast(): void
    {
        // only who could make the change can take it back (a member who may only read has nothing to undo)
        abort_unless($this->canEdit, 403);
        $undo = $this->undo;
        $image = $this->undoImage;
        $expiry = $this->undoExpiry;
        $this->clearToast();
        if ($undo === 'trash' && $this->screen === 'trash') {
            $this->bringBack(asDraft: false);
        } elseif ($undo === 'expiry' && $this->screen === 'form') {
            $this->authorizeEdit();
            if (! $this->sharedRefused()) {
                $this->expiresAt = $expiry;
            }
        } elseif ($undo === 'image' && $image !== null && $this->screen === 'form') {
            $this->authorizeEdit();
            if ($this->sharedRefused()) {
                return;
            }
            $ids = array_values(array_filter($this->imageMediaIds, static fn (int $id): bool => $id !== $image['id']));
            array_splice($ids, max(0, min($image['at'], count($ids))), 0, [$image['id']]);
            $this->imageMediaIds = $ids;
        }
    }

    private function bringBack(bool $asDraft): void
    {
        abort_if($this->ulid === null, 404);
        abort_unless($this->entitled(), 403);

        $back = DB::transaction(function () use ($asDraft): ?Product {
            $product = Product::onlyTrashed()->where('ulid', $this->ulid)->lockForUpdate()->first();
            if ($product === null) {
                // back already (another tab, another member): nothing to do but show the form
                return Product::query()->where('ulid', $this->ulid)->first();
            }
            $this->authorize('restore', $product);
            $product->restore();
            if ($asDraft) {
                $product->forceFill(['status' => 'draft'])->save();
            }

            return $product;
        });
        if ($back === null) {
            $this->productGone();

            return;
        }

        $this->screen = 'form';
        $this->status = $back->status;
        $this->publishedAt = self::iso($back->published_at);
        // a language with no version yet has no page on the website
        $this->liveUrl = $this->status === 'published' && $this->slug !== '' ? $this->address['base'].$this->slug : null;
        $this->checked = null;
        $this->canEdit = Gate::allows('update', $back);
        $this->mayTrash = $this->canEdit && $this->mayDelete($back);
        $this->readLangBar();
        if ($asDraft) {
            $this->dispatch('bs-toast', text: Cast::string(__('catalog.ed.toast_restored')));
        }
    }

    /** Close the message the member (or its timer) names; a newer message stays. */
    public function closeToast(int $seq): void
    {
        if ($seq === $this->toastSeq) {
            $this->clearToast();
        }
    }

    private function clearToast(): void
    {
        $this->toastMsg = '';
        $this->undo = null;
        $this->undoImage = null;
        $this->undoExpiry = '';
    }

    /**
     * @param  array{id: int, at: int}|null  $image
     */
    private function say(string $message, ?string $undo, ?array $image = null): void
    {
        $this->toastMsg = $message;
        $this->toastSeq++;
        $this->undo = $undo;
        $this->undoImage = $image;
    }

    /**
     * «Chuyển vào thùng rác» in the ⋮, for a saved product — the same move as the list's row, through
     * the same checks (the plan has the catalog; the policy asked about the product, with the member's
     * category limits): the product goes to the Trash, keeping its translations, images and sku, and
     * the screen shows the Trash card with a message that offers «Hoàn tác». A right taken away since the screen opened (the
     * role's, or the product now outside the member's category limits) is refused in words. A product
     * already in the Trash (another tab, another member) is not moved again — that would restart its
     * purge clock — and the member gets the list's words for a product that is gone: the row is read
     * again and locked before it moves.
     */
    public function trash(): void
    {
        // not from the card of a product that could not be read: nothing has checked its key yet (the
        // Trash card's own second move is answered below, in words)
        abort_if($this->ulid === null || $this->screen === 'error', 404);
        abort_unless($this->entitled(), 403);
        $product = Product::query()->where('ulid', $this->ulid)->first();
        if ($product === null) {
            $this->productGone();

            return;
        }
        try {
            $this->authorize('delete', $product);
        } catch (AuthorizationException) {
            $this->mayTrash = false;
            $this->dispatch('bs-toast', text: $this->trashRefusal(), stay: true, close: Cast::string(__('admin.close')));

            return;
        }

        $name = DB::transaction(function () use ($product): ?string {
            $live = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if ($live === null) {
                return null;
            }
            $name = $live->translations()->where('locale', $this->locale)->value('name');
            $live->delete();

            return is_string($name) ? $name : '';
        });
        if ($name === null) {
            $this->productGone();

            return;
        }

        // the board's way: the screen stays, shows the Trash card in place of the form (which keeps what
        // was typed, for «Hoàn tác») and says so with «Hoàn tác»
        $this->screen = 'trash';
        $this->mayRestore = Gate::allows('restore', $product);
        $this->checked = null;
        $this->say(Cast::string(__('catalog.toast_trashed', ['name' => $name !== '' ? $name : ($this->name !== '' ? $this->name : __('catalog.untitled'))])), 'trash');
    }

    /**
     * Why the member may not move this product to the Trash: outside their product category limits
     * (the policy's own sentence, naming the categories they may change), or not a right their role has.
     */
    private function trashRefusal(): string
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('catalog.manage')
            ? app(CategoryScopes::class)->refusal($user, CategoryScopes::PRODUCTS)
            : Cast::string(__('catalog.ed.refused_trash'));
    }

    /**
     * The product is no longer live (in the Trash, or gone): the editor's words for it, in a message that
     * stays until it is closed. The screen stays as it is, so nothing typed is lost.
     */
    private function productGone(): void
    {
        $this->skipRender();
        $this->dispatch('bs-toast', text: Cast::string(__('admin.editor.gone')), stay: true, close: Cast::string(__('admin.close')));
    }

    /**
     * The language to edit: a `?locale=` the site has enabled (the language bar's links carry it), else
     * the site's default. #[Locked] $locale then pins it for the component's lifetime, so the browser
     * can never change which version is written.
     */
    private function resolveLocale(mixed $requested): string
    {
        $languages = app(SiteLocale::class);

        return is_string($requested) && in_array($requested, $languages->enabled(), true) ? $requested : $languages->default();
    }

    /** Another language than the website's default is open: the product's shared fields are locked. */
    private function translating(): bool
    {
        return $this->locale !== app(SiteLocale::class)->default();
    }

    /** Why a shared field is locked here: where to change it (the post editor's words). */
    private function sharedWhy(): string
    {
        return Cast::string(__('entries.ed.shared_guard', ['lang' => LangBar::name(app(SiteLocale::class)->default())]));
    }

    /**
     * In another language than the website's default the product's shared fields (pictures, price,
     * stock, sale, SKU, labels, categories, status) are not changed: say where they are, in a message
     * that stays until it is closed. Returns whether it refused.
     */
    private function sharedRefused(): bool
    {
        if (! $this->translating()) {
            return false;
        }
        $this->dispatch('bs-toast', text: $this->sharedWhy(), stay: true, close: Cast::string(__('admin.close')));

        return true;
    }

    /**
     * A shared field sent from another language than the default (its control is locked there, so only a
     * hand-made request does it): put back what is stored and say where it is changed.
     */
    private function keepShared(string $field): bool
    {
        if (! $this->sharedRefused()) {
            return false;
        }
        $saved = $this->saved[$field] ?? '';
        $this->{$field} = match ($field) {
            'featured', 'bestseller' => $saved === '1',
            'saleStartsAt', 'saleEndsAt', 'expiresAt' => $saved,
            'imageMediaIds' => $this->normalizeIds((array) json_decode($saved !== '' ? $saved : '[]', true)),
            default => $saved !== '' ? $saved : null,
        };

        return true;
    }

    /**
     * The language bar's items for a saved product, when the site writes in more than one language:
     * each version's state from one read of its stored rows (ProductLangStates), each opening this
     * editor in its language (the default language without `?locale=`).
     */
    private function readLangBar(): void
    {
        if (! $this->multiLocale || $this->ulid === null || $this->productId === null) {
            $this->langBar = [];

            return;
        }
        $languages = app(SiteLocale::class);
        $default = $languages->default();
        $ulid = $this->ulid;
        $this->langBar = LangBar::items(
            ProductLangStates::read($this->productId, $this->status === self::PRODUCT_LIVE),
            $languages->enabled(),
            $default,
            $languages->public(),
            $this->locale,
            ProductLangStates::FIELDS,
            static fn (string $code): string => route('admin.catalog.edit', ['ulid' => $ulid] + ($code !== $default ? ['locale' => $code] : [])),
        );
    }

    private function entitled(): bool
    {
        return in_array('catalog', app(Entitlement::class)->entitledModuleKeys(), true);
    }

    private function mayDelete(Product $product): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('delete', $product);
    }

    /** The catalog's permalink preset may put the category in the address: the preview follows the pick. */
    public function updatedCategoryId(): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            $this->categoryId = $this->categoryIds[0] ?? null;

            return;
        }
        // like the list's boxes and stars: a category outside the member's limits is refused (none is
        // left to the save, which asks for one in words)
        if ($this->categoryId !== null) {
            $this->authorizeFiling($this->categoryId);
        }
        // the main category set on its own: it leads the list (none: the main one leaves it)
        $others = array_values(array_filter(array_slice($this->categoryIds, 1), fn (int $id): bool => $id !== $this->categoryId));
        $this->categoryIds = $this->categoryId !== null ? [$this->categoryId, ...$others] : $others;
        $this->categoryId = $this->categoryIds[0] ?? null;
        $this->address = $this->previewAddress($this->pickedCategory());
    }

    /**
     * A box in the categories list: file the product under the category too, or take it out. The
     * first category ticked becomes the main one; when the main one is unticked the next one takes
     * its place. A member limited to some product categories adds or takes out only categories inside
     * them (the list offers no other; a stored one outside stays); a category of another website or
     * none at all is refused. Nothing is saved until the product is.
     */
    public function toggleCategory(string $ulid): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        $id = $this->categoryIdOf($ulid);
        $this->authorizeFiling($id);
        if (in_array($id, $this->categoryIds, true)) {
            $this->categoryIds = array_values(array_filter($this->categoryIds, static fn (int $at): bool => $at !== $id));
        } else {
            $this->categoryIds[] = $id;
        }
        $this->categoryChanged();
    }

    /** «Đặt làm mục chính» on a ticked category: it moves to the front, the main category now. */
    public function setMainCategory(string $ulid): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        $id = $this->categoryIdOf($ulid);
        abort_unless(in_array($id, $this->categoryIds, true), 404);
        $this->authorizeFiling($id);
        $this->categoryIds = [$id, ...array_values(array_filter($this->categoryIds, static fn (int $at): bool => $at !== $id))];
        $this->categoryChanged();
    }

    /** A product category of this website by its key (RLS keeps other websites' out): its id, or a 404. */
    private function categoryIdOf(string $ulid): int
    {
        $id = Taxonomy::query()->where('vocabulary', 'product_cat')->where('ulid', $ulid)->value('id');
        abort_unless(is_numeric($id), 404);

        return (int) $id;
    }

    /** A category the member puts the product under must be inside their product limits (CatalogPolicy::fileUnder). */
    private function authorizeFiling(int $id): void
    {
        $this->authorize('fileUnder', [Product::class, $id]);
    }

    private function categoryChanged(): void
    {
        $previous = $this->categoryId;
        $this->categoryId = $this->categoryIds[0] ?? null;
        $this->resetErrorBag('categoryId');
        if ($previous !== $this->categoryId) {
            $this->address = $this->previewAddress($this->pickedCategory());
        }
    }

    /** Leaving the price: shown the Vietnamese way when it reads, checked, and the sale price checked again if it was refused. */
    public function updatedPrice(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('price')) {
            return;
        }
        $this->price = self::tidyAmount($this->price, ProductPrice::AMOUNT_DIGITS);
        $this->recheck('price', ...($this->shownErrors()->has('salePrice') ? ['salePrice'] : []));
    }

    public function updatedSalePrice(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('salePrice')) {
            return;
        }
        $this->salePrice = self::tidyAmount($this->salePrice, ProductPrice::AMOUNT_DIGITS);
        $this->recheck('salePrice');
    }

    public function updatedStock(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('stock')) {
            return;
        }
        $count = ProductPrice::readable($this->stock, ProductPrice::STOCK_DIGITS) ? ProductPrice::parse($this->stock, ProductPrice::STOCK_DIGITS) : false;
        $this->stock = match (true) {
            $count === null => null,
            is_int($count) => (string) $count,
            default => $this->stock,
        };
        $this->recheck('stock');
    }

    /** Leaving the start: checked, and the end checked against it. */
    public function updatedSaleStartsAt(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('saleStartsAt')) {
            return;
        }
        $this->saleStartsAt ??= '';
        $this->recheck('saleStartsAt', ...($this->saleEndsAt !== '' ? ['saleEndsAt'] : []));
    }

    /** «Gỡ khỏi website lúc», as the field is left: a changed time must still be to come (the reason stays under it). */
    public function updatedExpiresAt(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('expiresAt')) {
            return;
        }
        $this->expiresAt ??= '';
        $this->recheck('expiresAt');
    }

    /** «Bỏ giờ gỡ»: the field empties (saved with the next save) and the message offers «Hoàn tác». */
    public function clearExpiry(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('expiresAt') || ($this->expiresAt ?? '') === '') {
            return;
        }
        $old = (string) $this->expiresAt;
        $this->expiresAt = '';
        $this->resetErrorBag('expiresAt');
        $this->say(Cast::string(__('catalog.ed.exp_cleared')), 'expiry');
        $this->undoExpiry = $old;
    }

    public function updatedSaleEndsAt(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('saleEndsAt')) {
            return;
        }
        $this->saleEndsAt ??= '';
        $this->recheck('saleEndsAt');
    }

    /**
     * The address, as the field is left: an emptied one is made again from the name, and said; a name
     * with nothing usable leaves it blank with the reason under the field. A typed one is kept.
     */
    public function updatedSlug(): void
    {
        $this->authorizeEdit();
        $this->slug = PlainText::line($this->slug);
        $this->resetErrorBag('slug');
        if (trim($this->slug) !== '') {
            return;
        }
        if ($this->fillBlankSlug()) {
            $this->dispatch('bs-toast', text: Cast::string(__('catalog.ed.slug_auto', ['slug' => $this->slug])));
        } elseif (trim($this->name) !== '') {
            $this->addError('slug', __('catalog.slug_invalid'));
        }
    }

    /** Leaving the SKU: a code another product holds is said at once (the board's check on leaving the field). */
    public function updatedSku(): void
    {
        $this->authorizeEdit();
        if ($this->keepShared('sku')) {
            return;
        }
        $this->sku = $this->sku !== null ? SkuCode::normalize($this->sku) : null;
        $this->recheck('sku');
    }

    /**
     * Check these fields alone, each message kept or cleared on its own field; the others stay as
     * they are.
     */
    private function recheck(string ...$fields): void
    {
        foreach ($fields as $field) {
            try {
                $this->validateOnly($field);
            } catch (ValidationException $e) {
                // validateOnly hands back this field's messages merged with the others already shown
                $this->setErrorBag($e->validator->errors());
            }
        }
    }

    /** The messages the page shows now. */
    private function shownErrors(): MessageBag
    {
        $bag = $this->getErrorBag();

        return $bag instanceof MessageBag ? $bag : new MessageBag;
    }

    /** A typed amount the Vietnamese way when it reads (null when blank); otherwise as typed, for the person to fix. */
    private static function tidyAmount(?string $typed, int $digits): ?string
    {
        if (! ProductPrice::readable($typed, $digits)) {
            return $typed;
        }
        $amount = ProductPrice::parse($typed, $digits);

        return $amount === null ? null : ProductPrice::format($amount);
    }

    /** «Nổi bật» / «Bán chạy» are the product's: another language than the default keeps them as stored. */
    public function updatedFeatured(): void
    {
        $this->keepShared('featured');
    }

    public function updatedBestseller(): void
    {
        $this->keepShared('bestseller');
    }

    /** The pictures are the product's: another language than the default keeps them as stored. */
    public function updatedImageMediaIds(): void
    {
        $this->keepShared('imageMediaIds');
    }

    /** "Lưu nháp" / "Lưu": save, keeping the status. */
    public function save(): void
    {
        $this->onTheForm();
        $this->authorizeEdit();
        try {
            $saved = $this->commit(quiet: false);
        } catch (ValidationException $e) {
            $this->sayWhatToFix($e->validator->errors());

            throw $e;
        }
        if (! $saved) {
            $this->sayWhatToFix($this->shownErrors());
        }
    }

    /**
     * Only the form saves: a card in its place (the Trash, a read that failed) holds nothing to keep —
     * asked first, before the product is read again. The plan and no-permission cards are refused by
     * the checks that follow (authorizeEdit).
     */
    private function onTheForm(): void
    {
        abort_if(in_array($this->screen, ['trash', 'error'], true), 404);
    }

    /** "Đăng" / "Cập nhật": save it published. Nothing saved, the status stays as it was. */
    public function publish(): void
    {
        $this->saveAs(self::PRODUCT_LIVE);
    }

    /**
     * «Gỡ khỏi website» in the ⋮ of a published product: save it archived — visitors no longer see it,
     * its content and address kept. A save like the others: a field to fix stops it.
     */
    public function unpublish(): void
    {
        abort_if($this->ulid === null || $this->status !== self::PRODUCT_LIVE, 404);
        $this->saveAs('archived');
    }

    /** «Đưa về nháp» in the ⋮ of a published or archived product: save it as a draft. */
    public function toDraft(): void
    {
        abort_if($this->ulid === null || ! in_array($this->status, [self::PRODUCT_LIVE, 'archived'], true), 404);
        $this->saveAs('draft');
    }

    private function saveAs(string $status): void
    {
        $this->onTheForm();
        $this->authorizeEdit();
        // the status is the product's, one for every language: it moves in the default language only
        if ($this->sharedRefused()) {
            return;
        }
        $was = $this->status;
        $this->publishing = true;
        $this->target = $status;
        $this->publishFrom = $was;

        try {
            $saved = $this->commit(quiet: false);
        } catch (ValidationException $e) {
            $this->status = $was;
            $this->sayWhatToFix($e->validator->errors());

            throw $e;
        } finally {
            $this->publishing = false;
            $this->publishFrom = null;
        }

        if (! $saved) {
            $this->status = $was;
            $this->sayWhatToFix($this->shownErrors());
        }
    }

    /**
     * A button save a field stopped: how many fields need fixing, in a message that stays until it is
     * closed, and the cursor in the first of them (the page opens «Thêm tùy chọn» and the price card
     * when the field is inside them).
     */
    private function sayWhatToFix(MessageBag $errors): void
    {
        $fields = [];
        foreach ($errors->keys() as $key) {
            $fields[Str::before($key, '.')] = true;
        }
        if (! $this->saveFailed || $fields === []) {
            return;
        }

        $this->dispatch('bs-toast',
            text: Cast::string(__('catalog.ed.toast_fix', ['n' => count($fields)])),
            icon: Blade::render('<x-bs-admin::icon name="ri-error-warning" class="size-4" />'),
            stay: true,
            close: Cast::string(__('admin.close')),
        );
        // after the page has drawn the messages and opened the groups that hold them
        $this->js('window.setTimeout(() => { const el = document.querySelector(\'[data-catalog-editor] [aria-invalid="true"]\'); if (el) { el.focus() } }, 60)');
    }

    /**
     * A draft saves itself a few seconds after the last change (the browser asks). A published or
     * archived product never does, nor a form with nothing changed; a form with a field still to fix
     * is left as it is, the messages already shown kept.
     */
    public function autosave(): void
    {
        if ($this->screen !== 'form') {
            return; // a timer left over from the form (the product just went to the Trash): nothing to save
        }
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
            $this->reset('name', 'slug', 'description', 'summary', 'seoTitle', 'seoDescription', 'categoryId', 'categoryIds', 'price', 'salePrice', 'saleStartsAt', 'saleEndsAt', 'expiresAt', 'stock', 'featured', 'bestseller', 'sku', 'imageMediaIds', 'summaryStored', 'summaryShown');
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
     * «Chèn N ảnh» in the shared picker: the pictures join the strip, then the message says how many
     * (design 09: «Đã thêm N ảnh.», and on an empty strip that the first one is the main picture).
     */
    public function insertPicked(): void
    {
        $before = count($this->imageMediaIds);
        $this->insertLibraryPicked();
        $added = count($this->imageMediaIds) - $before;
        if ($added > 0) {
            $this->say(Cast::string(__($before === 0 ? 'catalog.ed.toast_img_added_first' : 'catalog.ed.toast_img_added', ['count' => $added])), null);
        }
    }

    /**
     * «Thêm ảnh» opens the shared picker for the product's pictures, which are every language's: another
     * language than the default says where to change them and opens nothing. The description's own
     * «Chèn ảnh» (editor mode) is this language's and always opens.
     */
    public function openImagePicker(string $mode = 'gallery'): void
    {
        if (! in_array($mode, ['editor', 'single'], true) && $this->sharedRefused()) {
            return;
        }
        $this->openLibraryPicker($mode);
    }

    /**
     * Gallery hook for the shared picker (gallery mode): append once — duplicates are the
     * UNIQUE(product_id, media_id) guard, app-side. The picker stays open for multi-pick.
     */
    protected function addGalleryImage(int $mediaId): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        if (! in_array($mediaId, $this->imageMediaIds, true)) {
            $this->imageMediaIds[] = $mediaId;
        }
    }

    /**
     * «Bỏ khỏi sản phẩm»: the picture leaves the product (it stays in the Library), with «Hoàn tác»
     * that puts it back where it stood. Taking the main picture off says which one takes its place.
     */
    public function removeImage(int $index): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        if (! array_key_exists($index, $this->imageMediaIds)) {
            return;
        }

        $ids = $this->imageMediaIds;
        $id = $ids[$index];
        array_splice($ids, $index, 1); // splice re-packs the keys — still a list
        $this->imageMediaIds = $ids;

        $names = Media::query()->whereIn('id', array_filter([$id, $index === 0 ? ($ids[0] ?? null) : null]))->pluck('original_filename', 'id');
        $name = Cast::string($names->get($id)) ?: Cast::string(__('catalog.ed.img_unnamed'));
        $next = $index === 0 && $ids !== [] ? (Cast::string($names->get($ids[0])) ?: Cast::string(__('catalog.ed.img_unnamed'))) : null;
        $this->say(Cast::string($next !== null
            ? __('catalog.ed.toast_img_cover_removed', ['name' => $name, 'next' => $next])
            : __('catalog.ed.toast_img_removed', ['name' => $name])), 'image', ['id' => $id, 'at' => $index]);
    }

    /** A picture dragged to another place in the strip (or moved by its buttons): it moves there, the others close up. */
    public function moveImage(int $from, int $to): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        if (! array_key_exists($from, $this->imageMediaIds) || ! array_key_exists($to, $this->imageMediaIds) || $from === $to) {
            return;
        }

        $ids = $this->imageMediaIds;
        $moved = array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, $moved);
        $this->imageMediaIds = $ids;
    }

    /** «Đặt làm ảnh đại diện»: the picture moves to the front — the first picture is the product's main one. */
    public function setCover(int $index): void
    {
        $this->moveImage($index, 0);
    }

    public function moveImageUp(int $index): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        $this->swapImages($index, $index - 1);
    }

    public function moveImageDown(int $index): void
    {
        $this->authorizeEdit();
        if ($this->sharedRefused()) {
            return;
        }
        $this->swapImages($index, $index + 1);
    }

    public function render(): View
    {
        $isNew = $this->ulid === null;
        $title = Cast::string(__($isNew ? 'catalog.create_title' : ($this->canEdit ? 'catalog.edit_title' : 'catalog.ed.view_title')));
        $user = auth()->user();
        if ($this->screen !== 'form') {
            return $this->stateView($title, $isNew, $user instanceof User ? $user : null);
        }
        $urls = app(MediaUrlResolver::class);
        $actions = $this->actions();
        $selling = $this->selling;
        $categories = $this->categoryRows();

        return view('admin::livewire.catalog.editor', [
            'frame' => [
                'title' => $title,
                'crumbs' => [
                    ['label' => Cast::string(__('catalog.ed.crumb_list')), 'url' => route('admin.catalog.index')],
                    ['label' => Cast::string(__($isNew ? 'admin.editor.crumb_new' : 'admin.editor.crumb_edit'))],
                ],
                'description' => Cast::string(__($isNew ? 'catalog.ed.desc_new' : 'catalog.ed.desc_edit')),
                'guide' => $this->guideDue && $user instanceof User
                    ? EditorGuide::for('editor-product', $title, Cast::string(__('guide.editor.unit_product')), $isNew, $actions, false)->toArray($user, app(CurrentSite::class)->id())
                    : null,
                // the preview page is for who may manage products: a member who may only read gets none
                'preview' => match (true) {
                    ! $this->canEdit => ['url' => null, 'why' => null],
                    $isNew => ['url' => null, 'why' => Cast::string(__('admin.editor.preview_new'))],
                    default => ['url' => route('admin.catalog.preview', ['ulid' => $this->ulid] + ($this->translating() ? ['locale' => $this->locale] : [])), 'why' => null],
                },
                'trash' => ! $isNew && $this->mayTrash ? ['why' => null] : null,
            ],
            'categories' => $categories,
            'selling' => $selling,
            'priceCard' => $this->priceCard(),
            'mayOpenSettings' => $user instanceof User && $user->can('settings.manage'),
            'mayManageCategories' => $user instanceof User && $user->can('taxonomies.manage'),
            'galleryImages' => $this->galleryImages($urls),
            'urls' => $urls,
            'actions' => $actions,
            'note' => $this->saveNote(),
            'leave' => $this->leaveWords(),
            'publishCard' => $this->publishCard($selling),
            'zoneName' => app(SiteTime::class)->offsetLabel(),
            'summaryWarning' => $this->summaryWarning(),
            'changes' => $this->changeCount(),
            'roRole' => ! $this->canEdit && $user instanceof User ? RoleLabel::topOf($user) : null,
            // «Quản lý ngôn ngữ» on the language bar: the site's languages, for a member who may change them
            'langManage' => $this->langBar !== [] && $user instanceof User && $user->can('settings.manage') ? route('admin.settings.languages') : null,
            // the product's own fields are every language's: another language shows them locked («Chung»)
            'sharedLock' => $this->translating(),
            'sharedWhy' => $this->translating() ? $this->sharedWhy() : '',
        ]);
    }

    /**
     * The card in place of the form (the board's states): the trail and the screen's name above it, the
     * card's own words and buttons, and the message at the bottom (the Trash card's «Hoàn tác»).
     */
    private function stateView(string $title, bool $isNew, ?User $user): View
    {
        return view('admin::livewire.catalog.editor-state', [
            'title' => $title,
            'crumbs' => [
                ['label' => Cast::string(__('catalog.ed.crumb_list')), 'url' => route('admin.catalog.index')],
                ['label' => Cast::string(__($isNew ? 'admin.editor.crumb_new' : 'admin.editor.crumb_edit'))],
            ],
            'role' => $user !== null && $this->screen === 'noperm' ? RoleLabel::topOf($user) : null,
            'mayList' => $user !== null && $this->screen === 'noperm' && $user->can('viewAny', Product::class),
            'supportMail' => $this->screen === 'plan' ? (Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address'))) : '',
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
        // UNIQUE(site_id, sku) index (products in the Trash too) that would otherwise surface as a raw 500.
        return [
            'name' => ['required', 'string', $this->nameIsValid(...)],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slugIsFree(...)],
            'description' => ['nullable', 'string'],
            // the limit is for new text: an older summary left as it was is kept as stored
            'summary' => ['nullable', 'string', ...($this->keepsStoredSummary() ? [] : ['max:'.self::SUMMARY_MAX])],
            'seoTitle' => ['nullable', 'string', 'max:255'],
            'seoDescription' => ['nullable', 'string', 'max:255'],
            // a member limited to some product categories must pick one inside them (CatalogPolicy::fileUnder)
            'categoryId' => [Rule::requiredIf(fn (): bool => $this->limitedOnProducts()), 'nullable', 'integer',
                $this->categoryExists(...), $this->categoryIsInsideLimits(...)],
            // the other categories: each one a product category of this website (RLS), and one the
            // product was not under before inside the member's limits (one already there they cannot
            // see is kept as it is)
            'categoryIds' => ['array', 'max:'.(Product::FACET_POSITION_BASE - 1)],
            'categoryIds.*' => ['integer', 'distinct', $this->categoryExists(...), $this->addedCategoryIsInsideLimits(...)],
            'price' => ['nullable', 'string', $this->amountReads(...)],
            'salePrice' => ['nullable', 'string', $this->amountReads(...), $this->saleIsBelowPrice(...)],
            'saleStartsAt' => ['string', $this->momentReads(...)],
            'saleEndsAt' => ['string', $this->momentReads(...), $this->saleEndsAfterStart(...)],
            // judged only when changed here: a product taken down at its end time keeps a past one
            'expiresAt' => ($this->expiresAt ?? '') !== ($this->saved['expiresAt'] ?? '')
                ? ['string', new ExpiryTime(app(SiteTime::class)->zone())]
                : ['string'],
            'stock' => ['nullable', 'string', $this->stockReads(...)],
            'featured' => ['boolean'],
            'bestseller' => ['boolean'],
            'sku' => ['nullable', 'string', 'max:'.SkuCode::MAX, $this->skuIsFree(...)],
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
            ...($this->limitedOnProducts() ? ['categoryId.required' => $this->limitsRefusal()] : []),
            'imageMediaIds.*.exists' => __('catalog.gallery_invalid'),
            'imageMediaIds.*.integer' => __('catalog.gallery_invalid'),
            'seoTitle.max' => __('catalog.seo_title_too_long'),
            'seoDescription.max' => __('catalog.seo_description_too_long'),
            'summary.max' => __('catalog.ed.summary_too_long', ['max' => self::SUMMARY_MAX]),
        ];
    }

    /**
     * Every action re-checks the plan and the right to manage products (the update endpoint skips
     * route middleware, and the routes leave both to this screen so it can show their cards) — about
     * the stored product itself, so a member limited to some product categories is refused one outside
     * them, and a member who may only see products is refused every write.
     */
    private function authorizeEdit(): void
    {
        abort_unless($this->entitled(), 403);
        if ($this->ulid === null) {
            $this->authorize('create', Product::class);

            return;
        }
        $this->checked ??= $this->resolveOrFail($this->ulid);
        $this->authorize('update', $this->checked);
    }

    private function limitedOnProducts(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(CategoryScopes::class)->isLimited($user, CategoryScopes::PRODUCTS);
    }

    private function limitsRefusal(): string
    {
        $user = auth()->user();

        return $user instanceof User ? app(CategoryScopes::class)->refusal($user, CategoryScopes::PRODUCTS) : __('catalog.category_invalid');
    }

    /** The picked category is inside the member's product limits (always, for a member not limited on products). */
    private function categoryIsInsideLimits(string $attribute, mixed $value, \Closure $fail): void
    {
        $user = auth()->user();
        if ($user instanceof User && is_numeric($value) && ! app(CategoryScopes::class)->coversId($user, (int) $value, CategoryScopes::PRODUCTS)) {
            $fail($this->limitsRefusal());
        }
    }

    /** A category the product was not under when last saved is inside the member's product limits. */
    private function addedCategoryIsInsideLimits(string $attribute, mixed $value, \Closure $fail): void
    {
        $before = json_decode($this->saved['categoryIds'] ?? '[]', true);
        if (is_numeric($value) && ! in_array((int) $value, is_array($before) ? $before : [], true)) {
            $this->categoryIsInsideLimits($attribute, $value, $fail);
        }
    }

    /** A category's place among the product's (its pivot position; none reads as past every category). */
    private static function pivotPosition(Taxonomy $taxonomy): int
    {
        $pivot = $taxonomy->relationLoaded('pivot') ? $taxonomy->getRelation('pivot') : null;
        $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;

        return is_numeric($position) ? (int) $position : Product::FACET_POSITION_BASE;
    }

    /**
     * The categories to store, decided before anything is written. A new product, or a member not
     * limited on products, stores the form's list as it is. For a member limited to some categories the
     * list is held against what the product is stored under now (read under the row lock, so a change
     * made elsewhere since the form opened counts): a category outside their limits is kept only while
     * the product is still under it (one taken out elsewhere is not filed again), and every stored one
     * outside the limits stays (they never take out one they cannot see). The main category is never
     * dropped that way — it would move the product's address — the save is refused on its field instead
     * (a second line of defence: the categoryId rule already refuses a main category outside the limits).
     *
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function filedUnder(?Product $product): array
    {
        $user = auth()->user();
        if ($product === null || ! $user instanceof User || ! $this->limitedOnProducts()) {
            return $this->categoryIds;
        }
        $relation = $product->taxonomies();
        $stored = array_map(Cast::int(...), $relation->newPivotQuery()
            ->where('position', '<', Product::FACET_POSITION_BASE)
            ->orderBy('position')
            ->pluck($relation->getRelatedPivotKeyName())
            ->all());
        $scopes = app(CategoryScopes::class);
        $inside = static fn (int $id): bool => $scopes->coversId($user, $id, CategoryScopes::PRODUCTS);
        $form = array_values(array_filter($this->categoryIds, static fn (int $id): bool => $inside($id) || in_array($id, $stored, true)));
        if ($this->categoryIds !== [] && ($form[0] ?? null) !== $this->categoryIds[0]) {
            throw ValidationException::withMessages(['categoryId' => $this->limitsRefusal()]);
        }
        $kept = array_filter($stored, static fn (int $id): bool => ! in_array($id, $form, true) && ! $inside($id));

        return [...$form, ...array_values($kept)];
    }

    /** Fill the form from a saved product. */
    private function load(Product $product): void
    {
        $this->ulid = $product->ulid;
        $this->productId = $product->id;
        $this->sku = $product->sku;
        $this->price = $product->price !== null ? ProductPrice::format($product->price) : null;
        $this->salePrice = $product->sale_price !== null ? ProductPrice::format($product->sale_price) : null;
        $time = app(SiteTime::class);
        $this->saleStartsAt = $time->forDisplay($product->sale_starts_at)?->format(self::MOMENT) ?? '';
        $this->saleEndsAt = $time->forDisplay($product->sale_ends_at)?->format(self::MOMENT) ?? '';
        $this->expiresAt = $time->forDisplay($product->expires_at)?->format(self::MOMENT) ?? '';
        $this->stock = $product->stock !== null ? (string) $product->stock : null;
        $this->featured = $product->is_featured;
        $this->bestseller = $product->is_bestseller;
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

        // every category in one read (or the one the screen opened with), the main one (position 0) first
        $categories = $product->relationLoaded('taxonomies')
            ? $product->taxonomies->filter(static fn (Taxonomy $taxonomy): bool => self::pivotPosition($taxonomy) < Product::FACET_POSITION_BASE)
                ->sortBy(static fn (Taxonomy $taxonomy): int => self::pivotPosition($taxonomy))
            : $product->taxonomies()->wherePivot('position', '<', Product::FACET_POSITION_BASE)->get();
        $this->categoryIds = array_values($categories->map(static fn (Taxonomy $taxonomy): int => $taxonomy->id)->all());
        $category = $categories->first();
        $this->categoryId = $category?->id;
        $this->address = $this->previewAddress($category);
        $this->imageMediaIds = $this->normalizeIds($product->images()->pluck('media_id')->all());
        $this->saved = $this->tracked();
        // a language with no version yet has no page on the website
        $this->liveUrl = $this->status === 'published' && $translation instanceof ProductTranslation ? $this->address['base'].$this->slug : null;
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
        $wasStatus = $this->publishFrom ?? $this->status;
        $slugWasBlank = trim($this->slug) === '';
        // A draft saving itself while the person types stores the tidied text but leaves the fields
        // exactly as typed: a space at the end of a word or a new line must not vanish under the
        // cursor, and a blank address keeps following the name until a button save fixes it.
        $typed = $quiet ? array_intersect_key(get_object_vars($this), array_flip(self::AS_TYPED)) : [];

        try {
            $product = DB::transaction(fn (): ?Product => $this->persist($quiet));
        } catch (ExpiredContentException|RouteConflictException|UniqueConstraintViolationException|ValidationException $e) {
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
            if ($e instanceof ExpiredContentException) {
                // going live past its end time: nothing kept, the end time is moved or cleared first
                $this->dispatch('bs-toast', text: Cast::string(__('catalog.expired_publish_why')), stay: true, close: Cast::string(__('admin.close')));

                return false;
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
        // what this save stored moves this language's state on the bar, and the others' when it is the source
        $this->readLangBar();

        if ($wasNew) {
            $this->mayTrash = $this->mayDelete($product);
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
                // a move from the ⋮ (the board's words)
                $this->publishing && $this->status === 'archived' && $wasLive => __('catalog.ed.toast_unpublished'),
                $this->publishing && $this->status === 'draft' && $wasStatus !== 'draft' => __('catalog.ed.toast_to_draft'),
                $this->status === 'draft' => __('catalog.ed.toast_draft', ['time' => $time]),
                default => __('catalog.ed.toast_saved', ['time' => $time]),
            });
        }

        return true;
    }

    /**
     * The two save buttons: the secondary one saves keeping the status (none on a published
     * product), the main one saves it published. A member who may only read the product gets
     * neither (the board's read-only form: no save, the ⋮ holding only «Cỡ chữ», no bar along the bottom).
     *
     * @return array{secondary: string|null, primary: string|null, primaryAction: string, primaryIcon: string}
     */
    private function actions(): array
    {
        $live = $this->status === 'published';
        if (! $this->canEdit) {
            return ['secondary' => null, 'primary' => null, 'primaryAction' => 'publish', 'primaryIcon' => 'ri-global'];
        }
        if ($this->translating()) {
            // another language's version: one button that saves it, the product's status kept (it is shared)
            return [
                'secondary' => null,
                'primary' => (string) match ($this->status) {
                    'published' => __('catalog.ed.update'),
                    'archived' => __('catalog.ed.save_plain'),
                    default => __('catalog.ed.save_draft'),
                },
                'primaryAction' => 'save',
                'primaryIcon' => $live ? 'ri-refresh' : 'ri-save-3',
            ];
        }

        return [
            'secondary' => match ($this->status) {
                'published' => null,
                'archived' => (string) __('catalog.ed.save_plain'),
                default => (string) __('catalog.ed.save_draft'),
            },
            'primary' => (string) ($live ? __('catalog.ed.update') : __('catalog.ed.publish')),
            'primaryAction' => 'publish',
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
    private function publishCard(bool $selling): array
    {
        $time = app(SiteTime::class);
        $when = fn (?string $iso): ?string => $iso === null ? null : $time->forDisplay(CarbonImmutable::parse($iso))?->format('d/m/Y H:i');
        $published = $when($this->publishedAt);
        $updated = $when($this->savedAt);

        $seen = __('catalog.ed.seen_no');
        $end = ExpiryTime::parse($this->saved['expiresAt'] ?? '', $time->zone());
        $endShown = $end?->format('d/m/Y H:i') ?? '';
        $endPast = $end !== null && $end <= CarbonImmutable::now();
        if ($this->status === 'published') {
            $price = ProductPrice::parse($this->saved['price'] ?? null);
            $sale = ProductPrice::parse($this->saved['salePrice'] ?? null);
            $onSale = ProductPrice::saleApplies($price, $sale, $this->moment($this->saved['saleStartsAt'] ?? ''), $this->moment($this->saved['saleEndsAt'] ?? ''), CarbonImmutable::now());
            $seen = match (true) {
                ! $selling || $price === null => __('catalog.ed.seen_ask'),
                $onSale && $sale !== null => __('catalog.ed.seen_sale', ['price' => ProductPrice::format($sale)]),
                default => __('catalog.ed.seen_price', ['price' => ProductPrice::format($price)]),
            };
        }

        return [
            'status' => $this->status,
            'chip' => __('catalog.ed.chip_'.$this->status),
            'published' => $published !== null ? __('catalog.ed.pub_first', ['date' => $published]) : __('catalog.ed.pub_never'),
            'updated' => $updated ?? __('catalog.ed.sv_never'),
            'seen' => match (true) {
                $this->status === 'published' && $end !== null && ! $endPast => __('catalog.ed.seen_until', ['seen' => $seen, 'time' => $endShown]),
                $this->status === 'archived' && $endPast => __('catalog.ed.seen_expired', ['time' => $endShown]),
                default => $seen,
            },
            'expHint' => match (true) {
                $end === null || ($endPast && $this->status !== 'archived') => __('catalog.ed.exp_hint'),
                $endPast => __('catalog.ed.exp_hint_done'),
                default => __('catalog.ed.exp_hint_at', ['time' => $endShown]),
            },
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

        $this->slug = app(ChangeProductSlug::class)->fromName($this->name, $this->locale, $this->productId, $this->pickedCategory());

        return $this->slug !== '';
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
            $this->status = $this->publishing ? $this->target : 'draft';
        }

        // A cleared input arrives as '' — store NULL: an '' sku would enter the partial
        // UNIQUE(site_id, sku) index and collide with the next skuless product.
        $this->sku = $this->sku !== null ? SkuCode::normalize($this->sku) : null;
        $this->saleStartsAt ??= '';
        $this->saleEndsAt ??= '';
        $this->sku = $this->sku === '' ? null : $this->sku;
        $this->price = $this->price === '' ? null : $this->price;
        $this->salePrice = $this->salePrice === '' ? null : $this->salePrice;
        $this->stock = $this->stock === '' ? null : $this->stock;

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
            $this->status = $this->target;
        }
        if ($stored !== null && $this->translating()) {
            return $this->persistTranslation($stored, $filled);
        }
        try {
            $this->validate();
            // where the product is filed, held against the stored categories under the row lock
            $filing = $this->filedUnder($stored);
        } catch (ValidationException $e) {
            // A link made for a save that failed must not stay behind: it would pin the old name.
            if ($filled) {
                $this->slug = '';
            }

            throw $e;
        }

        $price = ProductPrice::parse($this->price);
        $sale = ProductPrice::parse($this->salePrice);
        $stock = ProductPrice::parse($this->stock, ProductPrice::STOCK_DIGITS);
        $attributes = [
            'sku' => $this->sku,
            'price' => $price,
            'sale_price' => $sale,
            'stock' => $stock,
        ];
        // a sale's start and end are written together when either changed here (checked against each
        // other above), so a stored instant with seconds is kept as it is when neither did
        $timesChanged = $this->saleStartsAt !== ($this->saved['saleStartsAt'] ?? '') || $this->saleEndsAt !== ($this->saved['saleEndsAt'] ?? '');
        if ($timesChanged) {
            $attributes['sale_starts_at'] = $this->moment($this->saleStartsAt);
            $attributes['sale_ends_at'] = $this->moment($this->saleEndsAt);
        }
        // the fields show what is stored (a draft saving itself puts back what was typed: commit())
        $this->price = $price !== null ? ProductPrice::format($price) : null;
        $this->salePrice = $sale !== null ? ProductPrice::format($sale) : null;
        $this->stock = $stock !== null ? (string) $stock : null;
        // only the labels switched here: one another tab or the list changed meanwhile is not put back
        $flags = [];
        foreach (['featured' => 'featured', 'best' => 'bestseller'] as $flag => $field) {
            if ($this->{$field} !== (($this->saved[$field] ?? '') === '1')) {
                $flags[$flag] = $this->{$field};
            }
        }

        // where the product goes: inside the person's product limits (validated above; held here too)
        $this->authorize('fileUnder', [Product::class, $this->categoryId]);
        if ($this->ulid === null) {
            $this->authorize('create', Product::class);
            $product = Product::query()->create($attributes + ['status' => $this->status]);
            $this->ulid = $product->ulid;
            $this->productId = $product->id;
            $wasPublished = false;
            $mainBefore = null;
        } else {
            $product = $stored;
            $wasPublished = $product->status === self::PRODUCT_LIVE;
            $this->authorize('update', $product);
            // the main category as stored under the row lock (another tab may have moved it): a published
            // product moved elsewhere gives every language's address the new category
            $mainBefore = $wasPublished ? $product->primaryCategory()?->id : null;
            // the product row before its translation, like every other write of a name or a SKU
            ProductSearchWords::lock($product->site_id, $product->id);
            // the labels through the list's own write, saved in the same statement as the other fields
            if ($flags !== []) {
                app(FlagProduct::class)->fill($product, $flags);
            }
            $product->update($this->publishing ? $attributes + ['status' => $this->status] : $attributes);
        }
        // «Gỡ khỏi website lúc» through its own action (the policy, the time still to come, the audit row), before
        // the going-live effects below, which refuse an end time already past
        if (($this->expiresAt ?? '') !== ($this->saved['expiresAt'] ?? '') && ! $this->sharedRefused()) {
            app(ChangeProductExpiry::class)->handle($product, ExpiryTime::parse($this->expiresAt, app(SiteTime::class)->zone()));
        }
        $this->expiresAt = app(SiteTime::class)->forDisplay($product->expires_at)?->format(self::MOMENT) ?? '';

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

        $product->assignCategories($filing);
        if ($product->wasRecentlyCreated && in_array(true, $flags, true)) {
            // a new product's labels through the list's own write, once it is filed (the right to
            // change it is asked about its category); it has none until one is switched on
            app(FlagProduct::class)->handle($product, $flags);
        }
        app(ProductGallery::class)->sync($product, $this->imageMediaIds);
        // once every write went through: what this tab did not write shows as stored, so the form never
        // shows a value the save did not keep
        $this->featured = (bool) $product->is_featured;
        $this->bestseller = (bool) $product->is_bestseller;
        if (! $timesChanged) {
            $time = app(SiteTime::class);
            $this->saleStartsAt = $time->forDisplay($product->sale_starts_at)?->format(self::MOMENT) ?? '';
            $this->saleEndsAt = $time->forDisplay($product->sale_ends_at)?->format(self::MOMENT) ?? '';
        }

        if ($product->status === 'published') {
            // Drafts and archived products keep whatever address they had — the storefront renders
            // only published ones, so their route rows are harmless and keep old links stable. A product
            // going live, or moving to another main category, gives every language it is written in its
            // address (the other versions' paths carry the category too); otherwise this language's.
            // This language first, so a link taken in it is said on its own field.
            app(ChangeProductStatus::class)->publishEffects($product, $this->locale, $this->slug, goingLive: ! $wasPublished);
            if (! $wasPublished || $mainBefore !== ($filing[0] ?? null)) {
                $this->syncOtherLanguages($product);
            }
        }
        // the list shows what was stored (as on opening): a category dropped above leaves it
        $this->categoryIds = $filing;

        return $product;
    }

    /**
     * Every other language's address of a published product, after it went live or moved to another
     * main category (their paths carry the category too). A version whose address another page holds
     * stops the save with the language named, since its link is not on this form.
     *
     * @throws ValidationException
     */
    private function syncOtherLanguages(Product $product): void
    {
        $sync = app(RouteSync::class);
        $category = $product->primaryCategory();
        $others = $product->translations()->where('locale', '!=', $this->locale)->orderBy('locale')->get(['locale', 'slug']);
        foreach ($others as $translation) {
            try {
                $sync->syncProduct($product->id, $category, $translation->locale, $translation->slug);
            } catch (RouteConflictException) {
                throw ValidationException::withMessages(['slug' => (string) __('catalog.ed.slug_taken_in', ['lang' => LangBar::name($translation->locale)])]);
            }
        }
    }

    /**
     * Save the open language's version of a stored product (another language than the website's
     * default), under the row lock persist() took: its words only, created by its first save. The
     * product's own fields (pictures, price, stock, sale, SKU, labels, categories, status) are never
     * written here, whatever the request holds: they show what is stored. A published product's address
     * in this language is written with it.
     *
     * @throws ValidationException|RouteConflictException
     */
    private function persistTranslation(Product $product, bool $filled): Product
    {
        try {
            $this->validate(array_intersect_key($this->rules(), array_flip(['name', 'slug', 'description', 'summary', 'seoTitle', 'seoDescription'])));
        } catch (ValidationException $e) {
            if ($filled) {
                $this->slug = '';
            }

            throw $e;
        }
        $this->authorize('update', $product);
        // the product row before its translation, like every other write of a name
        ProductSearchWords::lock($product->site_id, $product->id);

        $summary = $this->keepsStoredSummary() ? $this->summaryStored : $this->summary;
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
        if ($product->status === self::PRODUCT_LIVE) {
            app(RouteSync::class)->syncProduct($product->id, $product->primaryCategory(), $this->locale, $this->slug);
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
     * The categories list (the board's «Chuyên mục»): every product category in menu order, parents
     * before children (indented two levels at most), with the names above it for a search result,
     * whether the product is under it and whether it is the main one. A member limited to some product
     * categories is offered only those inside the limits (a category the product is under that they
     * cannot see stays as it is). Keys only: no id reaches the page.
     *
     * @return list<array{ulid: string, label: string, depth: int, path: string, on: bool, main: bool}>
     */
    private function categoryRows(): array
    {
        $tree = CategoryTree::products(app(CurrentSite::class)->id(), $this->locale);
        $user = auth()->user();
        $within = $user instanceof User ? app(CategoryScopes::class)->allowedTaxonomyIds($user, CategoryScopes::PRODUCTS) : null;
        $inside = $within === null ? null : array_flip($within);
        $main = $this->categoryIds[0] ?? null;
        $rows = [];
        foreach ($tree->choices() as $choice) {
            $id = $tree->idOf($choice['ulid']);
            if ($id === null || ($inside !== null && ! isset($inside[$id]))) {
                continue;
            }
            $rows[] = ['ulid' => $choice['ulid'], 'label' => $choice['label'], 'depth' => $choice['depth'], 'path' => $choice['path'],
                'on' => in_array($id, $this->categoryIds, true), 'main' => $id === $main];
        }
        $this->rememberCategoryName($main !== null ? $tree->label($main) : null);

        return $rows;
    }

    /**
     * Keep the main category's name (from the tree just read, no query of its own): when the category
     * is gone from it, the name learnt for that id is kept.
     */
    private function rememberCategoryName(?string $name): void
    {
        if ($this->categoryId !== null && $name !== null) {
            $this->categoryShown = ['id' => $this->categoryId, 'name' => $name];

            return;
        }
        if (($this->categoryShown['id'] ?? null) !== $this->categoryId) {
            $this->categoryShown = null;
        }
    }

    /**
     * What the price card says beside its fields, from what is typed now: what visitors see for the
     * price, and whether the sale applies at this moment.
     *
     * @return array{shown: string|null, keep: string, sale: string}
     */
    private function priceCard(): array
    {
        $price = ProductPrice::readable($this->price) ? ProductPrice::parse($this->price) : false;
        $shown = match (true) {
            $price === null => Cast::string(__('catalog.price_ask')),
            is_int($price) => Cast::string(__('catalog.ed.amount', ['amount' => ProductPrice::format($price)])),
            default => null,
        };

        $sale = ProductPrice::readable($this->salePrice) ? ProductPrice::parse($this->salePrice) : null;
        $amount = is_int($price) ? $price : null;
        $now = CarbonImmutable::now();
        $words = match (true) {
            $amount === null || $sale === null || $sale >= $amount => __('catalog.ed.sale_open_end'),
            ProductPrice::saleApplies($amount, $sale, $this->moment($this->saleStartsAt), $this->moment($this->saleEndsAt), $now) => __('catalog.ed.sale_now', ['price' => ProductPrice::format($sale)]),
            default => __('catalog.ed.sale_off_window'),
        };

        return [
            'shown' => $shown,
            'keep' => is_int($price) ? Cast::string(__('catalog.ed.fold_keep_price', ['price' => $shown])) : Cast::string(__('catalog.ed.fold_keep')),
            'sale' => Cast::string(__('catalog.ed.sale_zone', ['zone' => $this->clock])).' '.Cast::string($words),
        ];
    }

    /** A sale's start or end typed in the site's wall clock, as the UTC instant stored; null when blank or unreadable. */
    private function moment(?string $local): ?CarbonImmutable
    {
        if ($local === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local) !== 1) {
            return null;
        }
        $read = CarbonImmutable::createFromFormat('!'.self::MOMENT, $local, app(SiteTime::class)->zone());
        if (! $read instanceof CarbonImmutable || $read->format(self::MOMENT) !== $local) {
            return null; // 2026-02-30 and the like
        }

        return $read->utc();
    }

    /**
     * An amount is whole đồng in digits, grouped the Vietnamese way or not (ProductPrice).
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function amountReads(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ProductPrice::readable($value)) {
            return;
        }
        $fail(ProductPrice::wellFormed($value) ? __('catalog.ed.amount_too_big') : __('catalog.ed.amount_invalid'));
    }

    /**
     * A sale price needs a price, and is below it; the message gives one for example (about 10% off,
     * rounded to the thousand). A price that does not read says so on its own field.
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function saleIsBelowPrice(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ! ProductPrice::readable($value)) {
            return;
        }
        $sale = ProductPrice::parse($value);
        if ($sale === null) {
            return;
        }
        if (! ProductPrice::readable($this->price)) {
            return;
        }
        $price = ProductPrice::parse($this->price);
        if ($price === null) {
            $fail(__('catalog.ed.sale_needs_price'));

            return;
        }
        if ($sale >= $price) {
            if ($price === 0) {
                $fail(__('catalog.ed.sale_not_below_free'));

                return;
            }
            // about 10% off, in whole thousands (the board's example); a small price gets 10% off as it is
            $example = intdiv($price * 9 + 5000, 10000) * 1000;
            if ($example <= 0 || $example >= $price) {
                $example = max(intdiv($price * 9, 10), 1) < $price ? max(intdiv($price * 9, 10), 1) : $price - 1;
            }
            $fail(__('catalog.ed.sale_not_below', ['price' => ProductPrice::format($price), 'example' => ProductPrice::format($example)]));
        }
    }

    /**
     * @param  \Closure(string): mixed  $fail
     */
    private function momentReads(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && $value !== '' && $this->moment($value) === null) {
            $fail(__('catalog.ed.sale_when_invalid'));
        }
    }

    /**
     * The end of a sale comes after its start (the message on the end, naming the start).
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function saleEndsAfterStart(string $attribute, mixed $value, \Closure $fail): void
    {
        $end = is_string($value) ? $this->moment($value) : null;
        $start = $this->moment($this->saleStartsAt);
        if ($end !== null && $start !== null && $end <= $start) {
            $fail(__('catalog.ed.sale_end_before', ['start' => app(SiteTime::class)->forDisplay($start)?->format('d/m/Y H:i')]));
        }
    }

    /**
     * @param  \Closure(string): mixed  $fail
     */
    private function stockReads(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && ! ProductPrice::readable($value, ProductPrice::STOCK_DIGITS)) {
            $fail(__('catalog.ed.stock_invalid'));
        }
    }

    /**
     * No other product of this site holds the code, the Trash included (the unique index covers both).
     * The message names that product and says whether it is in the Trash — for a member limited to some
     * product categories only when it is inside them; otherwise it says the code is taken.
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function skuIsFree(string $attribute, mixed $value, \Closure $fail): void
    {
        // as the save stores it (persist() normalises the code first), compared exactly so the
        // (site_id, sku) index serves the lookup
        $value = is_string($value) ? SkuCode::normalize($value) : '';
        if ($value === '') {
            return;
        }
        $other = Product::withTrashed()
            ->where('sku', $value)
            ->when($this->productId !== null, fn ($query) => $query->whereKeyNot($this->productId))
            ->first(['id', 'site_id', 'deleted_at']);
        if (! $other instanceof Product) {
            return;
        }

        $user = auth()->user();
        $scopes = app(CategoryScopes::class);
        if ($user instanceof User && $scopes->isLimited($user, CategoryScopes::PRODUCTS)
            && ! $scopes->coversId($user, $other->primaryCategory()?->id, CategoryScopes::PRODUCTS)) {
            $fail(__('catalog.sku_taken'));

            return;
        }
        $name = $other->translations()->where('locale', $this->locale)->value('name');
        $name = is_string($name) && $name !== '' ? $name : __('catalog.untitled');
        $fail(__($other->trashed() ? 'catalog.ed.sku_taken_trash' : 'catalog.ed.sku_taken_named', ['sku' => $value, 'name' => $name]));
    }

    /**
     * The picked category is a product category of this site (RLS). One deleted meanwhile (another tab,
     * another member) is named, when this page drew it, so the member knows which one to replace.
     *
     * @param  \Closure(string): mixed  $fail
     */
    private function categoryExists(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }
        $id = (int) $value;
        if (Taxonomy::query()->whereKey($id)->where('vocabulary', 'product_cat')->exists()) {
            return;
        }
        $fail(($this->categoryShown['id'] ?? null) === $id
            ? __('catalog.ed.category_gone', ['name' => $this->categoryShown['name']])
            : __('catalog.category_invalid'));
    }

    private function resolveOrFail(string $ulid): Product
    {
        return Product::query()->where('ulid', $ulid)->firstOrFail();
    }
}
