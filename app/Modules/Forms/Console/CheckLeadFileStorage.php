<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks, on the real private lead-files disk, that a kept file can only ever come out as a
 * download: run from the release smoke. It needs nothing on its command line (the disk's own
 * configuration) and prints one line, `OK` or `FAIL: <step>`, never a key, a bucket, an endpoint or
 * a link; the log gets the failed step and the exception's class, nothing more.
 *
 *  1. writes a tiny object under CHECK_PREFIX (never under a website's `leads/` prefix, so
 *     forms:reconcile-files and the sweeper never see it) with LeadFileStorage::OBJECT_OPTIONS;
 *  2. reads its stored headers back from the bucket: `application/octet-stream` and `attachment`;
 *  3. signs a link exactly as a download does (LeadFileStorage::signedUrl) and fetches it: the
 *     answer must be 200 with the object's bytes, `application/octet-stream`, `attachment` (never
 *     `inline`) and `no-store`;
 *  4. deletes the object, whatever happened before.
 *
 * Exit code 0 on OK, 1 on FAIL.
 */
final class CheckLeadFileStorage extends Command
{
    public const string CHECK_PREFIX = 'storage-check/';

    private const string BODY = "lead files storage check\n";

    #[\Override]
    protected $signature = 'forms:check-file-storage';

    #[\Override]
    protected $description = 'Check that the private lead-files disk keeps and hands out files only as downloads (prints OK or FAIL)';

    public function handle(): int
    {
        $disk = null;
        $opened = $this->step('the lead files disk could not be opened', function () use (&$disk): ?string {
            $disk = LeadFileStorage::disk();

            return null;
        });
        if ($opened !== null) {
            return $this->refuse($opened);
        }
        if (! $disk instanceof AwsS3V3Adapter) {
            return $this->refuse('the lead files disk is not an S3 disk');
        }

        $key = self::CHECK_PREFIX.Str::lower((string) Str::ulid());
        $written = false;
        $failure = null;
        try {
            $failure = $this->step('could not write the check object', function () use ($disk, $key, &$written): ?string {
                $written = $disk->put($key, self::BODY, LeadFileStorage::OBJECT_OPTIONS);

                return $written ? null : 'could not write the check object';
            })
                ?? $this->step('could not read the check object\'s headers', fn (): ?string => $this->storedHeaders($disk, $key))
                ?? $this->step('could not fetch the signed link', fn (): ?string => $this->signedAnswer($key));
        } finally {
            if ($written) {
                $deleted = $this->step('could not delete the check object', fn (): ?string => $disk->delete($key) ? null : 'could not delete the check object');
                $failure ??= $deleted;
            }
        }

        if ($failure !== null) {
            return $this->refuse($failure);
        }
        $this->line('OK');

        return self::SUCCESS;
    }

    /**
     * Runs one step: its own refusal, or $reason when it throws. The log gets the step and the
     * exception's class only: its message can carry the endpoint, the bucket or a signed link with
     * its access key id.
     */
    private function step(string $reason, Closure $step): ?string
    {
        try {
            return Cast::stringOrNull($step());
        } catch (Throwable $e) {
            Log::warning('forms:check-file-storage: '.$reason.'.', ['exception_class' => $e::class]);

            return $reason;
        }
    }

    private function storedHeaders(AwsS3V3Adapter $disk, string $key): ?string
    {
        $head = $disk->getClient()->headObject([
            'Bucket' => Cast::string($disk->getConfig()['bucket'] ?? null),
            'Key' => $disk->path($key),
        ]);
        $type = Cast::string($head['ContentType'] ?? null);
        $disposition = Cast::string($head['ContentDisposition'] ?? null);
        if (strtolower($type) !== 'application/octet-stream') {
            return 'the stored object is not kept as application/octet-stream';
        }
        if (! self::isAttachment($disposition)) {
            return 'the stored object is not kept as an attachment';
        }

        return null;
    }

    private function signedAnswer(string $key): ?string
    {
        $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->get(LeadFileStorage::signedUrl($key, 'storage-check.txt'));
        if ($response->status() !== 200) {
            return 'the signed link answered '.$response->status().', not 200';
        }
        if ($response->body() !== self::BODY) {
            return 'the signed link did not return the check object';
        }
        if (strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0])) !== 'application/octet-stream') {
            return 'the signed link is not answered as application/octet-stream';
        }
        if (! self::isAttachment((string) $response->header('Content-Disposition'))) {
            return 'the signed link is not answered as an attachment';
        }
        if (! str_contains(strtolower((string) $response->header('Cache-Control')), 'no-store')) {
            return 'the signed link is not answered as no-store';
        }

        return null;
    }

    private static function isAttachment(string $disposition): bool
    {
        $disposition = strtolower(trim($disposition));

        return str_starts_with($disposition, 'attachment') && ! str_contains($disposition, 'inline');
    }

    private function refuse(string $reason): int
    {
        $this->line('FAIL: '.$reason);

        return self::FAILURE;
    }
}
