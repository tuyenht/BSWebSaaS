<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Blocks;

use App\Modules\Admin\Livewire\Blocks\Concerns\GatesBlockScreens;
use App\Modules\Admin\Livewire\Concerns\EditsBlockList;
use App\Modules\Admin\Livewire\Concerns\EditsButtonActions;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\ContentBlocks\SaveContentBlockBody;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The content of one reusable block, in «Trình dựng» (board 27 opened from the block library): a
 * band says which block is being edited and that saving changes every place that uses it, with
 * «Quay lại» (the shared unsaved-changes question first when something is not saved) and «Lưu &
 * quay lại». The block list, its looks, pictures and button links are the content editor's own
 * (EditsBlockList, EditsButtonActions); each language of the website keeps its own layout, picked
 * by the chips. Saving goes through SaveContentBlockBody with the version the screen read, so a
 * change made meanwhile elsewhere is never overwritten. Gated on every request: the library's gate
 * (GatesBlockScreens), then ContentBlockPolicy::update on this block.
 */
#[Layout('admin::layouts.app')]
final class Editor extends Component
{
    use EditsBlockList;
    use EditsButtonActions;
    use GatesBlockScreens {
        GatesBlockScreens::boot as private bootLibrary;
    }
    use WithMediaLibraryPicker;

    #[Locked]
    public string $ulid = '';

    /** The language being edited (the chips are links: the screen opens again in that language). */
    #[Locked]
    #[Url(as: 'locale', except: '')]
    public string $locale = '';

    /** @var list<array<string, mixed>> this language's layout, in the editor's form */
    public array $blocks = [];

    /** The version the screen read (ContentBlockVersion), handed back on save. */
    #[Locked]
    public string $version = '';

    /** A hash of the layout as last read or saved: anything else is an unsaved change. */
    #[Locked]
    public string $savedHash = '';

    #[Locked]
    public string $blockName = '';

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /**
     * The library's gate on every request; this block's own rights are asked by each action
     * (authorizeEditing), so a block deleted meanwhile, or rights lost, reach exception() below.
     */
    public function boot(): void
    {
        $this->bootLibrary();
    }

    /**
     * After the screen opened (it has read a version), a block deleted elsewhere meanwhile gets words
     * in a toast instead of an error page, and the layout on screen stays. Opening the screen still
     * answers 404. A refused gate is never caught here: boot's refusal must stop the request.
     */
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($this->version !== '' && $e instanceof ModelNotFoundException) {
            $this->toast(__('blocks.editor_gone'), 'error');
            $stopPropagation();
        }
    }

    public function mount(string $ulid): void
    {
        $this->ulid = $ulid;
        $block = $this->block();
        Gate::authorize('update', $block);
        // the language is fixed as the screen opens: a language turned off, or another default chosen,
        // meanwhile never sends this layout into a different language
        $locales = app(SiteLocale::class);
        if (! in_array($this->locale, $locales->enabled(), true)) {
            $this->locale = $locales->default();
        }
        $this->load($block);
        // the library's «Tạo & mở Trình dựng» says it was created as this screen opens
        $flash = session(Library::FLASH);
        if (is_string($flash) && $flash !== '') {
            $this->toast($flash);
        }
    }

    public function addBlock(string $type): void
    {
        $this->authorizeEditing();
        if (! in_array($type, app(ComponentRegistry::class)->listedTypes(), true)) {
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

    /** Save this language's layout; true when it was saved. Also the unsaved question's «Lưu & thoát». */
    public function save(): bool
    {
        $this->authorizeEditing();
        $this->resetErrorBag('blocks');
        try {
            $stored = $this->layoutForStorage();
            $this->version = app(SaveContentBlockBody::class)->handle($this->actingUser(), $this->block(), $this->editingLocale(), $stored, $this->version);
        } catch (ValidationException $e) {
            $this->addError('blocks', Cast::string(collect($e->errors())->flatten()->first()));
            $this->toast(__('blocks.err_body'), 'error');

            return false;
        } catch (ContentBlockChangeRejectedException $e) {
            if ($e->reason === 'body_invalid') {
                $this->addError('blocks', $e->getMessage() !== '' ? $e->getMessage() : __('blocks.err_body'));
            }
            $this->toast(__(match ($e->reason) {
                'version_stale' => 'blocks.err_stale_body',
                'locale_invalid' => 'blocks.err_lang_off',
                'body_invalid' => 'blocks.err_body',
                'block_missing' => 'blocks.toast_gone',
                default => 'blocks.err_generic',
            }), 'error');

            return false;
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');

            return false;
        }
        $this->savedHash = $this->hash();
        $this->toast(__('blocks.toast_body_saved', ['lang' => Locales::label($this->editingLocale())]));

        return true;
    }

    /** «Lưu & quay lại»: save, then the library says it was saved. */
    public function saveAndReturn(): void
    {
        if (! $this->save()) {
            return;
        }
        session()->flash(Library::FLASH, __('blocks.toast_saved_back', ['name' => $this->blockName]));
        $this->redirectRoute('admin.blocks.index', navigate: true);
    }

    /** The unsaved question's «Thoát không lưu»: this language's layout as last saved. */
    public function resetChanges(): void
    {
        $this->authorizeEditing();
        $this->load($this->block());
    }

    public function closeToast(int $seq = 0): void
    {
        $this->authorizeLibrary();
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toastMsg = '';
        }
    }

    public function render(): View
    {
        $urls = app(MediaUrlResolver::class);
        $schemas = $this->schemas();
        $buttonPanel = $this->buttonPanel($schemas);
        $siteLocales = app(SiteLocale::class);
        $locale = $this->editingLocale();
        $title = __('blocks.editor_title', ['name' => $this->blockName]);

        return view('admin::livewire.blocks.editor', [
            'title' => $title,
            'changes' => $this->hash() !== $this->savedHash ? 1 : 0,
            'locales' => count($siteLocales->enabled()) > 1 ? $siteLocales->enabled() : [],
            'current' => $locale,
            'localeLabel' => Locales::label($locale),
            'unit' => __('blocks.unit'),
            'buttons' => $buttonPanel['buttons'],
            'buttonPanel' => $buttonPanel,
            'availableTypes' => app(ComponentRegistry::class)->listedTypes(),
            'kinds' => $this->blockKinds(),
            'schemas' => $schemas,
            'urls' => $urls,
            'blockImageUrls' => $this->resolveBlockImageUrls($schemas),
            'goneProducts' => $this->goneProductKeys(),
            'looks' => $this->looks(),
            'schemeColours' => $this->schemeColours(),
        ])->title($title);
    }

    /** The block image field's picture, picked in the shared «Chọn ảnh» dialog. */
    protected function pickedSingleImage(Media $media): void
    {
        $this->placeBlockImage($media);
    }

    private function authorizeEditing(): void
    {
        $this->authorizeLibrary();
        Gate::authorize('update', $this->block());
    }

    /** This website's block (RLS and the site scope): another website's ulid is not found. */
    private function block(): ContentBlock
    {
        return ContentBlock::query()->where('ulid', $this->ulid)->firstOrFail();
    }

    /** The language being edited, fixed when the screen opened (saving refuses one turned off since). */
    private function editingLocale(): string
    {
        return $this->locale;
    }

    /** This language's layout as saved (none yet: an empty layout), with the version it was read at. */
    private function load(ContentBlock $block): void
    {
        $body = ContentBlockTranslation::query()
            ->where('content_block_id', $block->id)
            ->where('locale', $this->editingLocale())
            ->value('body');
        $saved = is_array($body) ? ($body['blocks'] ?? []) : [];
        $this->blocks = $this->layoutForEditor($this->normalizeBlocks($saved));
        $this->openBlock = null;
        $this->lookSplit = [];
        $this->blockName = $block->name;
        $this->version = ContentBlockVersion::current($block);
        $this->savedHash = $this->hash();
        $this->resetErrorBag();
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
