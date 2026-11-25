<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Blocks;

use App\Modules\Admin\Livewire\Blocks\Concerns\GatesBlockScreens;
use App\Modules\Cms\Actions\ContentBlocks\CreateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DeleteContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DuplicateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\PublishContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\UpdateContentBlock;
use App\Modules\Cms\ContentBlocks\ContentBlockRules;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Queries\ContentBlockList;
use App\Modules\Cms\Queries\ContentBlockUsages;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Khối dùng chung» (board 28): the website's reusable blocks, newest change first, by
 * status (Tất cả / Đã xuất bản / Nháp) and region, searched by name (Vietnamese marks ignored) or
 * reference code. A row names its region, its reference code (copied in the browser), the
 * languages it has content in (each opens the block editor in that language), how many places use
 * it («Nơi đang dùng khối» lists them) and its status. «Thêm khối» / «Sửa» ask the name and the
 * region; a new block opens in the block editor. «Nhân bản» copies a block with its languages;
 * «Xóa» asks first, saying what uses it, and deletes for good (the board has no Trash). The row's «⋯»
 * starts with «Xuất bản» (a draft; acts at once) or «Đưa về nháp» (a published block; used somewhere:
 * asks first, in the delete question's dialog).
 *
 * The list is read in keyset pages (ContentBlockList::page) and «Xem thêm» follows the cursor one
 * page further; a website keeps at most `cms.blocks.max_per_site` blocks. Gated on every request
 * (GatesBlockScreens): the plan's `blocks` module, then ContentBlockPolicy::create (`blocks.manage`,
 * not limited to some categories); every write asks the policy again inside its action.
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use GatesBlockScreens;

    /** @var list<string> the status groups */
    public const array TABS = ['all', 'published', 'draft'];

    /** The screens a block library opened from can be returned to («Quay lại …»), by key. */
    public const array RETURNS = ['menus' => 'admin.menus.index'];

    /** The session key a block screen leaves its toast under for the next one. */
    public const string FLASH = 'blocks.toast';

    #[Url(as: 'status', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'region', except: 'all')]
    public string $region = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Where the member came from (a key of RETURNS), for the «Quay lại …» band. */
    #[Url(as: 'return', except: '')]
    public string $back = '';

    /** How many keyset pages are shown («Xem thêm» adds one). */
    #[Locked]
    public int $pages = 1;

    /** The «Tạo / Sửa khối dùng chung» dialog is open. */
    public bool $formOpen = false;

    /** The block the dialog edits (null: a new one) and the version it read. */
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public string $editingVersion = '';

    public string $name = '';

    public string $formRegion = ContentBlock::DEFAULT_REGION;

    /** «Nơi đang dùng khối» is open (entangled with the dialog). */
    public bool $usageOpen = false;

    /** @var array{name: string, total: int, places: list<array{label: string, href: string|null}>}|null */
    #[Locked]
    public ?array $usage = null;

    /** The «Xóa khối dùng chung?» question is open. */
    public bool $confirmOpen = false;

    /** @var array{ulid: string, name: string, places: int, menu: bool}|null the block it asks about, read when it opened */
    #[Locked]
    public ?array $deleting = null;

    /** What the open question is about: 'delete' («Xóa khối dùng chung?») or 'draft' («Đưa khối về nháp?»). */
    #[Locked]
    public string $asking = 'delete';

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** A message the previous screen left for this one (the block editor's «Lưu & quay lại»). */
    public function mount(): void
    {
        $flash = session(self::FLASH);
        if (is_string($flash) && $flash !== '') {
            $this->toast($flash);
        }
    }

    public function updatedTab(): void
    {
        $this->pages = 1;
    }

    public function updatedRegion(): void
    {
        $this->pages = 1;
    }

    public function updatedSearch(): void
    {
        $this->pages = 1;
    }

    /** «Xem thêm»: one keyset page more, up to the most a website keeps. */
    public function loadMore(): void
    {
        $this->authorizeLibrary();
        $this->pages = min($this->pages + 1, max(1, (int) ceil(ContentBlockRules::max() / ContentBlockList::PAGE_SIZE)));
    }

    public function clearFilters(): void
    {
        $this->authorizeLibrary();
        $this->tab = 'all';
        $this->region = 'all';
        $this->search = '';
        $this->pages = 1;
    }

    public function clearSearch(): void
    {
        $this->authorizeLibrary();
        $this->search = '';
        $this->pages = 1;
    }

    /** «Thêm khối»: a new block starts in the region the list is filtered to (else «Chèn trong trang»). */
    public function openAdd(): void
    {
        $this->authorizeLibrary();
        $this->resetErrorBag();
        $this->editing = null;
        $this->editingVersion = '';
        $this->name = '';
        $this->formRegion = in_array($this->region, ContentBlock::REGIONS, true) ? $this->region : ContentBlock::DEFAULT_REGION;
        $this->formOpen = true;
    }

    /** «Sửa» on a row: the block's name and region in the dialog, with «Sửa nội dung (Trình dựng)». */
    public function openEdit(string $ulid): void
    {
        $block = $this->writable($ulid, 'update');
        if ($block === null) {
            return;
        }
        $this->resetErrorBag();
        $this->editing = $block->ulid;
        $this->editingVersion = ContentBlockVersion::current($block);
        $this->name = $block->name;
        $this->formRegion = $block->region;
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->authorizeLibrary();
        $this->formOpen = false;
        $this->editing = null;
        $this->editingVersion = '';
        $this->resetErrorBag();
    }

    /**
     * The dialog's main button: «Tạo & mở Trình dựng» creates a draft block and opens it in the
     * block editor; «Lưu» renames it or moves it to another region.
     */
    public function saveBlock(): void
    {
        $this->authorizeLibrary();
        $name = trim($this->name);
        if ($name === '') {
            $this->addError('name', __('blocks.err_name_required'));
            $this->toast(__('blocks.err_name_required'), 'error');

            return;
        }
        $this->validate([
            'name' => ['required', 'string', 'max:'.ContentBlock::NAME_MAX],
            'formRegion' => ['required', 'string', 'in:'.implode(',', ContentBlock::REGIONS)],
        ], [], ['name' => __('blocks.f_name'), 'formRegion' => __('blocks.f_region')]);

        try {
            if ($this->editing === null) {
                Gate::authorize('create', ContentBlock::class);
                $block = app(CreateContentBlock::class)->handle($this->actingUser(), $name, $this->formRegion);
                $this->formOpen = false;
                // the block editor shows it as it opens
                session()->flash(self::FLASH, __('blocks.toast_created', ['name' => $block->name]));
                $this->redirectRoute('admin.blocks.edit', ['ulid' => $block->ulid], navigate: true);

                return;
            }

            $block = $this->writable($this->editing, 'update');
            if ($block === null) {
                $this->closeForm();

                return;
            }
            app(UpdateContentBlock::class)->handle($this->actingUser(), $block, $name, $this->formRegion, $this->editingVersion);
            $this->closeForm();
            $this->toast(__('blocks.toast_saved', ['name' => $name]));
        } catch (ContentBlockChangeRejectedException $e) {
            $this->refused($e->reason);
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');
        }
    }

    /** The dialog's «Sửa nội dung (Trình dựng)»: the block's content in the block editor. */
    public function openContent(): void
    {
        $block = $this->editing === null ? null : $this->writable($this->editing, 'update');
        if ($block === null) {
            return;
        }
        $this->formOpen = false;
        $this->redirectRoute('admin.blocks.edit', ['ulid' => $block->ulid], navigate: true);
    }

    /** «Nhân bản»: a copy with every language, a new reference code and no places. */
    public function duplicate(string $ulid): void
    {
        $block = $this->writable($ulid, 'duplicate');
        if ($block === null) {
            return;
        }
        try {
            app(DuplicateContentBlock::class)->handle($this->actingUser(), $block);
            $this->toast(__('blocks.toast_duplicated', ['name' => $block->name]));
        } catch (ContentBlockChangeRejectedException $e) {
            $this->refused($e->reason);
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');
        }
    }

    /** «N nơi» on a row: where the block is used, each place named in the website's default language. */
    public function showUsage(string $ulid): void
    {
        $this->authorizeLibrary();
        $block = ContentBlock::query()->where('ulid', $ulid)->first(['id', 'ulid', 'name']);
        if ($block === null || Gate::denies('view', $block)) {
            $this->toast(__('blocks.toast_gone'), 'error');

            return;
        }
        $usages = app(ContentBlockUsages::class);
        $places = $usages->placesOf($block);
        $this->usage = [
            'name' => $block->name,
            'total' => $usages->countsFor([$block->id])[$block->id] ?? 0,
            'places' => array_map(fn (array $place): array => $this->place($place), $places),
        ];
        $this->usageOpen = true;
    }

    public function closeUsage(): void
    {
        $this->authorizeLibrary();
        $this->usageOpen = false;
        $this->usage = null;
    }

    /** «Xuất bản» in the row's ⋯: the block shows wherever it is placed; a toast says where. */
    public function publish(string $ulid): void
    {
        $block = $this->writable($ulid, 'publish');
        if ($block === null) {
            return;
        }
        try {
            app(PublishContentBlock::class)->handle($this->actingUser(), $block, true);
            $places = app(ContentBlockUsages::class)->countsFor([$block->id])[$block->id] ?? 0;
            $this->toast($places > 0
                ? __('blocks.toast_published', ['name' => $block->name, 'places' => trans_choice('blocks.n_places', $places, ['n' => $places])])
                : __('blocks.toast_published_none', ['name' => $block->name]));
        } catch (ContentBlockChangeRejectedException $e) {
            $this->refused($e->reason);
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');
        }
    }

    /** «Đưa về nháp» in the row's ⋯: used somewhere, it asks first (the delete question's dialog); else it acts. */
    public function askUnpublish(string $ulid): void
    {
        $block = $this->writable($ulid, 'publish');
        if ($block === null) {
            return;
        }
        $places = app(ContentBlockUsages::class)->countsFor([$block->id])[$block->id] ?? 0;
        if ($places === 0) {
            $this->unpublishNow($block);

            return;
        }
        $this->asking = 'draft';
        $this->deleting = ['ulid' => $block->ulid, 'name' => $block->name, 'places' => $places, 'menu' => $block->region === 'menu'];
        $this->confirmOpen = true;
    }

    /** The dialog's «Đưa về nháp»: the website stops showing the block; its content and places stay. */
    public function confirmUnpublish(): void
    {
        $asked = $this->deleting;
        $this->cancelDelete();
        $block = $asked === null ? null : $this->writable($asked['ulid'], 'publish');
        if ($block !== null) {
            $this->unpublishNow($block);
        }
    }

    private function unpublishNow(ContentBlock $block): void
    {
        try {
            app(PublishContentBlock::class)->handle($this->actingUser(), $block, false);
            $this->toast(__('blocks.toast_unpublished', ['name' => $block->name]));
        } catch (ContentBlockChangeRejectedException $e) {
            $this->refused($e->reason);
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');
        }
    }

    /** «Xóa» in the row's ⋮: the question says how many places use the block, read now. */
    public function askDelete(string $ulid): void
    {
        $block = $this->writable($ulid, 'delete');
        if ($block === null) {
            return;
        }
        $this->deleting = [
            'ulid' => $block->ulid,
            'name' => $block->name,
            'places' => app(ContentBlockUsages::class)->countsFor([$block->id])[$block->id] ?? 0,
            'menu' => $block->region === 'menu',
        ];
        $this->asking = 'delete';
        $this->confirmOpen = true;
    }

    /** The dialog's «Vẫn xóa»: the block, its languages and where it was used go for good. */
    public function confirmDelete(): void
    {
        $asked = $this->deleting;
        $this->cancelDelete();
        $block = $asked === null ? null : $this->writable($asked['ulid'], 'delete');
        if ($asked === null || $block === null) {
            return;
        }
        // placed somewhere more since the question was asked: ask again with the new count
        $places = app(ContentBlockUsages::class)->countsFor([$block->id])[$block->id] ?? 0;
        if ($places > $asked['places']) {
            $this->deleting = ['places' => $places] + $asked;
            $this->confirmOpen = true;
            $this->toast(__('blocks.del_recount'), 'error');

            return;
        }
        try {
            app(DeleteContentBlock::class)->handle($this->actingUser(), $block);
            $this->toast(__('blocks.toast_deleted', ['name' => $asked['name']]));
        } catch (ContentBlockChangeRejectedException $e) {
            $this->refused($e->reason);
        } catch (AuthorizationException) {
            $this->toast(__('blocks.toast_cannot'), 'error');
        }
    }

    public function cancelDelete(): void
    {
        $this->authorizeLibrary();
        $this->confirmOpen = false;
        $this->deleting = null;
        $this->asking = 'delete';
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
        $this->clean();
        $status = $this->tab === 'all' ? null : $this->tab;
        $region = $this->region === 'all' ? null : $this->region;
        $siteLocales = app(SiteLocale::class);
        $locales = $siteLocales->enabled();
        $default = $siteLocales->default();
        $time = app(SiteTime::class);

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $read = DB::transaction(function () use ($status, $region): array {
                $list = app(ContentBlockList::class);
                $blocks = [];
                $after = null;
                $more = false;
                for ($page = 0; $page < $this->pages; $page++) {
                    $chunk = $list->page($status, $region, $after, ContentBlockList::PAGE_SIZE, $this->search);
                    array_push($blocks, ...$chunk['rows']);
                    $after = $chunk['next'];
                    $more = $after !== null;
                    if (! $more) {
                        break;
                    }
                }
                $ids = array_map(fn (ContentBlock $block): int => $block->id, $blocks);

                return [
                    'blocks' => $blocks,
                    'more' => $more,
                    'counts' => $list->counts(),
                    'matched' => $list->count($status, $region, $this->search),
                    'locales' => $list->locales($ids),
                    'places' => app(ContentBlockUsages::class)->countsFor($ids),
                ];
            });
            $failed = false;
        } catch (QueryException $e) {
            report($e);
            $read = ['blocks' => [], 'more' => false, 'counts' => [], 'matched' => 0, 'locales' => [], 'places' => []];
            $failed = true;
        }

        $rows = array_map(fn (ContentBlock $block): array => [
            'ulid' => $block->ulid,
            'name' => $block->name,
            'key' => $block->key,
            'region' => $block->region,
            'published' => $block->status === ContentBlock::PUBLISHED,
            'updated' => $time->day($block->updated_at, app()->getLocale()),
            'locales' => $read['locales'][$block->id] ?? [],
            'places' => $read['places'][$block->id] ?? 0,
        ], $read['blocks']);

        $counts = $read['counts'];
        $inRegion = fn (string $s): int => $region === null ? array_sum($counts[$s] ?? []) : ($counts[$s][$region] ?? 0);
        $inStatus = fn (string $r): int => $status === null
            ? array_sum(array_map(fn (array $byRegion): int => $byRegion[$r] ?? 0, $counts))
            : ($counts[$status][$r] ?? 0);
        $tabCounts = ['all' => $inRegion('published') + $inRegion('draft'), 'published' => $inRegion('published'), 'draft' => $inRegion('draft')];
        $regionCounts = ['all' => array_sum(array_map($inStatus, ContentBlock::REGIONS))];
        foreach (ContentBlock::REGIONS as $r) {
            $regionCounts[$r] = $inStatus($r);
        }

        return view('admin::livewire.blocks.library', [
            'failed' => $failed,
            'rows' => $rows,
            'more' => $read['more'],
            'matched' => $read['matched'],
            'any' => array_sum(array_map('array_sum', $counts)) > 0,
            'tabCounts' => $tabCounts,
            'regionCounts' => $regionCounts,
            'locales' => $locales,
            'defaultLocale' => $default,
            'backUrl' => isset(self::RETURNS[$this->back]) ? route(self::RETURNS[$this->back]) : null,
        ])->title(__('blocks.title'));
    }

    /**
     * One place of «Nơi đang dùng khối» in words, with where to go to change it.
     *
     * @param  array{kind: string, ulid: string|null, name: string|null, menu: string|null, slot: string|null, locales: list<string>}  $place
     * @return array{label: string, href: string|null}
     */
    private function place(array $place): array
    {
        $slot = $place['slot'] === null ? '' : __('blocks.slot.'.$place['slot']);

        return match ($place['kind']) {
            'page', 'post' => [
                'label' => $place['name'] ?? __('blocks.place_untitled'),
                'href' => $place['ulid'] !== null && Gate::allows('entries.view') ? route('admin.entries.edit', ['ulid' => $place['ulid']]) : null,
            ],
            'category' => ['label' => __('blocks.place_category', ['name' => $place['name'] ?? __('blocks.place_untitled'), 'slot' => $slot]), 'href' => null],
            'site' => ['label' => __('blocks.place_site', ['slot' => $slot]), 'href' => null],
            default => [
                'label' => __('blocks.place_menu', ['menu' => $place['menu'] ?? '—', 'item' => $place['name'] ?? __('blocks.place_untitled')]),
                'href' => Gate::allows('menus.manage') ? route('admin.menus.index') : null,
            ],
        };
    }

    /** Keep only values the screen knows: a pasted address with anything else falls back to the default. */
    private function clean(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'all';
        }
        if ($this->region !== 'all' && ! in_array($this->region, ContentBlock::REGIONS, true)) {
            $this->region = 'all';
        }
        if (! isset(self::RETURNS[$this->back])) {
            $this->back = '';
        }
        $this->search = Str::limit($this->search, 100, '');
    }

    /** The block behind $ulid when the member may do $ability to it; else a toast says why, and null. */
    private function writable(string $ulid, string $ability): ?ContentBlock
    {
        $this->authorizeLibrary();
        $block = ContentBlock::query()->where('ulid', $ulid)->first();
        if ($block === null) {
            // gone meanwhile (or never this site's: another site's block reads as gone, never as "not allowed")
            $this->toast(__('blocks.toast_gone'), 'error');

            return null;
        }
        if (Gate::denies($ability, $block)) {
            $this->toast(__('blocks.toast_cannot'), 'error');

            return null;
        }

        return $block;
    }

    /** A change the backend refused, in words: a stale dialog reloads, a full library says so. */
    private function refused(string $reason): void
    {
        if ($reason === 'version_stale' && $this->editing !== null) {
            // the dialog keeps what was typed and takes the block as it is now: a second «Lưu» saves it over that
            $fresh = ContentBlock::query()->where('ulid', $this->editing)->first();
            if ($fresh === null) {
                $this->closeForm();
                $reason = 'block_missing';
            } else {
                $this->editingVersion = ContentBlockVersion::current($fresh);
            }
        }
        $this->toast(__(match ($reason) {
            'name_invalid' => 'blocks.err_name_required',
            'library_full' => 'blocks.err_full',
            'version_stale' => 'blocks.err_stale',
            'block_missing' => 'blocks.toast_gone',
            default => 'blocks.err_generic',
        }, ['max' => ContentBlockRules::max()]), 'error');
    }

    private function toast(string $message, string $tone = 'success'): void
    {
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }
}
