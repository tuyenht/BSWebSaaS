<?php

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\LangBar;
use App\Modules\Admin\Support\MediaDeleteRights;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Actions\TrashMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * The media library's file detail drawer (design board 12 «Thư viện ảnh & tệp»): a picture or a
 * file opened by its ulid, live or (for someone who sees the Trash) trashed. Its picture
 * description and title are edited per website language and saved together; the ⋮ menu downloads,
 * moves or sends the open file to the Trash, or deletes it for good from the Trash; a trashed file
 * comes back out with «Khôi phục». Every endpoint re-checks its right: reading is media.view,
 * changing the words or the folder media.upload, the Trash the delete rights (MediaDeleteRights:
 * «own» = only this person's own unused uploads) and deleting for good media.purge (never by
 * support staff signed in for a member). The open file's ulid is the locked pointer the shared
 * picker already keeps ($pickerDetailUlid), set only after a site-scoped lookup.
 *
 * «Lưu» writes only the languages whose words differ from what was stored when the drawer opened
 * (the locked $detailBase), merged onto the row as it is stored at that moment (read under a row
 * lock), so another editor's change to another language meanwhile is kept. Only a picture has words
 * to change: the server refuses a save for any other file, as the drawer draws no fields for it.
 * «Tải xuống» is a plain link to the admin route that hands out the file (MediaFileDownloadController).
 *
 * The page (Library) supplies the move / Trash / purge dialogs, which act on the open file while
 * $single is set (targets()), and its toast (say()).
 */
trait WithMediaDetailDrawer
{
    /** @var array<string, string> the picture description being edited, by website language */
    public array $detailAlt = [];

    /** @var array<string, string> the picture title being edited, by website language */
    public array $detailTitle = [];

    /**
     * The words as they were stored when the drawer was filled: a language whose draft still equals
     * its entry here is not written by «Lưu».
     *
     * @var array{alt: array<string, string>, title: array<string, string>}
     */
    #[Locked]
    public array $detailBase = ['alt' => [], 'title' => []];

    /** The words of the open file were just saved (the footer's «Đã lưu»). */
    #[Locked]
    public bool $detailSaved = false;

    /** The move / Trash / purge dialog acts on the open file, not on the chosen files. */
    #[Locked]
    public bool $single = false;

    public function openDetail(string $ulid): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // site-scoped and never trusted from the wire: another site's, a malformed or an unseen
        // trashed ulid resolves to nothing and the drawer stays as it was
        $media = $this->detailFile($ulid);
        if ($media === null) {
            return;
        }
        $this->pickerDetailUlid = $media->ulid;
        $this->fillDetail($media);
    }

    public function closeDetail(): void
    {
        $this->pickerDetailUlid = null;
        $this->detailAlt = [];
        $this->detailTitle = [];
        $this->detailSaved = false;
        $this->single = false;
        $this->resetErrorBag();
    }

    /**
     * «Lưu»: the description and title of every website language that changed (media.upload; live
     * pictures only), merged onto the words as they are stored now.
     */
    public function saveDetail(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $ulid = $this->pickerDetailUlid;
        if ($ulid === null) {
            return;
        }

        $this->resetErrorBag();
        $wrong = null;
        $media = DB::transaction(function () use ($ulid, &$wrong): ?Media {
            // the row as stored now, held until the write: a save racing this one waits for it
            $media = Media::query()->where('ulid', $ulid)->lockForUpdate()->first();
            if ($media === null) {
                return null; // gone or in the Trash meanwhile: a trashed file's words are read-only
            }
            abort_unless(str_starts_with($media->mime, 'image/'), 403); // only a picture has words

            $alt = $media->alt;
            $title = $media->title;
            foreach (app(SiteLocale::class)->enabled() as $code) {
                $a = $this->detailEdit($this->detailAlt, 'alt', $code);
                $t = $this->detailEdit($this->detailTitle, 'title', $code);
                if ($a === false || (is_string($a) && mb_strlen($a) > Media::ALT_MAX_LENGTH)) {
                    $this->addError('detailAlt.'.$code, __('media.drw.alt_long', ['max' => Media::ALT_MAX_LENGTH]));
                    $wrong ??= $code;
                }
                if ($t === false || (is_string($t) && mb_strlen($t) > Media::TITLE_MAX_LENGTH)) {
                    $this->addError('detailTitle.'.$code, __('media.drw.title_long', ['max' => Media::TITLE_MAX_LENGTH]));
                    $wrong ??= $code;
                }
                if ($wrong !== null) {
                    continue;
                }
                // per-locale JSON: an emptied language drops its key; an unchanged language, and a
                // language no longer on the website, keep the words stored now
                self::putWords($alt, $code, $a);
                self::putWords($title, $code, $t);
            }
            if ($wrong === null && ($alt !== $media->alt || $title !== $media->title)) {
                $media->update(['alt' => $alt, 'title' => $title]);
            }

            return $media;
        });
        if ($media === null) {
            return;
        }
        if ($wrong !== null) {
            $this->detailSaved = false;
            $this->say(__('media.drw.save_failed'), 'error');
            $this->dispatch('media-detail-saved', ok: false, lang: $wrong);

            return;
        }

        $this->fillDetail($media);
        $this->detailSaved = true;
        $this->dispatch('media-detail-saved', ok: true, lang: '');
    }

    /** «Bỏ thay đổi và đóng»: the words back as they are stored. */
    public function discardDetail(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $media = $this->detailFile($this->pickerDetailUlid);
        if ($media === null) {
            $this->closeDetail();

            return;
        }
        $this->fillDetail($media);
    }

    /** «Di chuyển sang thư mục…»: the page's move dialog, for the open file. */
    public function moveDetail(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        if ($this->pickerDetailUlid === null || ! Media::query()->where('ulid', $this->pickerDetailUlid)->exists()) {
            return;
        }
        $this->single = true;
        $this->moveTo = '';
        $this->moveOpen = true;
    }

    /** «Chuyển vào Thùng rác» for the open file: asks first when it is used somewhere. */
    public function trashDetail(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        if ($this->pickerDetailUlid === null) {
            return;
        }
        $this->single = true;
        $this->askTrash();
    }

    /** «Xóa vĩnh viễn» for the open trashed file (media.purge; never by support staff signed in for a member). */
    public function purgeDetail(): void
    {
        abort_unless(Gate::allows('media.purge'), 403);
        app(SupportSession::class)->forbid();

        if ($this->pickerDetailUlid === null || ! Media::onlyTrashed()->where('ulid', $this->pickerDetailUlid)->exists()) {
            return;
        }
        $this->single = true;
        $this->purgeAll = false;
        $this->askPurgeOpen = true;
    }

    /** «Khôi phục» for the open trashed file (with media.delete.own, only what this person put in). */
    public function restoreDetail(): void
    {
        abort_unless(MediaDeleteRights::some(), 403);

        $media = $this->pickerDetailUlid === null ? null
            : MediaDeleteRights::restorable(Media::onlyTrashed()->where('ulid', $this->pickerDetailUlid))->first();
        if ($media === null) {
            return;
        }
        app(TrashMedia::class)->restore(new EloquentCollection([$media]));
        $this->closeDetail();
        $this->say(__('media.lib.restored', ['n' => 1]));
    }

    /** The «Sao chép» button's toast (the copy itself happens in the browser). */
    public function copiedUrl(bool $ok = true): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->say($ok ? __('media.drw.copied') : __('media.drw.copy_failed'), $ok ? 'success' : 'error');
    }

    /**
     * What the drawer shows for the open file, or null when none is open (or it is gone).
     *
     * @return array<string, mixed>|null
     */
    protected function detailView(): ?array
    {
        $media = $this->detailFile($this->pickerDetailUlid);
        if ($media === null) {
            if ($this->pickerDetailUlid !== null) {
                $this->closeDetail(); // gone meanwhile (purged, or out of sight): nothing left to show
            }

            return null;
        }

        $locale = app(SiteLocale::class);
        $codes = $locale->enabled();
        $source = $locale->default();
        $trashed = $media->trashed();
        $image = str_starts_with($media->mime, 'image/');
        $urls = app(MediaUrlResolver::class);
        $usages = app(MediaUsageLocator::class)->usagesFor($media, $source);
        $groups = ['product' => [], 'cover' => [], 'content' => [], 'brand' => []];
        foreach ($usages as $u) {
            $groups[$u['role'] === 'gallery' ? 'product' : $u['role']][] = $u;
        }

        $people = array_values(array_filter([$media->created_by, $media->deleted_by], fn (?int $id): bool => $id !== null));
        $names = $people === [] ? [] : User::query()->whereIn('id', $people)->pluck('name', 'id')->all();
        $uploader = $media->created_by !== null ? Cast::string($names[$media->created_by] ?? '') : '';
        $trasher = $media->deleted_by !== null ? Cast::string($names[$media->deleted_by] ?? '') : '';

        $any = MediaDeleteRights::any();
        $own = ! $any && Gate::allows('media.delete.own');
        $mine = $media->created_by !== null && $media->created_by === Auth::id();
        $mayTrash = ! $trashed && ($any || ($own && $mine && $usages === []));
        $mayRestore = $trashed && ($any || ($own && $mine && ($media->deleted_by === null || $media->deleted_by === Auth::id())));
        $why = '';
        if ($trashed && ! $mayRestore) {
            $why = __('media.drw.why_restore', ['name' => $trasher !== '' ? $trasher : __('media.drw.admin')]);
        } elseif (! $trashed && $own && ! $mayTrash) {
            $why = ! $mine
                ? __('media.drw.why_theirs', ['name' => $uploader !== '' ? $uploader : __('media.drw.someone')])
                : __('media.drw.why_used', ['n' => count($usages)]);
        }

        $retention = Cast::int(config('media.trash_retention_days', 30), 30);
        $deletedAt = $media->getAttribute('deleted_at');
        $left = $trashed && $deletedAt instanceof CarbonInterface
            ? max(0, $retention - (int) floor($deletedAt->diffInDays(now()))) : 0;

        // counted against the words as they were when the drawer was filled, like «Lưu» does
        $changes = 0;
        foreach ($codes as $code) {
            $changes += (int) ($this->detailEdit($this->detailAlt, 'alt', $code) !== null);
            $changes += (int) ($this->detailEdit($this->detailTitle, 'title', $code) !== null);
        }

        return [
            'media' => $media,
            'name' => $this->detailName($media),
            'trashed' => $trashed,
            'image' => $image,
            'pdf' => $media->mime === 'application/pdf',
            'title' => __($trashed ? 'media.drw.title_trash' : ($image ? 'media.drw.title_image' : 'media.drw.title_file')),
            'preview' => $image ? $urls->url($media->storage_key, 800) : null,
            'srcset' => $image ? $urls->srcset($media->storage_key, [480, 800, 1200], $media->width) : null,
            'fileUrl' => $urls->fileUrl($media->storage_key),
            'download' => route('admin.media.download', $media->ulid),
            'languages' => array_map(fn (string $code): array => ['code' => $code, 'name' => LangBar::name($code), 'source' => $code === $source], $codes),
            'source' => $source,
            'base' => $this->detailBase,
            'uploader' => $uploader,
            'groups' => array_filter($groups),
            'useN' => count($usages),
            'mayTrash' => $mayTrash && MediaDeleteRights::some(),
            'mayRestore' => $mayRestore,
            'why' => $why,
            'left' => $left,
            'changes' => $changes,
            'canEdit' => ! $trashed && $image && Gate::allows('media.upload'),
            'canUpload' => Gate::allows('media.upload'),
            'canPurge' => $trashed && Gate::allows('media.purge'),
            // support staff signed in for a member: «Xóa vĩnh viễn» stays, greyed, with why (the server refuses it too)
            'support' => app(SupportSession::class)->active(),
            'manage' => Gate::allows('settings.manage') ? route('admin.settings.languages') : null,
        ];
    }

    /** The open file: live, or trashed when this person sees it in the Trash. */
    private function detailFile(?string $ulid): ?Media
    {
        if ($ulid === null || ! Str::isUlid($ulid)) {
            return null;
        }
        $live = Media::query()->with('folder')->where('ulid', $ulid)->first();
        if ($live !== null || ! MediaDeleteRights::some()) {
            return $live;
        }

        return MediaDeleteRights::visibleTrash(Media::onlyTrashed()->with('folder')->where('ulid', $ulid))->first();
    }

    /** The drafts and their baseline from what is stored, one entry per website language. */
    private function fillDetail(Media $media): void
    {
        $this->detailAlt = [];
        $this->detailTitle = [];
        $this->detailBase = ['alt' => [], 'title' => []];
        foreach (app(SiteLocale::class)->enabled() as $code) {
            $this->detailAlt[$code] = $this->detailBase['alt'][$code] = $media->alt[$code] ?? '';
            $this->detailTitle[$code] = $this->detailBase['title'][$code] = $media->title[$code] ?? '';
        }
        $this->detailSaved = false;
        $this->resetErrorBag();
    }

    /**
     * What «Lưu» does with one language of one field: null leaves it as stored (the draft, trimmed,
     * still equals the baseline, or the wire sent no draft for it), a string is the new text ('' =
     * emptied), false a draft that is not text (a forged payload).
     *
     * @param  array<array-key, mixed>  $drafts
     * @param  'alt'|'title'  $field
     */
    private function detailEdit(array $drafts, string $field, string $code): string|false|null
    {
        if (! array_key_exists($code, $drafts)) {
            return null;
        }
        $value = $drafts[$code];
        if (! is_string($value)) {
            return false;
        }
        $text = trim($value);

        return $text === ($this->detailBase[$field][$code] ?? '') ? null : $text;
    }

    /**
     * One language's change onto stored per-locale words: null keeps them, '' drops the key.
     *
     * @param  array<string, string>  $words
     */
    private static function putWords(array &$words, string $code, string|false|null $text): void
    {
        if (! is_string($text)) {
            return;
        }
        if ($text === '') {
            unset($words[$code]);
        } else {
            $words[$code] = $text;
        }
    }

    private function detailName(Media $media): string
    {
        return $media->downloadName();
    }

    /**
     * The files a move / Trash / purge dialog acts on: the open file while $single is set, else the chosen ones.
     *
     * @return list<string>
     */
    private function targets(): array
    {
        return $this->single && $this->pickerDetailUlid !== null ? [$this->pickerDetailUlid] : $this->picked;
    }

    /** After a dialog acted: the open file's turn ends; otherwise the choice is cleared. */
    private function doneWithTargets(): void
    {
        if ($this->single) {
            $this->single = false;

            return;
        }
        $this->picked = [];
    }
}
