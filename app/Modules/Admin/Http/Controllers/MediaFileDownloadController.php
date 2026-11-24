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
 * attachment. The bytes go from the bucket to the browser, never through an Octane worker; only a
 * disk that cannot sign links (a local one) streams the file from here.
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

        $name = $media->downloadName();
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($name));
        $ascii = trim($ascii, ' .-') === '' ? 'tep.'.$media->extLabel() : $ascii;
        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $ascii);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        $disk = Storage::disk(MediaKey::disk());
        if ($disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl($media->storage_key, now()->addSeconds(self::LINK_SECONDS), [
                'ResponseContentDisposition' => $disposition,
                'ResponseCacheControl' => 'no-store, private',
            ]);

            return redirect()->away($url)->withHeaders($headers + ['Referrer-Policy' => 'no-referrer']);
        }

        return $disk->download($media->storage_key, $name, $headers + ['Content-Disposition' => $disposition]);
    }
}
