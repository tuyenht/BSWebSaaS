<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Categories;

use App\Modules\Admin\Livewire\Concerns\EditsBlockList;
use App\Modules\Admin\Livewire\Concerns\EditsButtonActions;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\Categories\CategoryBands;
use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\Taxonomies\SetCategoryLayoutBlocks;
use App\Modules\Cms\Exceptions\LayoutBlocksRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Support\LayoutSlotBlocks;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * One band of a category page in «Trình dựng trang» (design 10 «Sửa trong Trình dựng trang»,
 * opened from the category drawer): the blocks above or below the category's list
 * (CategoryBands::SLOTS), one layout per language, picked by the chips. It opens on what the page
 * shows in that language now — the category's own blocks, else the site's default — so editing a
 * band that follows the site starts from the site's blocks; saving makes them the category's own
 * (the other languages keep showing what they showed). The block list, looks, pictures and button
 * links are the content editor's own (EditsBlockList, EditsButtonActions).
 *
 * Saving goes through SetCategoryLayoutBlocks (TaxonomyPolicy, the slot and block checks, the
 * library's "in use" rows) under the tree lock, with the band as the screen read it: a change made
 * meanwhile elsewhere is refused, never overwritten. Gated on every request: taxonomies.manage
 * (boot), then per action TaxonomyPolicy::manage and ::update on this category, inside a section
 * the person may see; a support session only looks.
 */
#[Layout('admin::layouts.app')]
final class BandEditor extends Component
{
    use EditsBlockList;
    use EditsButtonActions;
    use WithMediaLibraryPicker;

    /** The categories screen shows this once it is open again («Lưu & quay lại»). */
    public const string FLASH = 'categories.band_flash';

    #[Locked]
    public string $ulid = '';

    #[Locked]
    public string $slotName = '';

    /** The language being edited (the chips are links: the screen opens again in that language). */
    #[Locked]
    #[Url(as: 'locale', except: '')]
    public string $locale = '';

    /** @var list<array<string, mixed>> this language's layout, in the editor's form */
    public array $blocks = [];

    /** A hash of the band as stored when the screen read it, handed back on save. */
    #[Locked]
    public string $version = '';

    /** A hash of the layout as last read or saved: anything else is an unsaved change. */
    #[Locked]
    public string $savedHash = '';

    #[Locked]
    public string $categoryName = '';

    /** The section the category belongs to, for «Quay lại». */
    #[Locked]
    public string $sectionKey = '';

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-check on every request.
        abort_unless(Gate::allows('taxonomies.manage'), 403);
    }

    /**
     * After the screen opened, a category deleted elsewhere meanwhile gets words in a toast instead
     * of an error page, and the layout on screen stays. Opening the screen still answers 404.
     */
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($this->version !== '' && $e instanceof ModelNotFoundException) {
            $this->toast(__('categories.band_gone'), 'error');
            $stopPropagation();
        }
    }

    public function mount(string $ulid, string $slot): void
    {
        abort_unless(in_array($slot, CategoryBands::SLOTS, true), 404);
        $this->ulid = $ulid;
        $this->slotName = $slot;
        $category = $this->category();
        Gate::authorize('update', $category);
        // the language is fixed as the screen opens: one turned off meanwhile never takes this layout
        $locales = app(SiteLocale::class);
        if (! in_array($this->locale, $locales->enabled(), true)) {
            $this->locale = $locales->default();
        }
        $this->load($category);
    }

    public function addBlock(string $type): void
    {
        $this->authorizeEditing();
        if (! in_array($type, $this->types(), true)) {
            $this->addError('blocks', __('entries.block_unsupported', ['type' => $type]));

            return;
        }
        $this->resetErrorBag('blocks');
        $this->blocks[] = ['type' => $type, 'props' => $this->defaultProps($type)];
        $this->openBlock = array_key_last($this->blocks);
    }

    /** «Xóa khối» on a row (asked first in the browser): the block leaves this language's layout, unsaved. */
    public function removeBlock(int $index): void
    {
        $this->authorizeEditing();
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }
        $blocks = $this->blocks;
        array_splice($blocks, $index, 1);
        $this->blocks = $blocks;
        $this->lookSplit = [];
        if ($this->openBlock !== null) {
            $this->openBlock = match (true) {
                $this->openBlock === $index => null,
                $this->openBlock > $index => $this->openBlock - 1,
                default => $this->openBlock,
            };
        }
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.block_removed')));
    }

    /** A list sent whole keeps positions as its keys; a typed link anchor is settled as everywhere. */
    public function updatedBlocks(mixed $value, ?string $key = null): void
    {
        $this->authorizeEditing();
        /** @var mixed $sent the client sends any shape */
        $sent = $this->blocks;
        if ($key === null && is_array($sent) && ! array_is_list($sent)) {
            /** @var list<array<string, mixed>> $list */
            $list = array_values($sent);
            $this->blocks = $list;
        }
        $this->settleAnchor($value, $key);
    }

    /** Save this language's layout of the band; true when it was saved. Also the unsaved question's «Lưu & thoát». */
    public function save(): bool
    {
        $this->authorizeEditing();
        $this->resetErrorBag('blocks');
        if (app(SupportSession::class)->active()) {
            $this->toast(__('categories.reason_support_session'), 'error');

            return false;
        }
        try {
            $stored = $this->layoutForStorage();
            $this->version = DB::transaction(function () use ($stored): string {
                TaxonomyTree::lock(app(CurrentSite::class)->id());
                $category = $this->category();
                if ($this->versionOf($category) !== $this->version) {
                    throw new TaxonomyChangeRejectedException('version_stale');
                }
                $value = $this->startingValue($category);
                $value[$this->locale] = ['blocks' => $stored];
                $saved = app(SetCategoryLayoutBlocks::class)->handle($category, [$this->slotName => $value]);

                return $this->versionOf($saved);
            });
        } catch (ValidationException $e) {
            $this->addError('blocks', Cast::string(collect($e->errors())->flatten()->first()));
            $this->toast(__('categories.band_err_body'), 'error');

            return false;
        } catch (TaxonomyChangeRejectedException $e) {
            $why = $e->getPrevious() instanceof LayoutBlocksRejectedException ? $e->getPrevious()->getMessage() : '';
            if ($why !== '') {
                $this->addError('blocks', $why);
            }
            $this->toast(__(match ($e->reason) {
                'version_stale' => 'categories.band_err_stale',
                'locale_invalid' => 'categories.band_err_lang_off',
                'blocks_invalid', 'blocks_too_many' => 'categories.band_err_body',
                'outside_category_limits' => 'categories.reason_outside_category_limits',
                default => 'categories.band_err_generic',
            }), 'error');

            return false;
        } catch (AuthorizationException) {
            $this->toast(__('categories.band_err_cannot'), 'error');

            return false;
        }
        $this->savedHash = $this->hash();
        $this->toast(__('categories.band_saved', ['lang' => Locales::label($this->locale)]));

        return true;
    }

    /** «Lưu & quay lại»: save, then the categories screen opens this category's drawer again and says so. */
    public function saveAndReturn(): void
    {
        if (! $this->save()) {
            return;
        }
        session()->flash(self::FLASH, __('categories.band_saved_back', ['band' => $this->bandLabel(), 'name' => $this->categoryName]));
        $this->redirect($this->backUrl(), navigate: true);
    }

    /** The unsaved question's «Thoát không lưu»: this language's layout as last saved. */
    public function resetChanges(): void
    {
        $this->authorizeEditing();
        $this->load($this->category());
    }

    public function closeToast(int $seq = 0): void
    {
        $this->authorizeEditing();
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toastMsg = '';
        }
    }

    public function render(): View
    {
        $schemas = $this->schemas();
        $buttonPanel = $this->buttonPanel($schemas);
        $siteLocales = app(SiteLocale::class);
        $title = __('categories.band_editor_title', ['band' => $this->bandLabel(), 'name' => $this->categoryName]);

        return view('admin::livewire.categories.band-editor', [
            'title' => $title,
            'band' => $this->bandLabel(),
            'backUrl' => $this->backUrl(),
            'changes' => $this->hash() !== $this->savedHash ? 1 : 0,
            'locales' => count($siteLocales->enabled()) > 1 ? $siteLocales->enabled() : [],
            'current' => $this->locale,
            'localeLabel' => Locales::label($this->locale),
            'unit' => $this->bandLabel(),
            'buttons' => $buttonPanel['buttons'],
            'buttonPanel' => $buttonPanel,
            'availableTypes' => $this->types(),
            'kinds' => $this->blockKinds(),
            'schemas' => $schemas,
            'urls' => app(MediaUrlResolver::class),
            'blockImageUrls' => $this->resolveBlockImageUrls($schemas),
            'goneProducts' => $this->goneProductKeys(),
            'looks' => $this->looks(),
            'schemeColours' => $this->schemeColours(),
            'support' => app(SupportSession::class)->active(),
        ])->title($title);
    }

    /** The block image field's picture, picked in the shared «Chọn ảnh» dialog. */
    protected function pickedSingleImage(Media $media): void
    {
        $this->placeBlockImage($media);
    }

    private function authorizeEditing(): void
    {
        Gate::authorize('manage', Taxonomy::class);
        Gate::authorize('update', $this->category());
    }

    /**
     * This website's category (RLS and the site scope: another website's key is not found), of a
     * vocabulary with a list page, in a section the person may see (else 403: the Chuyên mục
     * screen shows that section to no one else either).
     */
    private function category(): Taxonomy
    {
        $category = Taxonomy::query()->where('ulid', $this->ulid)->whereIn('vocabulary', [CategorySections::POST_VOCABULARY, CategorySections::PRODUCT_VOCABULARY])->firstOrFail();
        $section = $this->sectionOf($category);
        if ($section === null) {
            throw new ModelNotFoundException;
        }
        abort_unless(Gate::allows($section['prefix'].'.view'), 403);
        $this->sectionKey = $section['key'];

        return $category;
    }

    /**
     * The section the category lies in: the product catalogue, or the post section whose root is the
     * category or one above it; null when none (a post category outside every section).
     *
     * @return array{key: string, label: string, vocabulary: string, root: int|null, prefix: string}|null
     */
    private function sectionOf(Taxonomy $category): ?array
    {
        $sections = app(CategorySections::class);
        if ($category->vocabulary === CategorySections::PRODUCT_VOCABULARY) {
            return $sections->find(CategorySections::CATALOG);
        }
        $roots = [];
        foreach ($sections->postSections() as $section) {
            $roots[$section['root']] = $section;
        }
        foreach ([$category->id, ...array_reverse(TaxonomyTree::ancestorsOf($category->id))] as $at) {
            if (isset($roots[$at])) {
                return $roots[$at];
            }
        }

        return null;
    }

    /**
     * What the band holds before this language is written in: its own blocks when it has them;
     * when it followed the site (or was hidden), what every language showed then — the site's
     * blocks of that language (nothing for a hidden band) — so saving one language changes no other.
     *
     * @return array<string, array{blocks: list<mixed>}>
     */
    private function startingValue(Taxonomy $category): array
    {
        $stored = $this->stored($category);
        if (is_array($stored)) {
            /** @var array<string, array{blocks: list<mixed>}> $stored */
            return $stored;
        }

        $value = [];
        $resolver = app(LayoutSlotBlocks::class);
        foreach (app(SiteLocale::class)->enabled() as $locale) {
            $value[$locale] = ['blocks' => $stored === LayoutSlots::NONE ? [] : $resolver->blocks($this->slotName, $locale)];
        }

        return $value;
    }

    /** The band's stored value: a block list per language, "none", or null (the site's default). */
    private function stored(Taxonomy $category): mixed
    {
        return Cast::arr(Cast::arr($category->meta)[LayoutSlots::META_KEY] ?? null)[$this->slotName] ?? null;
    }

    private function versionOf(Taxonomy $category): string
    {
        return hash('xxh128', (string) json_encode($this->stored($category)));
    }

    /** This language's layout as the page shows it now (nothing for a hidden band), with the version it was read at. */
    private function load(Taxonomy $category): void
    {
        $shown = $this->stored($category) === LayoutSlots::NONE ? [] : app(LayoutSlotBlocks::class)->blocks($this->slotName, $this->locale, $category);
        $this->blocks = $this->layoutForEditor($this->normalizeBlocks($shown));
        $this->openBlock = null;
        $this->lookSplit = [];
        $main = app(SiteLocale::class)->default();
        $names = TaxonomyTranslation::query()->where('taxonomy_id', $category->id)->pluck('name', 'locale')->all();
        $this->categoryName = Cast::string($names[$main] ?? (reset($names) ?: ''));
        $this->version = $this->versionOf($category);
        $this->savedHash = $this->hash();
        $this->resetErrorBag();
    }

    /**
     * The block types this band takes: those listed for pages that may sit in this slot.
     *
     * @return list<string>
     */
    private function types(): array
    {
        return array_values(array_filter(app(ComponentRegistry::class)->listedTypes(), fn (string $type): bool => LayoutSlots::accepts($this->slotName, $type)));
    }

    private function bandLabel(): string
    {
        return Cast::string(__($this->slotName === 'before_list' ? 'categories.ui_band_top' : 'categories.ui_band_bot'));
    }

    private function backUrl(): string
    {
        return route('admin.taxonomies.index', ['section' => $this->sectionKey, 'open' => $this->ulid]);
    }

    private function hash(): string
    {
        return hash('xxh128', (string) json_encode($this->blocks));
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
