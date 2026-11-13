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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The media library page (/admin/media): a folder column, the tools row (search, filters, sort,
 * grid/list), the files page by page from a cursor, selection with move / Trash / restore / purge,
 * and the upload panel. Files and folders are addressed by ulid and slug only — never a bigint id.
 * Read is gated by media.view (re-checked in boot(): the livewire/update endpoint bypasses route
 * middleware); every action re-checks its own right. The upload panes and the detail panel are the
 * shared picker's (WithMediaLibraryPicker in manage mode).
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

    public ?string $toast = null;

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public const int FOLDER_NAME_MAX = 80;

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

    /** A finished upload batch: the new files are the newest, so the list starts again. */
    #[On('media-uploaded')]
    public function onPickerMediaUploaded(): void
    {
        $this->status = 'live';
        $this->restart();
    }

    public function openNewFolder(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

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
        abort_unless(Gate::allows('media.upload'), 403);

        $name = trim($this->newFolderName);
        $this->resetErrorBag('newFolderName');
        if ($name === '') {
            $this->addError('newFolderName', __('media.lib.folder_name_required'));

            return;
        }
        if (mb_strlen($name) > self::FOLDER_NAME_MAX) {
            $this->addError('newFolderName', __('media.lib.folder_name_long', ['max' => self::FOLDER_NAME_MAX]));

            return;
        }
        if (MediaFolder::query()->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            $this->addError('newFolderName', __('media.lib.folder_name_taken'));

            return;
        }

        $folder = new MediaFolder;
        $maxPosition = MediaFolder::query()->max('position');
        $folder = MediaFolder::query()->create([
            'name' => $name,
            'slug' => $folder->uniqueSlug($name, $name),
            'position' => (is_numeric($maxPosition) ? (int) $maxPosition : 0) + 1,
        ]);

        $this->cancelNewFolder();
        $this->setFolder((string) $folder->slug);
        $this->say(__('media.lib.folder_created', ['name' => $name]));
    }

    public function openFolderSheet(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->folderSheetOpen = true;
    }

    public function closeFolderSheet(): void
    {
        $this->folderSheetOpen = false;
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
        $this->undoTrash = Cast::strings($rows->pluck('ulid')->all());
        $this->picked = [];
        $this->askTrashOpen = false;
        $this->say(__('media.lib.trashed', ['n' => $rows->count()]));
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

    public function askPurge(bool $all = false): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

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
        abort_unless(Gate::allows('media.delete'), 403);

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
        }
    }

    public function render(): View
    {
        $this->clean();
        $canDelete = Gate::allows('media.delete');
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
        if ($this->askPurgeOpen && $canDelete && ! $failed) {
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

    private function say(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? $value : '';
    }
}
