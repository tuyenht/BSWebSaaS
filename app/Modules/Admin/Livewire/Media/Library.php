<?php

namespace App\Modules\Admin\Livewire\Media;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\MediaLibraryDirectory;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * The media library page (/admin/media): a folder column, the tools row (search, filters, sort,
 * grid/list), the files page by page from a cursor, selection with move / Trash / restore / purge,
 * and the upload panel. Files and folders are addressed by ulid and slug only — never a bigint id.
 * Read is gated by media.view (re-checked in boot(): the livewire/update endpoint bypasses route
 * middleware); every action re-checks its own right: making, renaming, reordering or deleting a
 * folder needs media.folders and deleting for good needs media.purge (both the website admin's),
 * moving files into a folder stays with media.upload and the Trash with media.delete. The upload
 * panes and the detail panel are the shared picker's (WithMediaLibraryPicker in manage mode).
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use WithMediaLibraryPicker;

    /** '' = all files, '-' = files in no folder, else a folder slug. */
    public string $folder = '';

    /** 'live' or 'trash' (the Trash needs media.delete, checked wherever it is read). */
    public string $status = 'live';

    public string $search = '';

    /** @var list<string> */
    public array $kinds = [];

    /** @var list<string> */
    public array $formats = [];

    /** @var list<string> */
    public array $uses = [];

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

    public bool $folderSheetOpen = false;

    public bool $moveOpen = false;

    /** '' = not chosen yet, '-' = no folder, else a folder slug. */
    public string $moveTo = '';

    public bool $askTrashOpen = false;

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
    private const array LIST_PROPS = ['search', 'kinds', 'formats', 'uses', 'sort', 'per', 'status', 'folder'];

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
        $this->clean();
        if (in_array($name, self::LIST_PROPS, true)) {
            $this->restart();
        }
        if ($name === 'newFolderName') {
            $this->resetErrorBag('newFolderName');
        }
    }

    public function setFolder(string $key): void
    {
        $toTrash = $key === '~trash' && Gate::allows('media.delete');
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
        $this->kinds = $this->formats = $this->uses = [];
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

    public function openUpload(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->uploadOpen = true;
        $this->pickerSource = null;
        $this->closePickerDetail();
    }

    public function closeUpload(): void
    {
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

    public function openNewFolder(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->newFolderOpen = true;
        $this->newFolderName = '';
        $this->folderSheetOpen = false;
        $this->resetErrorBag('newFolderName');
    }

    public function cancelNewFolder(): void
    {
        $this->newFolderOpen = false;
        $this->newFolderName = '';
        $this->resetErrorBag('newFolderName');
    }

    public function createFolder(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $name = trim($this->newFolderName);
        $this->resetErrorBag('newFolderName');
        $error = self::folderNameError($name);
        if ($error !== null) {
            $this->addError('newFolderName', $error);

            return;
        }

        // under the site's folder-list lock, like every folder write: the new last place is nobody else's
        $folder = DB::transaction(function () use ($name): MediaFolder {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $maxPosition = MediaFolder::query()->max('position');

            return MediaFolder::query()->create([
                'name' => $name,
                'slug' => (new MediaFolder)->uniqueSlug($name, $name),
                'position' => (is_numeric($maxPosition) ? (int) $maxPosition : 0) + 1,
            ]);
        });

        $this->cancelNewFolder();
        $this->setFolder((string) $folder->slug);
        $this->say(__('media.lib.folder_created', ['name' => $name]));
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

        $folder = self::folderBySlug($this->renaming);
        if ($folder === null) {
            $this->cancelRename(); // gone meanwhile, or never this site's

            return;
        }
        $name = trim($this->renameName);
        $this->resetErrorBag('renameName');
        $error = self::folderNameError($name, $folder->id);
        if ($error !== null) {
            $this->addError('renameName', $error);

            return;
        }

        $old = $folder->name;
        $this->cancelRename();
        if ($old === $name) {
            return;
        }
        $folder->update(['name' => $name]);
        $this->say(__('media.lib.folder_renamed', ['old' => $old, 'name' => $name]));
    }

    /** "Chuyển lên" (-1) / "Chuyển xuống" (+1): swaps the folder with its neighbour in the list. */
    public function moveFolder(string $slug, int $step): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = false;
        $step = $step < 0 ? -1 : 1;
        // the folders locked for the swap, so two people moving at once never leave equal positions
        $moved = DB::transaction(function () use ($slug, $step): bool {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            /** @var list<MediaFolder> $order */
            $order = MediaFolder::query()->orderBy('position')->orderBy('name')->orderBy('id')
                ->lockForUpdate()->get(['id', 'slug', 'name', 'position'])->all();
            $at = null;
            foreach ($order as $i => $folder) {
                if ((string) $folder->slug === $slug) {
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
     * "Xóa thư mục": a folder with no files goes at once (with Hoàn tác); with files it asks first.
     * Files are never deleted with their folder — they go back to "Chưa phân loại".
     */
    public function askDeleteFolder(string $slug): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $this->folderSheetOpen = false;
        $folder = self::folderBySlug($slug);
        if ($folder === null) {
            return;
        }
        $noUndo = self::tooManyForUndo($folder->id);
        if (! $noUndo && ! Media::query()->where('folder_id', $folder->id)->exists()) {
            $this->deleteFolder($folder);

            return;
        }
        $this->folderToDelete = (string) $folder->slug;
        $this->folderNoUndo = $noUndo;
        $this->askFolderOpen = true;
    }

    public function cancelDeleteFolder(): void
    {
        $this->askFolderOpen = false;
        $this->folderToDelete = '';
        $this->folderNoUndo = false;
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
     * "Hoàn tác" after a folder delete: the folder comes back at its place in the list with the files
     * that were in it. A folder of the same name made meanwhile keeps its name; the one coming back
     * takes the first free "Tên (2)", "Tên (3)"…, so no file is left out of its folder.
     */
    public function undoFolderDelete(): void
    {
        abort_unless(Gate::allows('media.folders'), 403);

        $key = self::folderUndoKey($this->undoFolder);
        $kept = $this->undoFolder === '' ? null : Cache::pull($key);
        $this->undoFolder = '';
        if (! is_array($kept) || ! is_string($kept['name'] ?? null) || ! is_string($kept['slug'] ?? null) || ! is_string($kept['ids'] ?? null)) {
            $this->say(__('media.lib.folder_undo_gone'), 'error');

            return;
        }

        try {
            $folder = DB::transaction(function () use ($kept): MediaFolder {
                MediaFolder::lockTree(app(CurrentSite::class)->id());
                // the list locked like a move: the folders from its old place down step one place down
                $position = max(1, Cast::int($kept['position'] ?? 1));
                // FOR NO KEY UPDATE: a file moved into a folder meanwhile (its key check) is not blocked
                $order = MediaFolder::query()->orderBy('position')->orderBy('name')->orderBy('id')
                    ->lock('for no key update')->get(['id', 'position']);
                $position = min($position, $order->count() + 1);
                foreach ($order->values() as $i => $other) {
                    $want = $i + 1 < $position ? $i + 1 : $i + 2;
                    if ($other->position !== $want) {
                        MediaFolder::query()->whereKey($other->id)->update(['position' => $want]);
                    }
                }

                $folder = MediaFolder::query()->create([
                    'name' => self::freeFolderName($kept['name']),
                    'slug' => (new MediaFolder)->uniqueSlug($kept['slug'], $kept['name']),
                    'position' => $position,
                ]);
                /** @var list<int> $ids */
                $ids = $kept['ids'] === '' ? [] : array_values(unpack('q*', $kept['ids']) ?: []);
                foreach (array_chunk($ids, 1000) as $chunk) {
                    // only files still in no folder: one moved elsewhere meanwhile stays where it was put
                    Media::withTrashed()->whereIn('id', $chunk)->whereNull('folder_id')->update(['folder_id' => $folder->id]);
                }

                return $folder;
            });
        } catch (Throwable $e) {
            Cache::put($key, $kept, now()->addMinutes(self::FOLDER_UNDO_MINUTES)); // the next try still has it

            throw $e;
        }

        $this->setFolder((string) $folder->slug);
        $this->say(__('media.lib.folder_back', ['name' => $folder->name]));
    }

    public function openMove(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->moveTo = '';
        $this->moveOpen = $this->picked !== [];
    }

    public function cancelMove(): void
    {
        $this->moveOpen = false;
        $this->moveTo = '';
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

        $n = Media::query()->whereIn('ulid', $this->picked)->update(['folder_id' => $target?->id]);
        $this->picked = [];
        $this->cancelMove();
        $this->say(__('media.lib.moved', ['n' => $n, 'folder' => $target !== null ? $target->name : __('media.lib.unfiled')]));
    }

    /** "Chuyển vào Thùng rác": asks first only when a chosen file is used somewhere. */
    public function trashPicked(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        $query = Media::query()->whereIn('ulid', $this->picked);
        app(MediaUsageLocator::class)->constrain($query, true);
        if ($query->exists()) {
            $this->askTrashOpen = true;

            return;
        }
        $this->confirmTrash();
    }

    public function confirmTrash(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        $rows = Media::query()->whereIn('ulid', $this->picked)->get();
        $rows->each->delete(); // one by one, so the observers fire
        $this->picked = [];
        $this->askTrashOpen = false;
        $this->say(__('media.lib.trashed', ['n' => $rows->count()]));
        $this->undoTrash = Cast::strings($rows->pluck('ulid')->all()); // after say(): this toast's Hoàn tác
    }

    public function cancelTrash(): void
    {
        $this->askTrashOpen = false;
    }

    /** "Hoàn tác" on the toast after a move to Trash. */
    public function undo(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        $n = Media::onlyTrashed()->whereIn('ulid', $this->undoTrash)->get()->each->restore()->count();
        $this->undoTrash = [];
        $this->say(__('media.lib.restored', ['n' => $n]));
    }

    public function restorePicked(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        $n = Media::onlyTrashed()->whereIn('ulid', $this->picked)->get()->each->restore()->count();
        $this->picked = [];
        $this->say(__('media.lib.restored', ['n' => $n]));
    }

    /** Deleting for good is the website admin's right (media.purge); restore stays with media.delete. */
    public function askPurge(bool $all = false): void
    {
        abort_unless(Gate::allows('media.purge'), 403);

        $this->purgeAll = $all;
        $this->askPurgeOpen = $all || $this->picked !== [];
    }

    public function cancelPurge(): void
    {
        $this->askPurgeOpen = false;
        $this->purgeAll = false;
    }

    public function confirmPurge(): void
    {
        abort_unless(Gate::allows('media.purge'), 403);

        $query = $this->purgeAll ? Media::onlyTrashed() : Media::onlyTrashed()->whereIn('ulid', $this->picked);
        $rows = $query->get();
        $bytes = Cast::int($rows->sum('size'));
        $rows->each->forceDelete(); // one by one, so the observer drops attachments and the stored file
        $this->picked = [];
        $this->cancelPurge();
        $this->say(__('media.lib.purged', ['n' => $rows->count(), 'size' => self::bytes($bytes)]));
    }

    public function openDetail(string $ulid): void
    {
        $this->openPickerDetail($ulid);
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

    public function insertPicked(): void
    {
        abort(403);
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
        }
    }

    public function render(): View
    {
        $this->clean();
        $canDelete = Gate::allows('media.delete');
        $canPurge = Gate::allows('media.purge');
        $trash = $this->status === 'trash' && $canDelete;
        $directory = app(MediaLibraryDirectory::class);

        $failed = false;
        $folders = new Collection;
        $counts = ['byFolder' => [], 'unfiled' => 0, 'all' => 0, 'trash' => 0];
        $page = ['rows' => new Collection, 'older' => null, 'newer' => null, 'lost' => false];
        $totals = ['n' => 0, 'bytes' => 0];
        $current = null;
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable
            DB::transaction(function () use ($directory, $canDelete, $trash, &$folders, &$counts, &$page, &$totals, &$current): void {
                $folders = MediaFolder::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'slug']);
                $current = $this->currentFolder($folders);
                $counts = $directory->counts($canDelete);
                $query = $directory->filtered($this->filters($current, $trash));
                $page = $directory->page($query, $this->sort, $this->per, $this->after, $this->before);
                $totals = $directory->totals($query);
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
            $purge = $directory->totals($this->purgeAll ? Media::onlyTrashed() : Media::onlyTrashed()->whereIn('ulid', $this->picked));
        }

        $detail = $this->pickerDetailMedia($this->pickerDetailUlid, withFolder: true);
        $uploader = null;
        if ($detail?->created_by !== null) {
            $name = User::query()->whereKey($detail->created_by)->value('name');
            $uploader = is_string($name) ? $name : null;
        }

        $view = view('admin::livewire.media.library', [
            'failed' => $failed,
            'folders' => $folders,
            'current' => $current,
            'counts' => $counts,
            'rows' => $page['rows'],
            'olderUlid' => $page['older'],
            'newerUlid' => $page['newer'],
            'totals' => $totals,
            'trash' => $trash,
            'canUpload' => Gate::allows('media.upload'),
            'canDelete' => $canDelete,
            'canFolders' => Gate::allows('media.folders'),
            'canPurge' => $canPurge,
            'urls' => app(MediaUrlResolver::class),
            'purge' => $purge,
            'retention' => Cast::int(config('media.trash_retention_days', 30), 30),
            'mp' => [
                'detail' => $detail,
                'detailUsages' => $detail === null ? [] : app(MediaUsageLocator::class)->usagesFor($detail, $this->pickerContentLocale()),
                'detailUploader' => $uploader,
                'urls' => app(MediaUrlResolver::class),
            ],
        ]);
        $view->title(__('media.lib.title'));

        return $view;
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

    /** Why a folder name cannot be used (empty, too long, already in use here), or null when it can. */
    private static function folderNameError(string $name, ?int $except = null): ?string
    {
        if ($name === '') {
            return Cast::string(__('media.lib.folder_name_required'));
        }
        if (mb_strlen($name) > self::FOLDER_NAME_MAX) {
            return Cast::string(__('media.lib.folder_name_long', ['max' => self::FOLDER_NAME_MAX]));
        }
        $taken = MediaFolder::query()->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))->exists();

        return $taken ? Cast::string(__('media.lib.folder_name_taken')) : null;
    }

    /** The name itself when no folder here uses it, else the first free "Tên (2)", "Tên (3)"… within the length limit. */
    private static function freeFolderName(string $name): string
    {
        $candidate = $name;
        for ($n = 2; self::folderNameError($candidate) !== null && $n < 1000; $n++) {
            $suffix = ' ('.$n.')';
            $candidate = mb_substr($name, 0, self::FOLDER_NAME_MAX - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    /** More files (Trash included) than Hoàn tác keeps — answered without counting past the limit. */
    private static function tooManyForUndo(int $folderId): bool
    {
        return Media::withTrashed()->where('folder_id', $folderId)
            ->offset(Cast::int(config('media.folder_undo_max_files', 100000)))->exists();
    }

    /** The cache key of a folder delete kept for Hoàn tác: this site's, this member's, this delete's. */
    private static function folderUndoKey(string $token): string
    {
        return 'media:folder-undo:'.app(CurrentSite::class)->id().':'.Cast::int(auth()->id()).':'.$token;
    }

    /**
     * Deletes the folder: its files (live and in the Trash) go back to no folder through the foreign
     * key. What Hoàn tác needs — the name, slug, place and the files' ids — is kept server side for a
     * few minutes; the page holds only its random key.
     */
    private function deleteFolder(MediaFolder $folder): void
    {
        // The folder row locked first: an upload into it (its foreign-key check) waits for this delete,
        // or committed before it and is counted; a second delete of the same folder finds it gone.
        $done = DB::transaction(function () use ($folder): ?array {
            MediaFolder::lockTree(app(CurrentSite::class)->id());
            $row = MediaFolder::query()->whereKey($folder->id)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $live = Media::query()->where('folder_id', $row->id)->count();
            $ids = self::tooManyForUndo($row->id) ? null : Media::withTrashed()->where('folder_id', $row->id)->pluck('id')
                ->map(fn (mixed $id): int => Cast::int($id))->all();
            $row->delete();

            return ['live' => $live, 'ids' => $ids, 'row' => $row];
        });
        if ($done === null) {
            return;
        }
        ['live' => $live, 'ids' => $ids, 'row' => $folder] = $done;

        $token = '';
        if ($ids !== null) {
            $token = Str::random(40);
            Cache::put(self::folderUndoKey($token), [
                'name' => $folder->name,
                'slug' => (string) $folder->slug,
                'position' => $folder->position,
                'ids' => $ids === [] ? '' : pack('q*', ...$ids),
            ], now()->addMinutes(self::FOLDER_UNDO_MINUTES));
        }
        $name = $folder->name;
        $wasOpen = $this->folder === (string) $folder->slug;

        if ($wasOpen) {
            $this->setFolder('');
        }
        if ($this->renaming === (string) $folder->slug) {
            $this->cancelRename();
        }
        $this->say(match (true) {
            $ids === null => __('media.lib.folder_deleted_no_undo', ['name' => $name, 'n' => number_format($live, 0, ',', '.')]),
            $live > 0 => __('media.lib.folder_deleted_n', ['name' => $name, 'n' => number_format($live, 0, ',', '.')]),
            default => __('media.lib.folder_deleted', ['name' => $name]),
        });
        $this->undoFolder = $token; // after say(): this toast's Hoàn tác
    }

    private function canPick(): bool
    {
        return $this->status === 'trash' ? Gate::allows('media.delete') : (Gate::allows('media.upload') || Gate::allows('media.delete'));
    }

    /** @return Builder<Media> the files of the place in view, without conditions */
    private function placeQuery(): Builder
    {
        $trash = $this->status === 'trash' && Gate::allows('media.delete');

        return $trash ? Media::onlyTrashed() : Media::query();
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

    /** @return array{folder: int|null, unfiled: bool, trash: bool, search: string, kinds: list<string>, formats: list<string>, uses: list<string>} */
    private function filters(?MediaFolder $current, bool $trash): array
    {
        return [
            'folder' => $current?->id,
            'unfiled' => $this->folder === '-',
            'trash' => $trash,
            'search' => $this->search,
            'kinds' => $this->kinds,
            'formats' => $this->formats,
            'uses' => $trash ? [] : $this->uses,
        ];
    }

    /** Values that come back from the browser are trimmed to the known ones. */
    private function clean(): void
    {
        $this->kinds = array_values(array_intersect(array_filter($this->kinds, 'is_string'), MediaLibraryDirectory::KINDS));
        $this->formats = array_values(array_intersect(array_filter($this->formats, 'is_string'), array_keys(MediaLibraryDirectory::FORMATS)));
        $this->uses = array_values(array_intersect(array_filter($this->uses, 'is_string'), MediaLibraryDirectory::USES));
        $this->picked = array_values(array_filter(Cast::strings($this->picked), fn (string $u): bool => self::ulidOr($u) !== ''));
        if (! array_key_exists($this->sort, MediaLibraryDirectory::SORTS)) {
            $this->sort = 'new';
        }
        if (! in_array($this->per, MediaLibraryDirectory::PER_PAGE, true)) {
            $this->per = 24;
        }
        if ($this->status !== 'trash' || ! Gate::allows('media.delete')) {
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
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? $value : '';
    }
}
