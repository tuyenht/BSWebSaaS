<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Counts a stored PDF's pages with qpdf and keeps the number in `media.meta.pages`, for the media
 * library to show («PDF · N trang»). The number is only shown, never filtered or sorted on, so it
 * lives in `meta` rather than a column.
 *
 * The file is an untrusted upload, so qpdf runs as its own process: the arguments go as a list (no
 * shell), under a wall-clock timeout and prlimit caps on memory and CPU time, on a private copy
 * of the object in the temp folder. Whatever qpdf cannot answer cleanly (a broken or
 * password-protected file, a timeout, odd output, a count out of range) leaves the number empty:
 * the library then shows «PDF» alone, never a made-up count. Nothing here throws for a bad file.
 */
final readonly class PdfPages
{
    public const string MIME = 'application/pdf';

    /** The largest page count kept; anything above is treated as unreadable. */
    public const int MAX_PAGES = 100_000;

    /** Seconds qpdf may run before it is stopped. */
    public const int TIMEOUT_SECONDS = 20;

    /** Address space qpdf may take (prlimit --as), in bytes. */
    public const int MEMORY_BYTES = 512 * 1024 * 1024;

    /** CPU seconds qpdf may use (prlimit --cpu). */
    public const int CPU_SECONDS = 15;

    public function __construct(private Tenancy $tenancy) {}

    /**
     * Counts one website's PDF and stores the number. Returns the page count now stored (an
     * existing one is kept, so a second run changes nothing), or null when the row is gone, is not
     * a PDF, or its pages cannot be told.
     */
    public function record(int $siteId, int $mediaId): ?int
    {
        $row = $this->tenancy->enter($siteId, fn (): ?Media => Media::withTrashed()->whereKey($mediaId)
            ->where('mime', self::MIME)->first(['id', 'mime', 'disk', 'storage_key', 'size', 'meta']));
        if (! $row instanceof Media) {
            return null;
        }
        $known = $row->pageCount();
        if ($known !== null || $row->size > UploadMedia::MAX_KB * 1024) {
            return $known;
        }

        $pages = $this->countObject($row->disk, $row->storage_key);
        if ($pages === null) {
            return null;
        }

        // jsonb_set on the one key, in the database: other meta keys written meanwhile are kept (a
        // meta that is not an object starts over as one); updated_at is left alone, the file did not
        // change. RLS applies, and site_id = ? keeps the statement to the website on its own too; the
        // storage key must still be the one counted (a file swapped meanwhile is not given its count).
        $written = $this->tenancy->enter($siteId, fn (): int => DB::update(
            "update media set meta = jsonb_set(case when jsonb_typeof(meta) = 'object' then meta else '{}'::jsonb end, '{pages}', to_jsonb(?::int)) where site_id = ? and id = ? and storage_key = ?",
            [$pages, $siteId, $mediaId, $row->storage_key],
        ));

        return $written > 0 ? $pages : null;
    }

    /** The page count of the PDF at $path, or null when qpdf cannot tell. */
    public function count(string $path): ?int
    {
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->env(['LC_ALL' => 'C'])->run([
                'prlimit', '--as='.self::MEMORY_BYTES, '--cpu='.self::CPU_SECONDS, '--',
                'qpdf', '--show-npages', $path,
            ]);
        } catch (Throwable) {
            return null; // timed out, or the process could not start
        }

        // 0 = clean; 3 = qpdf repaired something on the way and still answered
        if (! in_array($result->exitCode(), [0, 3], true)) {
            return null;
        }

        return self::parse($result->output());
    }

    /** A page count from qpdf's output: one whole number, 1..MAX_PAGES, nothing else. */
    public static function parse(string $output): ?int
    {
        if (preg_match('/\A\s*([0-9]{1,7})\s*\z/', $output, $m) !== 1) {
            return null;
        }

        return self::valid((int) $m[1]);
    }

    /** A stored page count as an int, or null when it is missing or not a sensible count. */
    public static function valid(mixed $pages): ?int
    {
        return is_int($pages) && $pages >= 1 && $pages <= self::MAX_PAGES ? $pages : null;
    }

    /** Copies the stored object to a private temp file and counts it there. */
    private function countObject(string $diskName, string $key): ?int
    {
        try {
            $in = Storage::disk($diskName)->readStream($key);
        } catch (Throwable) {
            return null;
        }
        if (! is_resource($in)) {
            return null; // the object is missing
        }

        $path = tempnam(sys_get_temp_dir(), 'pdf-pages-');
        if ($path === false) {
            fclose($in);

            return null;
        }

        try {
            $out = fopen($path, 'wb');
            if ($out === false) {
                return null;
            }
            $copied = stream_copy_to_stream($in, $out, UploadMedia::MAX_KB * 1024 + 1);
            fclose($out);

            return $copied === false || $copied > UploadMedia::MAX_KB * 1024 ? null : $this->count($path);
        } finally {
            fclose($in);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
