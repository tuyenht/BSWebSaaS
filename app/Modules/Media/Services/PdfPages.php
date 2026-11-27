<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

/**
 * Counts a stored PDF's pages with qpdf and keeps the number in `media.meta.pages`, for the media
 * library to show («PDF · N trang»). The number is only shown, never filtered or sorted on, so it
 * lives in `meta` rather than a column.
 *
 * The file is an untrusted upload, so qpdf runs as its own process: the arguments go as a list (no
 * shell), with only PATH and LC_ALL in its environment (none of the app's secrets), under a
 * wall-clock timeout and prlimit caps on memory and CPU time, on a private copy of the object in
 * the temp folder. The copy itself has a deadline and a per-read timeout, so a stalled storage read
 * cannot outlast the job. Whatever qpdf cannot answer cleanly (a broken or password-protected file,
 * a timeout, odd output, a count out of range) leaves the number empty: the library then shows
 * «PDF» alone, never a made-up count. Nothing here throws for a bad file.
 *
 * A file qpdf has judged unreadable is marked with its storage key (`meta.pages_unreadable`), so it
 * is not read again until the file under it changes (a new key). When qpdf is not there at all, or
 * the object cannot be read, nothing is marked: the next run tries again.
 */
final readonly class PdfPages
{
    public const string MIME = 'application/pdf';

    /** The meta key holding the storage key of a file qpdf could not count. */
    public const string UNREADABLE_KEY = 'pages_unreadable';

    /** The largest page count kept; anything above is treated as unreadable. */
    public const int MAX_PAGES = 100_000;

    /** Seconds qpdf may run before it is stopped. */
    public const int TIMEOUT_SECONDS = 20;

    /** Address space qpdf may take (prlimit --as), in bytes. */
    public const int MEMORY_BYTES = 512 * 1024 * 1024;

    /** CPU seconds qpdf may use (prlimit --cpu). */
    public const int CPU_SECONDS = 15;

    /** Seconds the whole copy from storage may take. */
    public const int READ_SECONDS = 15;

    /** Seconds one storage connect or read may wait for bytes. */
    public const int READ_IDLE_SECONDS = 5;

    /** The only PATH qpdf is given. */
    public const string PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /** qpdf answered: the file cannot be counted. */
    private const int UNREADABLE = 0;

    /** No answer about the file (qpdf missing, a process that would not start, a storage fault). */
    private const int NO_ANSWER = -1;

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
        if ($known !== null) {
            return $known;
        }
        if (($row->meta[self::UNREADABLE_KEY] ?? null) === $row->storage_key) {
            return null; // judged before, and the file has not changed since
        }

        $pages = $row->size > UploadMedia::MAX_KB * 1024 ? self::UNREADABLE : $this->countObject($row->disk, $row->storage_key);
        if ($pages === self::NO_ANSWER) {
            return null;
        }
        if ($pages === self::UNREADABLE) {
            $this->write($siteId, $mediaId, $row->storage_key, self::UNREADABLE_KEY, 'to_jsonb(?::text)', $row->storage_key);

            return null;
        }

        return $this->write($siteId, $mediaId, $row->storage_key, 'pages', 'to_jsonb(?::int)', $pages) ? $pages : null;
    }

    /** The page count of the PDF at $path, or null when qpdf cannot tell. */
    public function count(string $path): ?int
    {
        $pages = $this->run($path);

        return $pages > 0 ? $pages : null;
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

    /**
     * qpdf's environment: PATH and LC_ALL alone. Every variable this process has is listed as
     * false, which tells the process component to leave it out rather than pass it on.
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $parent = [];
        foreach (array_keys(getenv() + $_ENV) as $name) {
            $parent[(string) $name] = false;
        }

        return ['PATH' => self::PATH, 'LC_ALL' => 'C'] + $parent;
    }

    /**
     * Copies at most $max + 1 bytes from $in to $out within $seconds. Returns the bytes copied (more
     * than $max means the source holds more than $max), or null when a read or write fails or the
     * time runs out first.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public static function copyWithin($in, $out, int $max, float $seconds): ?int
    {
        $end = microtime(true) + $seconds;
        $copied = 0;
        while ($copied <= $max && ! feof($in)) {
            if (microtime(true) > $end) {
                return null;
            }
            $chunk = fread($in, max(1, min(65536, $max + 1 - $copied)));
            if ($chunk === false) {
                return null;
            }
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                return null;
            }
            $copied += strlen($chunk);
        }

        return $copied;
    }

    /** Pages (> 0), UNREADABLE or NO_ANSWER for the PDF at $path. */
    private function run(string $path): int
    {
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->env(self::environment())->run([
                'prlimit', '--as='.self::MEMORY_BYTES, '--cpu='.self::CPU_SECONDS, '--',
                'qpdf', '--show-npages', $path,
            ]);
        } catch (ProcessTimedOutException) {
            return self::UNREADABLE; // the file kept qpdf busy past its time
        } catch (ProcessSignaledException) {
            return self::UNREADABLE; // the CPU cap stopped qpdf with a signal: the file is the cause
        } catch (Throwable) {
            return self::NO_ANSWER; // the process could not start
        }

        $exit = $result->exitCode();
        if ($exit === 126 || $exit === 127) {
            return self::NO_ANSWER; // prlimit could not run qpdf (not installed): try again later
        }
        // 0 = clean; 3 = qpdf repaired something on the way and still answered
        if (! in_array($exit, [0, 3], true)) {
            return self::UNREADABLE;
        }

        return self::parse($result->output()) ?? self::UNREADABLE;
    }

    /**
     * Sets one meta key, in the database: other meta keys written meanwhile are kept (a meta that
     * is not an object starts over as one); updated_at is left alone, the file did not change. RLS
     * applies, and site_id = ? keeps the statement to the website on its own too; the storage key
     * must still be the one read (a file swapped meanwhile is not given its verdict).
     */
    private function write(int $siteId, int $mediaId, string $storageKey, string $metaKey, string $valueSql, int|string $value): bool
    {
        return $this->tenancy->enter($siteId, fn (): int => DB::update(
            "update media set meta = jsonb_set(case when jsonb_typeof(meta) = 'object' then meta else '{}'::jsonb end, ?::text[], {$valueSql}) where site_id = ? and id = ? and storage_key = ?",
            ['{'.$metaKey.'}', $value, $siteId, $mediaId, $storageKey],
        )) > 0;
    }

    /** Copies the stored object to a private temp file and counts it there. */
    private function countObject(string $diskName, string $key): int
    {
        try {
            $in = $this->open($diskName, $key);
        } catch (Throwable) {
            return self::NO_ANSWER;
        }
        if (! is_resource($in)) {
            return self::NO_ANSWER; // the object is missing
        }

        $path = tempnam(sys_get_temp_dir(), 'pdf-pages-');
        if ($path === false) {
            fclose($in);

            return self::NO_ANSWER;
        }

        try {
            $out = fopen($path, 'wb');
            if ($out === false) {
                return self::NO_ANSWER;
            }
            try {
                $copied = self::copyWithin($in, $out, UploadMedia::MAX_KB * 1024, self::READ_SECONDS);
            } finally {
                fclose($out);
            }

            return match (true) {
                $copied === null => self::NO_ANSWER, // storage stalled or failed: try again later
                $copied > UploadMedia::MAX_KB * 1024 => self::UNREADABLE,
                default => $this->run($path),
            };
        } catch (Throwable) {
            return self::NO_ANSWER;
        } finally {
            fclose($in);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * A read stream of the stored object. On S3-compatible storage (R2) it is asked for directly,
     * with a connect and per-read timeout; other disks read it their own way.
     *
     * @return resource|null
     */
    private function open(string $diskName, string $key)
    {
        $disk = Storage::disk($diskName);
        if (! $disk instanceof AwsS3V3Adapter) {
            return $disk->readStream($key);
        }

        $body = $disk->getClient()->getObject([
            'Bucket' => Cast::string($disk->getConfig()['bucket'] ?? null),
            'Key' => $disk->path($key),
            // the streaming handler honours only `timeout` for connecting and the headers
            '@http' => ['stream' => true, 'timeout' => self::READ_IDLE_SECONDS + self::READ_SECONDS, 'connect_timeout' => self::READ_IDLE_SECONDS, 'read_timeout' => self::READ_IDLE_SECONDS],
        ])->get('Body');

        return $body instanceof StreamInterface ? StreamWrapper::getResource($body) : null;
    }
}
