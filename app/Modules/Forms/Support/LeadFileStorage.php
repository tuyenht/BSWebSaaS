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
 * `leads/{site ulid}/{file ulid}`. The key names nothing of the visitor and no file name, so the
 * bucket's listing and the access log hold no personal data. Objects are written as
 * `application/octet-stream` + `attachment`, and the only way out is a signed link that lives
 * LINK_SECONDS, made after the permission check and the audit row (LeadFileDownloadController).
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
     * The signed link to one kept file, valid LINK_SECONDS, that the browser saves under the file's
     * name (an ASCII fallback first, the real name per RFC 6266 second) and never opens in a page.
     */
    public static function downloadUrl(FormSubmissionFile $file): string
    {
        $key = $file->storage_key ?? throw new LogicException('A refused file has nothing to download.');
        $disk = self::disk();
        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('The lead files disk cannot sign a link.');
        }

        return $disk->temporaryUrl($key, now()->addSeconds(self::LINK_SECONDS), [
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => self::disposition($file->original_name),
            'ResponseCacheControl' => 'no-store, private',
        ]);
    }

    public static function disposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($name));
        $ascii = trim($ascii, ' .') === '' ? 'tep' : $ascii;

        return 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
