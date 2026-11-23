<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Shared\Support\Cast;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Where the files visitors send with a lead are kept: the private disk `config('forms.files_disk')`
 * (filesystems.php `leads`, never served by the public media Worker), under
 * `leads/{site ulid}/{file ulid}`. The key names nothing of the visitor and no file name, and the
 * signed link's query asks for a neutral name to save under (downloadName: `tep-dinh-kem-2.pdf`),
 * never the visitor's own: a link is written down by the bucket's and the edge's access logs and
 * the browser's history, so neither the bucket's listing nor those logs hold the visitor's file
 * name. The real name is shown only in the lead's detail, behind the permission check. Objects are
 * written as `application/octet-stream` + `attachment`, and the only way out is a signed link that
 * lives LINK_SECONDS, made after the permission check and the audit row
 * (LeadFileDownloadController).
 */
final class LeadFileStorage
{
    public const int LINK_SECONDS = 60;

    /** Written with every object, and asked again of the signed link: a download, never a page. */
    public const array OBJECT_OPTIONS = [
        'mimetype' => 'application/octet-stream',
        'ContentType' => 'application/octet-stream',
        'ContentDisposition' => 'attachment',
        'visibility' => 'private',
    ];

    public static function diskName(): string
    {
        return Cast::string(config('forms.files_disk'), 'leads');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function key(string $siteUlid, string $fileUlid): string
    {
        if (preg_match('/\A[0-9A-Za-z]{26}\z/', $siteUlid) !== 1 || preg_match('/\A[0-9A-Za-z]{26}\z/', $fileUlid) !== 1) {
            throw new LogicException('A lead file key is made of two ulids.');
        }

        return 'leads/'.$siteUlid.'/'.$fileUlid;
    }

    /** The largest file a field takes, in bytes. */
    public static function maxBytes(): int
    {
        return max(1, Cast::int(config('forms.file_max_mb'), 20)) * 1024 * 1024;
    }

    /**
     * The signed link to one kept file, valid LINK_SECONDS, that the browser saves under
     * downloadName() and never opens in a page.
     */
    public static function downloadUrl(FormSubmissionFile $file): string
    {
        $key = $file->storage_key ?? throw new LogicException('A refused file has nothing to download.');

        return self::signedUrl($key, self::downloadName($file));
    }

    /**
     * A link to the object under $key, valid LINK_SECONDS, that asks the bucket to answer as a
     * download saved as $name (`application/octet-stream`, `attachment`, `no-store`). Shared by
     * downloadUrl and forms:check-file-storage, so the self-check signs exactly what a download
     * signs.
     */
    public static function signedUrl(string $key, string $name): string
    {
        $disk = self::disk();
        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('The lead files disk cannot sign a link.');
        }

        return $disk->temporaryUrl($key, now()->addSeconds(self::LINK_SECONDS), [
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => self::disposition($name),
            'ResponseCacheControl' => 'no-store, private',
        ]);
    }

    /**
     * The name a downloaded file is saved under: `tep-dinh-kem-{n}.{type}`, n its place among its
     * lead's files (as the lead's detail lists them, refused ones included), the type its kept
     * extension. Nothing of the visitor: no name, not even transliterated, since a file name is
     * often the visitor's own («CV Nguyễn Văn A.pdf») and this one travels in the signed link.
     */
    public static function downloadName(FormSubmissionFile $file): string
    {
        $extension = LeadFileKinds::extension($file->original_name);
        $extension = $extension !== null && preg_match('/\A[a-z0-9]{1,8}\z/', $extension) === 1 ? $extension : 'bin';
        $place = $file->form_submission_id === null ? 1 : FormSubmissionFile::query()
            ->where('form_submission_id', $file->form_submission_id)
            ->where('id', '<=', $file->id)
            ->count();

        return 'tep-dinh-kem-'.max(1, $place).'.'.$extension;
    }

    /** `attachment` with a plain ASCII file name (no `filename*`: the name is ASCII already). */
    public static function disposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($name));
        $ascii = trim($ascii, ' .-') === '' ? 'tep-dinh-kem' : $ascii;

        return 'attachment; filename="'.$ascii.'"';
    }
}
