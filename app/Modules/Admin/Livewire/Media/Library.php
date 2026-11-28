<?php

namespace App\Modules\Admin\Livewire\Media;

use App\Modules\Admin\Livewire\Concerns\WithMediaDetailDrawer;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\MediaDeleteRights;
use App\Modules\Admin\Support\MediaLibraryDirectory;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Jobs\DeleteLargeMediaFolder;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The media library page (/admin/media): a folder column, the tools row (search, filters, sort,
 * grid/list), the files page by page from a cursor, selection with move / Trash / restore / purge,
 * and the upload panel. Files and folders are addressed by ulid and slug only — never a bigint id.
 * Read is gated by media.view (re-checked in boot(): the livewire/update endpoint bypasses route
 * middleware); every action re-checks its own right: making, renaming, reordering or deleting a
 * folder needs media.folders and deleting for good needs media.purge (both the website admin's),
 * moving files into a folder stays with media.upload and the Trash with media.delete.own / .any
 * (MediaDeleteRights: «own» = only this person's own uploads that nobody uses). The upload
 * panes are the shared picker's (WithMediaLibraryPicker in manage mode); a file opens in the
 * library's own detail drawer (WithMediaDetailDrawer).
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use WithMediaDetailDrawer;
    use WithMediaLibraryPicker;

    /** '' = all files, '-' = files in no folder, else a folder slug. */
    public string $folder = '';

    /** 'live' or 'trash' (the Trash needs a delete right, checked wherever it is read). */
    public string $status = 'live';

    public string $search = '';

    /** @var list<string> */
    public array $kinds = [];

    /** @var list<string> */
    public array $formats = [];

    /** @var list<string> */
    public array $uses = [];

    /** @var list<string> «Người tạo»: the chosen uploaders' ulids */
    public array $by = [];

    /** «Thời gian» by upload day: a MediaLibraryDirectory::WHEN choice ('' = every time). */
    public string $when = '';

    /** «Tùy chọn»: the first and last upload day (Y-m-d, either may be empty). */
    public string $from = '';

    public string $to = '';

    /**
     * The «Thư mục» choice of the Filter sheet while the folder column is hidden: '' = all files,
     * '-' = files in no folder, a folder slug, or '~trash' while the Trash is open (no folder chosen).
     */
    public string $folderPick = '';

    /**
     * Whether the Filter sheet or one filter's panel is open: only then are the groups' own counts read
     * (each «Thời gian» choice, each uploader, the list of uploaders). The browser turns it on as one
     * opens and off, with no request of its own, once none is open; a page turn or a drawer never reads them.
     */
    public bool $tally = false;

    public string $sort = 'new';

    public string $view = 'grid';

    public int $per = 24;

    public string $after = '';

    public string $before = '';

    /** Which page of the current list is shown (for "Hiện 25–48"); display only. */
    public int $pageNo = 1;

    /** @var list<string> the chosen files' ulids */
    public array $picked = [];

    public bool $uploadOpen = false;

    public bool $newFolderOpen = false;

    public string $newFolderName = '';

    /** Where the new folder goes: '' = a top-level folder, else the slug of the top-level folder it goes in. */
    public string $newFolderParent = '';

    public bool $folderSheetOpen = false;

    public bool $moveOpen = false;

    /** '' = not chosen yet, '-' = no folder, else a folder slug. */
    public string $moveTo = '';

    public bool $askTrashOpen = false;

    /** How many chosen files stay out of that Trash move (someone else's, or in use): the question's wording. */
    #[Locked]
    public int $trashKept = 0;

    /** With media.delete.own: how many of the chosen files the asked question sends (its title and OK). */
    #[Locked]
    public int $trashGo = 0;

    public bool $askPurgeOpen = false;

    #[Locked]
    public bool $purgeAll = false;

    /** @var list<string> the files the last move to Trash took (for Hoàn tác) */
    #[Locked]
    public array $undoTrash = [];

    /** The slug of the folder whose name is being changed ('' = none). */
    #[Locked]
    public string $renaming = '';

    public string $renameName = '';

    public bool $askFolderOpen = false;

    /** The slug of the folder the delete question is about. */
    #[Locked]
    public string $folderToDelete = '';

    /** The folder asked about holds more files than Hoàn tác keeps (config media.folder_undo_max_files). */
    #[Locked]
    public bool $folderNoUndo = false;

    /** The folder asked about holds more files than one request moves (config media.folder_delete_inline_max_files). */
    #[Locked]
    public bool $folderInBatches = false;

    public bool $reparentOpen = false;

    /** The slug of the folder «Chuyển thư mục…» is about. */
    #[Locked]
    public string $reparentFolder = '';

    /** Where it goes: '' = not chosen yet, '-' = up to the top level, else the slug of a top-level folder. */
    public string $reparentTo = '';

    /**
     * The key of the last «Chuyển thư mục…» kept for Hoàn tác (the folder, its parent before and its
     * place, server side: the page holds no folder id).
     */
    #[Locked]
    public string $undoReparent = '';

    /** The key of the last folder delete kept for Hoàn tác (the folder and its files, server side). */
    #[Locked]
    public string $undoFolder = '';

    public ?string $toast = null;

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public const int FOLDER_NAME_MAX = 80;

    /** How long a deleted folder can be brought back with Hoàn tác (the toast itself stays 10 seconds). */
    public const int FOLDER_UNDO_MINUTES = 15;

    /** Properties that change which files are listed: the page and the selection start again. */
    private const array LIST_PROPS = ['search', 'kinds', 'formats', 'uses', 'by', 'when', 'from', 'to', 'sort', 'per', 'status', 'folder'];

    public function mount(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->pickerManageMode = true;
        $this->showImagePicker = true; // the shared upload panes listen for finished uploads
        $this->pickerTab = 'library';
    }

    public function boot(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
    }

    public function updated(string $property): void
    {
        $name = Str::before($property, '.');
        if ($name === 'folderPick') {
            $this->setFolder($this->folderPick);

            return;
        }
        if ($name === 'when' && $this->when !== MediaLibraryDirectory::RANGE) {
            $this->from = $this->to = '';
        }
        if ($name === 'from' || $name === 'to') {
            $this->when = self::dayOr($this->from) !== '' || self::dayOr($this->to) !== '' ? MediaLibraryDirectory::RANGE : $this->when;
        }
        $this->clean();
        if (in_array($name, self::LIST_PROPS, true)) {
            $this->restart();
        }
        if ($name === 'newFolderName') {
            $this->resetErrorBag('newFolderName');
        }
        if (in_array($name, ['moveOpen', 'askTrashOpen', 'askPurgeOpen'], true) && $this->{$name} === false) {
            $this->single = false; // a dialog closed by Esc or its backdrop: the open file's turn ends
        }
    }

    public function setFolder(string $key): void
    {
        $toTrash = $key === '~trash' && MediaDeleteRights::some();
        $this->folder = $toTrash ? '' : $key;
        $this->status = $toTrash ? 'trash' : 'live';
        $this->clean();
        $this->restart();
        $this->folderSheetOpen = false;
        $this->closePickerDetail();
    }

    public function setSort(string $key): void
    {
        $this->sort = array_key_exists($key, MediaLibraryDirectory::SORTS) ? $key : 'new';
        $this->restart();
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'grid';
    }

    public function older(string $ulid): void
    {
        $this->after = self::ulidOr($ulid);
        $this->before = '';
        $this->pageNo++;
    }

    public function newer(string $ulid): void
    {
        $this->before = self::ulidOr($ulid);
        $this->after = '';
        $this->pageNo = max(1, $this->pageNo - 1);
    }

    /** Bỏ lọc: search and every condition go; the place (folder / Trash) and the order stay. */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->kinds = $this->formats = $this->uses = $this->by = [];
        $this->when = $this->from = $this->to = '';
        $this->restart();
    }

    public function clearBy(): void
    {
        $this->by = [];
        $this->restart();
    }

    public function clearWhen(): void
    {
        $this->when = $this->from = $this->to = '';
        $this->restart();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->restart();
    }

    public function clearKinds(): void
    {
        $this->kinds = [];
        $this->restart();
    }

    public function clearFormats(): void
    {
        $this->formats = [];
        $this->restart();
    }

    public function clearUses(): void
    {
        $this->uses = [];
        $this->restart();
    }

    public function clearStatus(): void
    {
        $this->status = 'live';
        $this->restart();
    }

    public function removeCondition(string $group, string $value): void
    {
        match ($group) {
            'kinds' => $this->kinds = array_values(array_diff($this->kinds, [$value])),
            'formats' => $this->formats = array_values(array_diff($this->formats, [$value])),
            'uses' => $this->uses = array_values(array_diff($this->uses, [$value])),
            default => null,
        };
        $this->restart();
    }

    public function togglePick(string $ulid): void
    {
        abort_unless($this->canPick(), 403);

        if (in_array($ulid, $this->picked, true)) {
            $this->picked = array_values(array_diff($this->picked, [$ulid]));

            return;
        }
        if ($this->placeQuery()->where('ulid', self::ulidOr($ulid))->exists()) {
            $this->picked[] = $ulid;
        }
    }

    /**
     * "Chọn tất cả": choose every file of this page, or, when all are chosen, none of them.
     *
     * @param  array<mixed>  $ulids
     */
    public function togglePage(array $ulids): void
    {
        abort_unless($this->canPick(), 403);

        $ulids = array_values(array_filter(array_map(fn (mixed $u): string => is_string($u) ? self::ulidOr($u) : '', $ulids)));
        if ($ulids !== [] && array_diff($ulids, $this->picked) === []) {
            $this->picked = array_values(array_diff($this->picked, $ulids));

            return;
        }
        $found = $this->placeQuery()->whereIn('ulid', array_slice($ulids, 0, 96))->pluck('ulid')->all();
        $this->picked = array_values(array_unique([...$this->picked, ...Cast::strings($found)]));
    }

    public function clearPicked(): void
    {
        $this->picked = [];
    }

    /**
     * The upload panel, on its choice of source; 'device' goes straight to the device pane (files
     * dropped on the page, handed over by the browser). New files go into the folder in view.
     */
    public function openUpload(string $source = ''): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->uploadOpen = true;
        $this->pickerSource = $source === 'device' ? 'device' : null;
        $this->closePickerDetail();
        $this->dispatch('media-upload-opened'); // the page moves focus to the panel's heading
    }

    /** «Tải ảnh lên vào đây» (a folder's menu): that folder opens, with the upload panel on it. */
    public function uploadInto(string $slug): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $folder = self::folderBySlug($slug);
        if ($folder === null) {
            return; // gone meanwhile, or never this site's
        }
        if (self::deleting($folder)) {
            // being emptied in the background: the upload endpoints would leave a new file unsorted
            $this->say(__('media.lib.folder_deleting', ['name' => $folder->name]), 'info');

            return;
        }
        $this->setFolder((string) $folder->slug);
        if ($folder->parent_id !== null) {
            $this->dispatch('media-folder-open', slug: Cast::string(MediaFolder::query()->whereKey($folder->parent_id)->value('slug')));
        }
        $this->openUpload();
    }

    /** «Mở Thùng rác» / «Dọn Thùng rác» on the storage card: the Trash opens (the upload panel closes). */
    public function openTrash(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        $this->closeUpload();
        $this->setFolder('~trash');
    }

    public function closeUpload(): void
    {
        $this->discardStagedUrlPreview();
        $this->pickerUrlInput = '';
        $this->uploadOpen = false;
        $this->pickerSource = null;
    }

    /**
     * A finished upload batch: the new files are the newest, so the list starts again. The upload
     * panel stays open either way (its list shows what failed), so the failure count is not needed.
     */
    #[On('media-uploaded')]
    public function onPickerMediaUploaded(int $failed = 0): void
    {
        $this->status = 'live';
        $this->restart();
    }

    public function openNewFolder(string $parent = ''): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        // «Thư mục con mới» names a top-level folder; anything else starts a top-level folder
        $top = $parent === '' ? null : self::folderBySlug($parent);
        $this->newFolderParent = $top !== null && $top->parent_id === null ? (string) $top->slug : '';
        $this->newFolderOpen = true;
        $this->newFolderName = '';
        $this->folderSheetOpen = false;
        $this->resetErrorBag(['newFolderName', 'newFolderParent']);
    }

    public function cancelNewFolder(): void
    {
        $this->newFolderOpen = false;
        $this->newFolderName = '';
        $this->newFolderParent = '';
        $this->resetErrorBag(['newFolderName', 'newFolderParent']);
    }

    public function createFolder(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $name = trim($this->newFolderName);
        $this->resetErrorBag(['newFolderName', 'newFolderParent']);
        $parentSlug = $this->newFolderParent;

        // under the site's folder-list lock, like every folder write: the checks see what others committed
        $made = DB::transaction(function () use ($name, $parentSlug): MediaFolder|array|string {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $parent = $parentSlug === '' ? null : self::folderBySlug($parentSlug);
            if ($parentSlug !== '' && ($parent === null || $parent->parent_id !== null || self::deleting($parent))) {
                return ['newFolderParent', Cast::string(__('media.lib.folder_parent_gone'))]; // deleted or made a sub-folder meanwhile
            }
            $error = self::folderNameError($name, null, $parent?->id);
            if ($error !== null) {
                return $error;
            }
            $maxPosition = MediaFolder::query()->where('parent_id', $parent?->id)->max('position');

            return MediaFolder::query()->create([
                'name' => $name,
                'slug' => (new MediaFolder)->uniqueSlug($name, $name),
                'parent_id' => $parent?->id,
                'position' => (is_numeric($maxPosition) ? (int) $maxPosition : 0) + 1,
            ]);
        });
        if (! $made instanceof MediaFolder) {
            [$field, $error] = is_array($made) ? $made : ['newFolderName', $made];
            $this->addError($field, $error);

            return;
        }

        $this->cancelNewFolder();
        $this->setFolder((string) $made->slug);
        $parent = $made->parent_id === null ? null : MediaFolder::query()->whereKey($made->parent_id)->first(['slug', 'name']);
        if ($parent !== null) {
            $this->dispatch('media-folder-open', slug: (string) $parent->slug);
        }
        $this->say($parent === null
            ? __('media.lib.folder_created', ['name' => $name])
            : __('media.lib.subfolder_created', ['name' => $name, 'parent' => $parent->name]));
    }

    public function openFolderSheet(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = true;
    }

    public function closeFolderSheet(): void
    {
        $this->folderSheetOpen = false;
    }

    /** "Đổi tên": the name changes, the slug (the folder's address in the admin) stays. */
    public function openRename(string $slug): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $folder = self::folderBySlug($slug);
        $this->folderSheetOpen = false;
        if ($folder === null) {
            return;
        }
        $this->cancelNewFolder();
        $this->renaming = (string) $folder->slug;
        $this->renameName = $folder->name;
        $this->resetErrorBag('renameName');
    }

    public function cancelRename(): void
    {
        $this->renaming = '';
        $this->renameName = '';
        $this->resetErrorBag('renameName');
    }

    public function saveRename(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $slug = $this->renaming;
        $name = trim($this->renameName);
        $this->resetErrorBag('renameName');
        // under the site's folder-list lock: the name check sees a folder created or moved in meanwhile
        $done = DB::transaction(function () use ($slug, $name): array|string|null {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $folder = self::folderBySlug($slug);
            if ($folder === null) {
                return null; // gone meanwhile, or never this site's
            }
            $error = self::folderNameError($name, $folder->id, $folder->parent_id);
            if ($error !== null) {
                return $error;
            }
            $old = $folder->name;
            if ($old !== $name) {
                $folder->update(['name' => $name]);
            }

            return ['old' => $old];
        });
        if (is_string($done)) {
            $this->addError('renameName', $done);

            return;
        }
        $this->cancelRename();
        if ($done !== null && $done['old'] !== $name) {
            $this->say(__('media.lib.folder_renamed', ['old' => $done['old'], 'name' => $name]));
        }
    }

    /** "Chuyển lên" (-1) / "Chuyển xuống" (+1): swaps the folder with its neighbour among the folders of its parent. */
    public function moveFolder(string $slug, int $step): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = false;
        $step = $step < 0 ? -1 : 1;
        // under the site's folder-list lock, so two people moving at once never leave equal positions
        $moved = DB::transaction(function () use ($slug, $step): bool {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $me = self::folderBySlug($slug);
            if ($me === null) {
                return false;
            }
            /** @var list<MediaFolder> $order */
            $order = MediaFolder::query()->where('parent_id', $me->parent_id)->orderBy('position')->orderBy('name')->orderBy('id')
                ->get(['id', 'slug', 'name', 'position'])->all();
            $at = null;
            foreach ($order as $i => $folder) {
                if ($folder->id === $me->id) {
                    $at = $i;
                }
            }
            $to = $at === null ? -1 : $at + $step;
            if ($at === null || $to < 0 || $to >= count($order)) {
                return false;
            }
            [$order[$at], $order[$to]] = [$order[$to], $order[$at]];
            foreach ($order as $i => $folder) {
                if ($folder->position !== $i + 1) {
                    MediaFolder::query()->whereKey($folder->id)->update(['position' => $i + 1]);
                }
            }

            return true;
        });

        if ($moved) {
            $this->say(__($step < 0 ? 'media.lib.folder_moved_up' : 'media.lib.folder_moved_down'));
        }
    }

    /**
     * «Chuyển thư mục…»: a folder goes inside a top-level folder, or a sub-folder goes up to the top
     * level. Not for a top-level folder that holds sub-folders (that would make three levels), nor
     * when there is no other top-level folder to go in — the menu says why.
     */
    public function openReparent(string $slug): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = false;
        $folder = self::folderBySlug($slug);
        if ($folder === null || self::reparentBlocked($folder) !== null) {
            return;
        }
        $this->reparentFolder = (string) $folder->slug;
        $this->reparentTo = '';
        $this->reparentOpen = true;
    }

    public function cancelReparent(): void
    {
        $this->reparentOpen = false;
        $this->reparentFolder = '';
        $this->reparentTo = '';
    }

    public function confirmReparent(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $slug = $this->reparentFolder;
        $to = $this->reparentTo;
        if ($slug === '' || $to === '') {
            return;
        }
        $this->cancelReparent();

        $done = DB::transaction(function () use ($slug, $to): array|string|null {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $folder = self::folderBySlug($slug);
            $target = $to === '-' ? null : self::folderBySlug($to);
            if ($folder === null || ($to !== '-' && ($target === null || $target->parent_id !== null || $target->id === $folder->id || self::deleting($target)))) {
                return null; // gone, another site's, or a sub-folder: nothing changes
            }
            if ($target?->id === $folder->parent_id
                || ($target !== null && MediaFolder::query()->where('parent_id', $folder->id)->exists())) {
                return null; // already there, or it holds sub-folders (a third level)
            }
            if (self::folderNameError($folder->name, $folder->id, $target?->id) !== null) {
                return 'taken';
            }
            $before = [
                'folder' => (string) $folder->slug,
                'folderId' => $folder->id,
                'parent' => $folder->parent_id === null ? '' : Cast::string(MediaFolder::query()->whereKey($folder->parent_id)->value('slug')),
                'parentId' => $folder->parent_id ?? 0,
                'position' => self::slotOf($folder),
            ];
            $from = $folder->parent_id;
            $folder->update(['parent_id' => $target?->id]);
            self::placeAmongSiblings($target?->id, $folder->id);
            self::placeAmongSiblings($from);

            return ['before' => $before, 'name' => $folder->name, 'target' => $target];
        });

        if ($done === 'taken') {
            $this->say(__('media.lib.reparent_name_taken'), 'error');

            return;
        }
        if (! is_array($done)) {
            $this->say(__('media.lib.reparent_gone'), 'error');

            return;
        }
        $target = $done['target'];
        if ($target instanceof MediaFolder) {
            $this->dispatch('media-folder-open', slug: (string) $target->slug);
        }
        $this->say($target instanceof MediaFolder
            ? __('media.lib.reparented', ['name' => $done['name'], 'parent' => $target->name])
            : __('media.lib.reparented_top', ['name' => $done['name']]));
        $token = Str::random(40);
        Cache::put(self::folderUndoKey('move-'.$token), $done['before'], now()->addMinutes(self::FOLDER_UNDO_MINUTES));
        $this->undoReparent = $token; // after say(): this toast's Hoàn tác
    }

    /** "Hoàn tác" after «Chuyển thư mục…»: the folder goes back where it was, at its old place. */
    public function undoReparent(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $key = self::folderUndoKey('move-'.$this->undoReparent);
        $raw = $this->undoReparent === '' ? null : Cache::pull($key);
        $kept = $raw;
        $this->undoReparent = '';
        if (! is_array($kept) || ! is_string($kept['folder'] ?? null) || ! is_string($kept['parent'] ?? null)) {
            $this->say(__('media.lib.reparent_undo_gone'), 'error');

            return;
        }
        $kept = ['folder' => $kept['folder'], 'folderId' => Cast::int($kept['folderId'] ?? 0), 'parent' => $kept['parent'],
            'parentId' => Cast::int($kept['parentId'] ?? 0), 'position' => Cast::int($kept['position'] ?? 1)];

        try {
            $name = $this->undoReparentNow($kept);
        } catch (Throwable $e) {
            Cache::put($key, $raw, now()->addMinutes(self::FOLDER_UNDO_MINUTES)); // the next try still has it
            throw $e;
        }

        $name === null
            ? $this->say(__('media.lib.reparent_undo_gone'), 'error')
            : $this->say(__('media.lib.reparent_back', ['name' => $name]));
    }

    /**
     * Puts the moved folder back, under the folder-list lock; null when it or its old place changed.
     *
     * @param  array{folder: string, folderId: int, parent: string, parentId: int, position: int}  $kept
     */
    private function undoReparentNow(array $kept): ?string
    {
        return DB::transaction(function () use ($kept): ?string {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $folder = MediaFolder::query()->whereKey($kept['folderId'])->where('slug', $kept['folder'])->first();
            $parent = $kept['parent'] === '' ? null : self::folderBySlug($kept['parent']);
            if ($folder === null || ($kept['parent'] !== '' && ($parent === null || $parent->id !== $kept['parentId'] || $parent->parent_id !== null || self::deleting($parent)))
                || ($parent !== null && MediaFolder::query()->where('parent_id', $folder->id)->exists())
                || self::folderNameError($folder->name, $folder->id, $parent?->id) !== null) {
                return null; // the folder or its old parent changed since: it stays where it is
            }
            $from = $folder->parent_id;
            $folder->update(['parent_id' => $parent?->id]);
            self::placeAmongSiblings($parent?->id, $folder->id, $kept['position']);
            self::placeAmongSiblings($from);

            return $folder->name;
        });
    }

    /**
     * "Xóa thư mục": an empty folder without sub-folders goes at once (with Hoàn tác); one with
     * files or sub-folders asks first. Sub-folders go with it; files are never deleted with their
     * folder — they go back to "Chưa phân loại".
     */
    public function askDeleteFolder(string $slug): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = false;
        $folder = self::folderBySlug($slug);
        if ($folder === null) {
            return;
        }
        $family = self::familyIds($folder->id);
        $inBatches = self::tooManyInline($family);
        $noUndo = $inBatches || self::tooManyForUndo($family);
        if (! $noUndo && count($family) === 1 && ! Media::query()->where('folder_id', $folder->id)->exists()) {
            $this->deleteFolder($folder);

            return;
        }
        $this->folderToDelete = (string) $folder->slug;
        $this->folderNoUndo = $noUndo;
        $this->folderInBatches = $inBatches;
        $this->askFolderOpen = true;
    }

    public function cancelDeleteFolder(): void
    {
        $this->askFolderOpen = false;
        $this->folderToDelete = '';
        $this->folderNoUndo = false;
        $this->folderInBatches = false;
    }

    public function confirmDeleteFolder(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $folder = self::folderBySlug($this->folderToDelete);
        $this->cancelDeleteFolder();
        if ($folder !== null) {
            $this->deleteFolder($folder);
        }
    }

    /**
     * "Hoàn tác" after a folder delete: the folder comes back at its place in the list, in the folder
     * it was in (at the top level if that one went meanwhile), with its sub-folders and the files that
     * were in them. A folder of the same name made meanwhile keeps its name; the one coming back takes
     * the first free "Tên (2)", "Tên (3)"…, so no file is left out of its folder.
     */
    public function undoFolderDelete(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $key = self::folderUndoKey($this->undoFolder);
        $kept = $this->undoFolder === '' ? null : Cache::pull($key);
        $this->undoFolder = '';
        if (! is_array($kept) || self::keptFolder($kept) === null) {
            $this->say(__('media.lib.folder_undo_gone'), 'error');

            return;
        }

        try {
            $folder = DB::transaction(function () use ($kept): MediaFolder {
                MediaFolder::lockTree(app(CurrentSite::class)->id());
                $one = self::keptFolder($kept) ?? throw new LogicException('checked above');
                // the folder it was in, when that one is still there (the same row, not a new one that took its slug)
                $parent = is_numeric($kept['parentId'] ?? null) && is_string($kept['parent'] ?? null)
                    ? MediaFolder::query()->whereKey(Cast::int($kept['parentId']))->where('slug', $kept['parent'])->whereNull('parent_id')->first()
                    : null;
                if ($parent !== null && self::deleting($parent)) {
                    $parent = null; // its parent is being deleted: it comes back at the top level, as when the parent is gone
                }

                $folder = self::bringBack($one, $parent?->id);
                self::placeAmongSiblings($parent?->id, $folder->id, max(1, $one['position']));
                $kids = is_array($kept['kids'] ?? null) ? $kept['kids'] : [];
                $at = 0;
                foreach ($kids as $kid) {
                    $k = is_array($kid) ? self::keptFolder($kid) : null;
                    if ($k !== null && $parent === null) { // a sub-folder never holds sub-folders
                        $k['position'] = ++$at;
                        self::bringBack($k, $folder->id);
                    }
                }

                return $folder;
            });
        } catch (Throwable $e) {
            Cache::put($key, $kept, now()->addMinutes(self::FOLDER_UNDO_MINUTES)); // the next try still has it

            throw $e;
        }

        if ($folder->parent_id !== null) {
            $this->dispatch('media-folder-open', slug: Cast::string(MediaFolder::query()->whereKey($folder->parent_id)->value('slug')));
        }
        $this->setFolder((string) $folder->slug);
        $this->say(__('media.lib.folder_back', ['name' => $folder->name]));
    }

    public function openMove(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->single = false;
        $this->moveTo = '';
        $this->moveOpen = $this->picked !== [];
    }

    public function cancelMove(): void
    {
        $this->moveOpen = false;
        $this->moveTo = '';
        $this->single = false;
    }

    public function confirmMove(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        if ($this->moveTo === '') {
            return;
        }
        $target = null;
        if ($this->moveTo !== '-') {
            $target = MediaFolder::query()->where('slug', $this->moveTo)->first();
            if ($target === null) {
                $this->cancelMove();

                return; // a folder of another site, or one that just went: never a cross-site write
            }
        }

        $n = Media::query()->whereIn('ulid', $this->targets())->update(['folder_id' => $target?->id]);
        $this->doneWithTargets();
        $this->cancelMove();
        $this->say(__('media.lib.moved', ['n' => $n, 'folder' => $target !== null ? $target->name : __('media.lib.unfiled')]));
    }

    /**
     * "Chuyển vào Thùng rác": asks first when a chosen file is used somewhere, or (with
     * media.delete.own) when some chosen files stay because they are someone else's or in use.
     */
    public function trashPicked(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        $this->single = false;
        $this->askTrash();
    }

    public function confirmTrash(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        // only what this person may send: someone else's file (or one in use, for «own») stays
        // only the ones still out of the Trash go (and count): one by one, through TrashMedia
        $rows = app(TrashMedia::class)->trash(app(MediaDeleteRights::class)->trashable(Media::query()->whereIn('ulid', $this->targets()))->get());
        // the open file went to the Trash: its drawer goes with it, and the chosen files stay chosen
        if ($this->single) {
            $this->closeDetail();
        } else {
            $this->picked = [];
        }
        $this->askTrashOpen = false;
        $this->trashKept = 0;
        $this->trashGo = 0;
        $this->say(__('media.lib.trashed', ['n' => $rows->count()]));
        $this->undoTrash = Cast::strings($rows->pluck('ulid')->all()); // after say(): this toast's Hoàn tác
    }

    public function cancelTrash(): void
    {
        $this->askTrashOpen = false;
        $this->trashKept = 0;
        $this->trashGo = 0;
        $this->single = false;
    }

    /** The Trash question for targets(), or the move itself when there is nothing to ask. */
    private function askTrash(): void
    {
        $chosen = Media::query()->whereIn('ulid', $this->targets())->count();
        $mine = app(MediaDeleteRights::class)->trashable(Media::query()->whereIn('ulid', $this->targets()))->count();
        if ($mine === 0) {
            if ($chosen === 0 || MediaDeleteRights::any()) {
                $this->doneWithTargets(); // the chosen files are gone meanwhile: nothing left to send

                return;
            }
            $this->single = false;
            $this->say(__('media.lib.trash_none_mine'), 'error');

            return;
        }
        $this->trashKept = $chosen - $mine;
        $this->trashGo = $mine;
        $used = Media::query()->whereIn('ulid', $this->targets());
        app(MediaUsageLocator::class)->constrain($used, true);
        if ($this->trashKept > 0 || $used->exists()) {
            $this->askTrashOpen = true;

            return;
        }
        $this->confirmTrash();
    }

    /** "Hoàn tác" on the toast after a move to Trash. */
    public function undo(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        $n = app(TrashMedia::class)->restore(MediaDeleteRights::restorable(Media::onlyTrashed()->whereIn('ulid', $this->undoTrash))->get())->count();
        $this->undoTrash = [];
        $this->say(__('media.lib.restored', ['n' => $n]));
    }

    public function restorePicked(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        // with media.delete.own only what this person put in the Trash comes back out
        $n = app(TrashMedia::class)->restore(MediaDeleteRights::restorable(Media::onlyTrashed()->whereIn('ulid', $this->picked))->get())->count();
        $this->picked = [];
        $this->say(__('media.lib.restored', ['n' => $n]));
    }

    /**
     * Deleting for good is the website admin's right (media.purge); restore stays with the delete rights.
     * Support staff signed in for a member never delete the website's files for good (the member does it).
     */
    public function askPurge(bool $all = false): void
    {
        abort_unless(Gate::allows('media.purge'), 403);
        app(SupportSession::class)->forbid();

        $this->single = false;
        $this->purgeAll = $all;
        $this->askPurgeOpen = $all || $this->picked !== [];
    }

    public function cancelPurge(): void
    {
        $this->askPurgeOpen = false;
        $this->purgeAll = false;
        $this->single = false;
    }

    public function confirmPurge(): void
    {
        abort_unless(Gate::allows('media.purge'), 403);
        app(SupportSession::class)->forbid();

        $query = $this->purgeAll ? Media::onlyTrashed() : Media::onlyTrashed()->whereIn('ulid', $this->targets());
        // one by one, so the observer drops attachments and the stored file, a page of files at a time
        // (a whole Trash is never loaded at once); the message counts what was really deleted (a file
        // taken back out of the Trash meanwhile is kept)
        $done = app(PurgeMedia::class)->purgeAll($query);
        if ($this->single) {
            $this->closeDetail(); // the open file is gone for good
        } else {
            $this->picked = [];
        }
        $this->cancelPurge();
        $this->say(__('media.lib.purged', ['n' => $done['files'], 'size' => self::bytes($done['bytes'])]));
    }

    /*
    | The shared picker's detail panel is the editors'; this page opens a file in its own drawer
    | (WithMediaDetailDrawer), which saves every language at once.
    */

    public function openPickerDetail(string $ulid): void
    {
        $this->openDetail($ulid);
    }

    public function closePickerDetail(): void
    {
        $this->closeDetail();
    }

    public function savePickerDetailAlt(): void
    {
        abort(403);
    }

    /*
    | The shared picker's own endpoints take bigint ids and its own selection; this page addresses
    | files by ulid and folders by slug through the methods above, so they are closed here.
    */

    public function pickImage(int $mediaId): void
    {
        abort(403);
    }

    public function setPickerFolder(?int $folderId): void
    {
        abort(403);
    }

    public function updatedPickerFolderId(mixed $value): void
    {
        abort(403);
    }

    public function openPickerTrash(): void
    {
        abort(403);
    }

    public function togglePickerItem(int $mediaId): void
    {
        abort(403);
    }

    /**
     * «Từ đường link» on the upload panel: the previewed picture is copied into the library, into the
     * folder the panel names, under the same right as the device pane (media.upload). The shared
     * picker's other insert, its bigint-id selection, stays closed on this page.
     */
    public function insertPicked(): void
    {
        abort_unless($this->uploadOpen && $this->hasUrlPick() && Gate::allows('media.upload'), 403);

        $preview = $this->pickerUrlPreview ?? throw new LogicException('checked above');
        try {
            app(RegisterMediaUpload::class)->handle(
                $preview['tempKey'],
                $preview['filename'],
                is_int(Auth::id()) ? Auth::id() : null,
                mayRestore: Gate::allows('media.delete.any'),
                folderId: $this->uploadFolder()?->id,
            );
        } catch (MediaRejectedException $e) {
            $this->discardStagedUrlPreview(); // register may have dropped the staged bytes: a new preview is needed
            $this->addError('pickerUrlInput', $e->userMessage());

            return;
        } catch (RuntimeException $e) {
            report($e);
            $this->discardStagedUrlPreview();
            $this->addError('pickerUrlInput', __('media.err_unexpected'));

            return;
        }
        $this->reset('pickerUrlInput', 'pickerUrlPreview', 'pickerUrlSaveCopy');
        $this->pickerSource = null; // back to the sources, ready for the next file
        $this->onPickerMediaUploaded();
        $this->say(__('media.upload_success'));
    }

    public function pickerBulkDelete(): void
    {
        abort(403);
    }

    public function restorePickerSelection(): void
    {
        abort(403);
    }

    public function purgePickerSelection(): void
    {
        abort(403);
    }

    public function emptyPickerTrash(): void
    {
        abort(403);
    }

    public function pickerBulkMove(?int $folderId): void
    {
        abort(403);
    }

    public function createPickerFolder(): void
    {
        abort(403);
    }

    public function dismissToast(int $seq = 0): void
    {
        if ($seq === 0 || $seq === $this->toastSeq) {
            $this->toast = null;
            $this->undoTrash = [];
            $this->undoFolder = '';
            $this->undoReparent = '';
        }
    }

    public function render(): View
    {
        $this->clean();
        $canDelete = MediaDeleteRights::some();
        $canDeleteAny = MediaDeleteRights::any();
        $canPurge = Gate::allows('media.purge');
        $trash = $this->status === 'trash' && $canDelete;
        $directory = app(MediaLibraryDirectory::class);
        // the Filter sheet's «Thư mục» group shows where the list is (no folder chosen in the Trash)
        $this->folderPick = $trash ? '~trash' : $this->folder;

        $failed = false;
        $folders = new Collection;
        $counts = ['byFolder' => [], 'unfiled' => 0, 'all' => 0, 'trash' => 0, 'many' => false, 'uploaders' => []];
        $page = ['rows' => new Collection, 'older' => null, 'newer' => null, 'lost' => false];
        $totals = ['n' => 0, 'bytes' => 0];
        $current = null;
        $mineN = null;
        $chosenN = null;
        $people = new Collection;
        $byCounts = [];
        $whenCounts = [];
        $groupCounts = [];
        $useCounts = [];
        $usage = null;
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable
            DB::transaction(function () use ($directory, $canDelete, $canDeleteAny, $trash, &$folders, &$counts, &$page, &$totals, &$current, &$mineN, &$chosenN, &$people, &$byCounts, &$whenCounts, &$groupCounts, &$useCounts, &$usage): void {
                // «Dung lượng đã dùng»: the stored and Trash totals and the plan's ceiling, one statement
                $usage = app(MediaStorage::class)->usage();
                $folders = MediaFolder::inTreeOrder(MediaFolder::query()->orderBy('position')->orderBy('name')->orderBy('id')
                    ->get(['id', 'name', 'slug', 'parent_id', 'position']));
                $current = $this->currentFolder($folders);
                $counts = $directory->counts($canDelete, $this->tally);
                if ($canDelete && ! $canDeleteAny) {
                    // «own»: the Trash holds only this person's own files
                    $counts['trash'] = MediaDeleteRights::visibleTrash(Media::onlyTrashed())->count();
                }
                // «Người tạo» (offered from two uploaders on): the site's uploaders while the filters are open,
                // else the chosen ones alone, for their names; ulid only to the page
                $uploaderIds = array_keys($counts['uploaders']);
                if ($this->tally && count($uploaderIds) >= 2) {
                    $people = User::query()->whereIn('id', $uploaderIds)->get(['id', 'ulid', 'name']);
                } elseif ($this->by !== []) {
                    $people = $directory->uploadersAmong($this->by);
                }
                // a ulid of nobody who uploaded here filters nothing, so it neither stays chosen nor counts on «Lọc»
                $known = $people->pluck('ulid')->map(fn (mixed $u): string => Cast::string($u))->all();
                $this->by = array_values(array_filter($this->by, fn (string $u): bool => in_array($u, $known, true)));
                $filters = $this->filters($current, $folders, $trash, $people);
                $visible = function (Builder $query) use ($trash): Builder {
                    return $trash ? MediaDeleteRights::visibleTrash($query) : $query;
                };
                $query = $visible($directory->filtered($filters));
                $page = $directory->page($query, $this->sort, $this->per, $this->after, $this->before);
                $totals = $directory->totals($query);
                // while the filters are open, each group's own counts, read with every other condition on as the
                // board counts them (each one statement over the matching files)
                if ($this->tally) {
                    if (count($uploaderIds) >= 2) {
                        $byCounts = $directory->uploaderCounts($visible($directory->filtered(['by' => []] + $filters)));
                    }
                    $whenCounts = $directory->whenCounts($visible($directory->filtered(['since' => null, 'until' => null] + $filters)), $this->from, $this->to);
                    // «Loại tệp», «Định dạng» and «Tình trạng» (live files only), each without its own choices
                    $groupCounts['kinds'] = $directory->kindCounts($visible($directory->filtered(['kinds' => []] + $filters)));
                    $groupCounts['formats'] = $directory->formatCounts($visible($directory->filtered(['formats' => []] + $filters)));
                    if (! $trash) {
                        $groupCounts['uses'] = $directory->useCounts($directory->filtered(['uses' => []] + $filters));
                    }
                }
                if (! $trash) {
                    // «Dùng ở N nơi» on each tile and list row: one statement for the page
                    $useCounts = app(MediaUsageLocator::class)->countsFor($page['rows']);
                }
                if ($canDelete && ! $canDeleteAny && $this->picked !== []) {
                    // «own», with files chosen: how many of them the Trash button / Khôi phục acts on
                    $mineN = $trash
                        ? MediaDeleteRights::restorable(Media::onlyTrashed()->whereIn('ulid', $this->picked))->count()
                        : app(MediaDeleteRights::class)->trashable(Media::query()->whereIn('ulid', $this->picked))->count();
                    // the chosen files still there (one deleted meanwhile neither goes nor stays)
                    $chosenN = ($trash ? Media::onlyTrashed() : Media::query())->whereIn('ulid', $this->picked)->count();
                }
            });
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }
        if ($page['lost']) {
            // the row the cursor named is gone from this list: the first page is what is shown
            $this->after = $this->before = '';
            $this->pageNo = 1;
        }

        $purge = ['n' => 0, 'bytes' => 0];
        if ($this->askPurgeOpen && $canPurge && ! $failed) {
            $purge = $directory->totals($this->purgeAll ? Media::onlyTrashed() : Media::onlyTrashed()->whereIn('ulid', $this->targets()));
        }

        $drawer = $failed ? null : $this->detailView();
        $canUpload = Gate::allows('media.upload');
        $storage = $usage === null ? null : self::storageMeter($usage, MediaStorage::enforcing());
        // the full state's second button: the plans when the platform invites upgrades, else support
        $upsell = $storage !== null && $storage['level'] === 'full' && $canUpload && app(UpgradeInvitation::class)->isOn();

        // a folder's count holds its sub-folders' files, as its list does
        $treeN = [];
        foreach ($folders as $f) {
            $own = $counts['byFolder'][$f->id] ?? 0;
            $treeN[$f->id] = ($treeN[$f->id] ?? 0) + $own;
            if ($f->parent_id !== null) {
                $treeN[$f->parent_id] = ($treeN[$f->parent_id] ?? 0) + $own;
            }
        }

        $view = view('admin::livewire.media.library', [
            'failed' => $failed,
            'folders' => $folders,
            'treeN' => $treeN,
            'kidsOf' => $folders->whereNotNull('parent_id')->countBy('parent_id')->all(),
            'current' => $current,
            // where a file added now goes (the upload panel, the drop cover): none in the Trash, outside
            // a folder, or in a folder being deleted, as the upload endpoints decide
            'upFolder' => $trash || $current === null || self::deleting($current) ? null : $current,
            'counts' => $counts,
            'rows' => $page['rows'],
            'olderUlid' => $page['older'],
            'newerUlid' => $page['newer'],
            'totals' => $totals,
            'trash' => $trash,
            'canUpload' => $canUpload,
            'canDelete' => $canDelete,
            'storage' => $storage,
            'upsell' => $upsell,
            'supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address')),
            'canDeleteAny' => $canDeleteAny,
            // «own», with files chosen: how many of them the Trash button / Khôi phục acts on
            'mineN' => $mineN,
            'chosenN' => $chosenN,
            'canFolders' => Gate::allows('media.folders'),
            'canPurge' => $canPurge,
            // support staff signed in for a member: «Xóa vĩnh viễn» stays, greyed, with why (the server refuses it too)
            'support' => app(SupportSession::class)->active(),
            'urls' => app(MediaUrlResolver::class),
            'purge' => $purge,
            'retention' => Cast::int(config('media.trash_retention_days', 30), 30),
            // how many files the move / Trash dialog acts on: the open file alone, or the chosen ones
            'targetN' => count($this->targets()),
            'drawer' => $drawer,
            'uploaders' => $this->uploaderOptions($people, $this->tally ? $byCounts : null),
            'manyUploaders' => $counts['many'],
            'whenCounts' => $whenCounts,
            'groupCounts' => $groupCounts,
            'useCounts' => $useCounts,
        ]);
        $view->title(__('media.lib.title'));

        return $view;
    }

    /**
     * The «Dung lượng đã dùng» card from the library's totals (board 12): the level — `full` once the
     * ceiling is reached, `warn` from 80 %, else `ok` — the bar's percent (at most 100) and the texts in
     * megabytes to a tenth. As on the board, the level comes from the used figure the card shows (to a
     * tenth of a megabyte), and the percent is rounded down from it, so the bar never reads 80 while
     * the card is still `ok`, nor 100 before it says the room is used up. `full` (nothing more can be
     * uploaded) only while the storage switch refuses uploads: with it off a website above its plan is
     * told so on the `warn` card, never «chưa tải thêm được», which would not be true.
     *
     * @param  array{used: int, trash: int, limit: int}  $usage  bytes
     * @return array{level: 'ok'|'warn'|'full', pct: int, used: string, limit: string, status: string, trash: string}
     */
    public static function storageMeter(array $usage, bool $enforcing): array
    {
        $used = max(0, $usage['used']);
        $limit = max(0, $usage['limit']);
        // tenths of a megabyte, the figures the card prints
        $usedTenths = (int) round($used * 10 / 1048576);
        $limitTenths = (int) round($limit * 10 / 1048576);
        $over = $usedTenths >= $limitTenths;
        $level = $over && $enforcing ? 'full' : ($over || $usedTenths * 5 >= $limitTenths * 4 ? 'warn' : 'ok');
        $limitText = self::megabytes($limit, 0);
        $left = self::megabytes(max(0, $limit - $used));

        return [
            'level' => $level,
            'pct' => $over ? 100 : intdiv($usedTenths * 100, $limitTenths),
            'used' => self::megabytes($used),
            'limit' => $limitText,
            'status' => $over ? __('media.lib.st_used_all', ['limit' => $limitText])
                : __($level === 'warn' ? 'media.lib.st_warn' : 'media.lib.st_left', ['left' => $left]),
            'trash' => self::megabytes(max(0, $usage['trash'])),
        ];
    }

    /** Bytes as megabytes, to $decimals places at most, the Vietnamese way («312,4 MB», «500 MB»). */
    private static function megabytes(int $bytes, int $decimals = 1): string
    {
        $mb = round($bytes / 1048576, $decimals);
        $places = $mb === floor($mb) ? 0 : $decimals;

        return number_format($mb, $places, ',', '.').' MB';
    }

    /** "12,3 MB" — the size of files as people read it, Vietnamese decimal comma. */
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB';
        }

        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }

    /** A folder of this site by its slug (the scope and RLS keep another site's out), or null. */
    private static function folderBySlug(string $slug): ?MediaFolder
    {
        return $slug === '' ? null : MediaFolder::query()->where('slug', $slug)->first();
    }

    /** Why a folder name cannot be used (empty, too long, already in use among the folders of that parent), or null when it can. */
    private static function folderNameError(string $name, ?int $except = null, ?int $parentId = null): ?string
    {
        if ($name === '') {
            return Cast::string(__('media.lib.folder_name_required'));
        }
        if (mb_strlen($name) > self::FOLDER_NAME_MAX) {
            return Cast::string(__('media.lib.folder_name_long', ['max' => self::FOLDER_NAME_MAX]));
        }
        $taken = MediaFolder::query()->where('parent_id', $parentId)->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))->exists();

        return $taken ? Cast::string(__('media.lib.folder_name_taken')) : null;
    }

    /** The name itself when no folder of that parent uses it, else the first free "Tên (2)", "Tên (3)"… within the length limit. */
    private static function freeFolderName(string $name, ?int $parentId = null): string
    {
        $candidate = $name;
        for ($n = 2; self::folderNameError($candidate, null, $parentId) !== null && $n < 1000; $n++) {
            $suffix = ' ('.$n.')';
            $candidate = mb_substr($name, 0, self::FOLDER_NAME_MAX - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /** More files in these folders (Trash included) than Hoàn tác keeps — answered without counting past the limit. */
    /** @param  list<int>  $folderIds */
    private static function tooManyForUndo(array $folderIds): bool
    {
        return Media::withTrashed()->whereIn('folder_id', $folderIds)
            ->offset(Cast::int(config('media.folder_undo_max_files', 100000)))->exists();
    }

    /**
     * The folder a file added now goes into, as the upload panel names it: the one in view, unless
     * that is the Trash, all files, the unsorted ones or a folder being deleted (the upload endpoints
     * leave a file unsorted then too).
     */
    private function uploadFolder(): ?MediaFolder
    {
        if (($this->status === 'trash' && MediaDeleteRights::some()) || $this->folder === '' || $this->folder === '-') {
            return null;
        }
        $folder = self::folderBySlug($this->folder);

        return $folder !== null && ! self::deleting($folder) ? $folder : null;
    }

    /** Whether a queued job is deleting this folder: nothing new goes under it. */
    private static function deleting(MediaFolder $folder): bool
    {
        return DeleteLargeMediaFolder::pending(app(CurrentSite::class)->id(), $folder->id);
    }

    /** @return list<int> the folder and its sub-folders */
    private static function familyIds(int $folderId): array
    {
        return array_values(array_merge([$folderId], MediaFolder::query()->where('parent_id', $folderId)
            ->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all()));
    }

    /**
     * Gives the folders of one parent the places 1..n in their order; $folderId (when given) goes at
     * place $at (clamped). Runs under the folder-list lock.
     */
    private static function placeAmongSiblings(?int $parentId, ?int $folderId = null, int $at = PHP_INT_MAX): void
    {
        $order = MediaFolder::query()->where('parent_id', $parentId)
            ->when($folderId !== null, fn (Builder $q) => $q->whereKeyNot($folderId))
            ->orderBy('position')->orderBy('name')->orderBy('id')->get(['id', 'position']);
        $ids = $order->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all();
        $had = $order->pluck('position', 'id')->all();
        if ($folderId !== null) {
            array_splice($ids, max(0, min($at - 1, count($ids))), 0, [$folderId]);
        }
        foreach ($ids as $i => $id) {
            if (($had[$id] ?? null) !== $i + 1) {
                MediaFolder::query()->whereKey($id)->update(['position' => $i + 1]);
            }
        }
    }

    /** The folder's place (1..n) among the folders of its parent, as the folder column shows them — not its position number, which may have gaps. */
    private static function slotOf(MediaFolder $folder): int
    {
        return MediaFolder::query()->where('parent_id', $folder->parent_id)
            ->where(fn (Builder $q) => $q->where('position', '<', $folder->position)
                ->orWhere(fn (Builder $q) => $q->where('position', $folder->position)->where('name', '<', $folder->name))
                ->orWhere(fn (Builder $q) => $q->where('position', $folder->position)->where('name', $folder->name)->where('id', '<', $folder->id)))
            ->count() + 1;
    }

    /**
     * One folder kept for Hoàn tác, checked, or null when the entry is not one.
     *
     * @param  array<mixed>  $kept
     * @return array{name: string, slug: string, position: int, ids: string}|null
     */
    private static function keptFolder(array $kept): ?array
    {
        if (! is_string($kept['name'] ?? null) || ! is_string($kept['slug'] ?? null) || ! is_string($kept['ids'] ?? null)) {
            return null;
        }

        return ['name' => $kept['name'], 'slug' => $kept['slug'], 'position' => Cast::int($kept['position'] ?? 1), 'ids' => $kept['ids']];
    }

    /**
     * Makes a kept folder again under $parentId and puts back the files that are still in no folder
     * (one moved elsewhere meanwhile stays where it was put).
     *
     * @param  array{name: string, slug: string, position: int, ids: string}  $kept
     */
    private static function bringBack(array $kept, ?int $parentId): MediaFolder
    {
        $folder = MediaFolder::query()->create([
            'name' => self::freeFolderName($kept['name'], $parentId),
            'slug' => (new MediaFolder)->uniqueSlug($kept['slug'], $kept['name']),
            'parent_id' => $parentId,
            'position' => $kept['position'],
        ]);
        /** @var list<int> $ids */
        $ids = $kept['ids'] === '' ? [] : array_values(unpack('q*', $kept['ids']) ?: []);
        foreach (array_chunk($ids, 1000) as $chunk) {
            Media::withTrashed()->whereIn('id', $chunk)->whereNull('folder_id')->update(['folder_id' => $folder->id]);
        }

        return $folder;
    }

    /** Why «Chuyển thư mục…» cannot be used on this folder (shown under the menu item), or null. */
    private static function reparentBlocked(MediaFolder $folder): ?string
    {
        if ($folder->parent_id !== null) {
            return null; // a sub-folder can always go up to the top level
        }
        if (MediaFolder::query()->where('parent_id', $folder->id)->exists()) {
            return Cast::string(__('media.lib.reparent_why_kids'));
        }

        return MediaFolder::query()->whereNull('parent_id')->whereKeyNot($folder->id)->exists()
            ? null : Cast::string(__('media.lib.reparent_why_none'));
    }

    /**
     * More files (Trash included) in these folders than one web request moves back to no folder —
     * such a folder is emptied in batches off the request (DeleteLargeMediaFolder), without Hoàn tác.
     *
     * @param  list<int>  $folderIds
     */
    private static function tooManyInline(array $folderIds): bool
    {
        return Media::withTrashed()->whereIn('folder_id', $folderIds)
            ->offset(Cast::int(config('media.folder_delete_inline_max_files', 20000)))->exists();
    }

    /** The cache key of a folder delete kept for Hoàn tác: this site's, this member's, this delete's. */
    private static function folderUndoKey(string $token): string
    {
        return 'media:folder-undo:'.app(CurrentSite::class)->id().':'.Cast::int(auth()->id()).':'.$token;
    }

    /**
     * Deletes the folder and its sub-folders (the parent key's CASCADE): their files (live and in the
     * Trash) go back to no folder through media's foreign key. What Hoàn tác needs — each folder's
     * name, slug, place, parent and files' ids — is kept server side for a few minutes; the page holds
     * only its random key. A folder with more files than one request moves is left in place and
     * emptied in batches by a queued job, which then deletes it with its sub-folders.
     */
    private function deleteFolder(MediaFolder $folder): void
    {
        // The folder rows locked first: an upload into one (its foreign-key check) waits for this
        // delete, or committed before it and is counted; a second delete of the same folder finds it gone.
        $done = DB::transaction(function () use ($folder): ?array {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $row = MediaFolder::query()->whereKey($folder->id)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $kids = MediaFolder::query()->where('parent_id', $row->id)->orderBy('position')->orderBy('name')->orderBy('id')
                ->lockForUpdate()->get(['id', 'name', 'slug', 'position']);
            $family = array_values(array_merge([$row->id], $kids->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all()));
            if (self::tooManyInline($family)) {
                // no count of a folder this big inside the request: the job says nothing per file either
                $siteId = app(CurrentSite::class)->id();
                DB::afterCommit(fn () => DeleteLargeMediaFolder::markPending($siteId, $row->id)); // only once the delete is decided
                DeleteLargeMediaFolder::dispatch(app(CurrentSite::class)->id(), $row->id); // once this commits

                return ['live' => 0, 'files' => null, 'row' => $row, 'kids' => $kids, 'parent' => '', 'slot' => 0, 'later' => true];
            }
            $live = Media::query()->whereIn('folder_id', $family)->count();
            $files = null;
            if (! self::tooManyForUndo($family)) {
                $files = [];
                foreach (Media::withTrashed()->whereIn('folder_id', $family)->toBase()->get(['id', 'folder_id']) as $file) {
                    $files[Cast::int($file->folder_id)][] = Cast::int($file->id);
                }
            }
            $parent = $row->parent_id === null ? '' : Cast::string(MediaFolder::query()->whereKey($row->parent_id)->value('slug'));
            $slot = self::slotOf($row);
            $row->delete();

            return ['live' => $live, 'files' => $files, 'row' => $row, 'kids' => $kids, 'parent' => $parent, 'slot' => $slot, 'later' => false];
        });
        if ($done === null) {
            return;
        }
        ['live' => $live, 'files' => $files, 'row' => $folder, 'kids' => $kids, 'parent' => $parent, 'slot' => $slot, 'later' => $later] = $done;

        $pack = fn (int $id): string => ($files[$id] ?? []) === [] ? '' : pack('q*', ...$files[$id]);
        $token = '';
        if ($files !== null) {
            $token = Str::random(40);
            Cache::put(self::folderUndoKey($token), [
                'name' => $folder->name,
                'slug' => (string) $folder->slug,
                'position' => $slot,
                'parent' => $parent,
                'parentId' => $folder->parent_id,
                'ids' => $pack($folder->id),
                'kids' => $kids->map(fn (MediaFolder $k): array => ['name' => $k->name, 'slug' => (string) $k->slug, 'position' => 0, 'ids' => $pack($k->id)])->all(),
            ], now()->addMinutes(self::FOLDER_UNDO_MINUTES));
        }
        $name = $folder->name;
        $slugs = array_merge([(string) $folder->slug], $kids->map(fn (MediaFolder $k): string => (string) $k->slug)->all());

        if (in_array($this->folder, $slugs, true)) {
            $this->setFolder('');
        }
        if (in_array($this->renaming, $slugs, true)) {
            $this->cancelRename();
        }
        $this->say(match (true) {
            $later => __('media.lib.folder_deleting', ['name' => $name]),
            $files === null => __('media.lib.folder_deleted_no_undo', ['name' => $name, 'n' => number_format($live, 0, ',', '.')]),
            $live > 0 => __('media.lib.folder_deleted_n', ['name' => $name, 'n' => number_format($live, 0, ',', '.')]),
            default => __('media.lib.folder_deleted', ['name' => $name]),
        });
        $this->undoFolder = $token; // after say(): this toast's Hoàn tác
    }

    private function canPick(): bool
    {
        return $this->status === 'trash' ? MediaDeleteRights::some() : (Gate::allows('media.upload') || MediaDeleteRights::some());
    }

    /** @return Builder<Media> the files of the place in view, without conditions */
    private function placeQuery(): Builder
    {
        $trash = $this->status === 'trash' && MediaDeleteRights::some();

        return $trash ? MediaDeleteRights::visibleTrash(Media::onlyTrashed()) : Media::query();
    }

    /** @param  Collection<int, MediaFolder>  $folders */
    private function currentFolder(Collection $folders): ?MediaFolder
    {
        if ($this->folder === '' || $this->folder === '-') {
            return null;
        }
        $found = $folders->firstWhere('slug', $this->folder);
        if (! $found instanceof MediaFolder) {
            $this->folder = ''; // a folder that went away (or never was this site's): back to all files

            return null;
        }

        return $found;
    }

    /**
     * The «Người tạo» options (board 12): the site's uploaders, the member first as «Của tôi (…)», the
     * others by name; each with how many files match with every other condition on, while the filters
     * are open ($counts null: no count, the options are then only the chosen people, for their names).
     *
     * @param  Collection<int, User>  $people
     * @param  array<int, int>|null  $counts
     * @return list<array{value: string, label: string, count?: int}>
     */
    private function uploaderOptions(Collection $people, ?array $counts): array
    {
        $me = Auth::id();
        $sorted = $people->sort(fn (User $a, User $b): int => [$a->id !== $me, mb_strtolower((string) $a->name), (string) $a->ulid]
            <=> [$b->id !== $me, mb_strtolower((string) $b->name), (string) $b->ulid]);
        $out = [];
        foreach ($sorted as $person) {
            $out[] = [
                'value' => (string) $person->ulid,
                'label' => $person->id === $me ? Cast::string(__('entries.f_mine', ['name' => $person->name])) : (string) $person->name,
            ] + ($counts === null ? [] : ['count' => $counts[$person->id] ?? 0]);
        }

        return $out;
    }

    /**
     * @param  Collection<int, MediaFolder>  $folders
     * @param  Collection<int, User>  $people  the site's uploaders read for «Người tạo» (a chosen ulid outside them is dropped)
     * @return array{folders: list<int>|null, unfiled: bool, trash: bool, search: string, kinds: list<string>, formats: list<string>, uses: list<string>, by: list<int>, since: CarbonImmutable|null, until: CarbonImmutable|null}
     */
    private function filters(?MediaFolder $current, Collection $folders, bool $trash, Collection $people): array
    {
        [$since, $until] = MediaLibraryDirectory::window($this->when, $this->from, $this->to);

        return [
            // a folder shows its sub-folders' files too
            'folders' => $current === null ? null : array_values(array_merge([$current->id], $folders->where('parent_id', $current->id)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all())),
            'unfiled' => $this->folder === '-',
            'trash' => $trash,
            'search' => $this->search,
            'kinds' => $this->kinds,
            'formats' => $this->formats,
            'uses' => $trash ? [] : $this->uses,
            'by' => array_values(array_map(fn (mixed $id): int => Cast::int($id), $people->whereIn('ulid', $this->by)->pluck('id')->all())),
            'since' => $since,
            'until' => $until,
        ];
    }

    /** Values that come back from the browser are trimmed to the known ones. */
    private function clean(): void
    {
        $this->kinds = array_values(array_intersect(array_filter($this->kinds, 'is_string'), MediaLibraryDirectory::KINDS));
        $this->formats = array_values(array_intersect(array_filter($this->formats, 'is_string'), array_keys(MediaLibraryDirectory::FORMATS)));
        $this->uses = array_values(array_intersect(array_filter($this->uses, 'is_string'), MediaLibraryDirectory::USES));
        $this->by = array_values(array_unique(array_filter(Cast::strings($this->by), fn (string $u): bool => self::ulidOr($u) !== '')));
        $this->from = self::dayOr($this->from);
        $this->to = self::dayOr($this->to);
        if (! in_array($this->when, MediaLibraryDirectory::WHEN, true)) {
            $this->when = '';
        }
        if ($this->when !== MediaLibraryDirectory::RANGE) {
            $this->from = $this->to = '';
        }
        if ($this->from !== '' && $this->to !== '' && $this->from > $this->to) {
            // «Từ» after «Đến»: the two days swap, so the range lists what they span rather than nothing
            [$this->from, $this->to] = [$this->to, $this->from];
        }
        $this->picked = array_values(array_filter(Cast::strings($this->picked), fn (string $u): bool => self::ulidOr($u) !== ''));
        if (! array_key_exists($this->sort, MediaLibraryDirectory::SORTS)) {
            $this->sort = 'new';
        }
        if (! in_array($this->per, MediaLibraryDirectory::PER_PAGE, true)) {
            $this->per = 24;
        }
        if ($this->status !== 'trash' || ! MediaDeleteRights::some()) {
            $this->status = 'live';
        }
        if (mb_strlen($this->search) > MediaLibraryDirectory::SEARCH_MAX) {
            $this->search = mb_substr($this->search, 0, MediaLibraryDirectory::SEARCH_MAX);
        }
        $this->after = self::ulidOr($this->after);
        $this->before = self::ulidOr($this->before);
        $this->pageNo = max(1, $this->pageNo);
    }

    private function restart(): void
    {
        $this->after = $this->before = '';
        $this->pageNo = 1;
        $this->picked = [];
    }

    /** A new toast; its Hoàn tác, if any, is set by the caller after this (an older one never carries over). */
    private function say(string $message, string $tone = 'success'): void
    {
        $this->undoTrash = [];
        $this->undoFolder = '';
        $this->undoReparent = '';
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? $value : '';
    }

    /** A real calendar day as the date field sends it (Y-m-d), else ''. */
    private static function dayOr(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }
}
