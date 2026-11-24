<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Tải xuống» in the media library's file drawer: the open file saved under its name.
 *
 * The file's public address is on the media host (another origin than the admin), where a link's
 * `download` attribute is ignored and a picture or a PDF opens in the tab instead. So the drawer
 * links here: media.view (also on the route), the file looked up by its ulid in the current website
 * (the model's site scope + RLS: another website's ulid, a trashed file or a malformed key finds
 * nothing), then a 302 to a link to the stored object signed for LINK_SECONDS that answers as an
 * attachment. The bytes go from the bucket to the browser, never through an Octane worker.
 *
 * Only the s3 driver (R2) writes the signed link's ResponseContentDisposition into the answer. Any
 * other disk streams the file from here: a local disk with `serve` also hands out temporary links,
 * but Laravel's serve route answers inline and ignores the disposition, so the file would open in
 * the tab instead of being saved.
 *
 * The saved name is the uploaded one, which comes from the browser as it was sent: a `/` or `\` in
 * it becomes `-` (the extension stays), since a header file name cannot carry a path separator.
 */
final class MediaFileDownloadController
{
    public const int LINK_SECONDS = 60;

    public function __invoke(string $ulid): RedirectResponse|StreamedResponse
    {
        abort_unless(Gate::allows('media.view'), 403);

        $media = Media::query()->where('ulid', strtoupper($ulid))
            ->first(['id', 'site_id', 'ulid', 'slug', 'storage_key', 'original_filename', 'mime']);
        abort_if($media === null, 404);

        $name = self::savedName($media);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($name));
        $ascii = trim($ascii, ' .-') === '' ? 'tep.'.$media->extLabel() : $ascii;
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $ascii);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        $diskName = MediaKey::disk();
        $disk = Storage::disk($diskName);
        if (config("filesystems.disks.{$diskName}.driver") === 's3') {
            $url = $disk->temporaryUrl($media->storage_key, now()->addSeconds(self::LINK_SECONDS), [
                'ResponseContentDisposition' => $disposition,
                'ResponseCacheControl' => 'no-store, private',
            ]);

            return redirect()->away($url)->withHeaders($headers + ['Referrer-Policy' => 'no-referrer']);
        }

        return $disk->download($media->storage_key, $name, $headers + ['Content-Disposition' => $disposition]);
    }

    /** The file's download name with no path separator or control character, safe for a header. */
    private static function savedName(Media $media): string
    {
        $name = trim((string) preg_replace(['/[\/\\\\]/', '/[\x00-\x1F\x7F]/'], ['-', ''], $media->downloadName()));

        return trim($name, ' .-') === '' ? 'tep.'.$media->extLabel() : $name;
    }
}
