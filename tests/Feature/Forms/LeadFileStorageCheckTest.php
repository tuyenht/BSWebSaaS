<?php

use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;

/*
 * forms:check-file-storage, run from the release smoke against the real private disk: it writes a
 * tiny object under a check prefix, reads its stored headers back, fetches a link signed exactly
 * as a download is, deletes the object, and prints one line, OK or FAIL with the step, never a
 * key, a bucket, an endpoint or a link. Here the bucket is an S3 client whose answers the test
 * gives (the link is still really signed); the run against R2 itself happens at release.
 */

const LSC_BODY = "lead files storage check\n";

/**
 * Puts an S3 disk on `leads` whose bucket answers through $answer(command name, params), and
 * notes every command it is sent in $this->calls.
 *
 * @param  Closure(string, array<string, mixed>): mixed  $answer  a Result, or an S3Exception to throw
 */
function lscDisk(object $test, ?Closure $answer = null): void
{
    $test->calls = $calls = new ArrayObject;
    $answer ??= fn (string $name): mixed => null;
    $handler = function (CommandInterface $command, RequestInterface $request) use ($calls, $answer) {
        $calls[] = ['name' => $command->getName(), 'params' => $command->toArray()];
        $given = $answer($command->getName(), $command->toArray());
        if ($given instanceof S3Exception) {
            return Create::rejectionFor($given);
        }
        if ($given instanceof Result) {
            return Create::promiseFor($given);
        }

        return Create::promiseFor(new Result(match ($command->getName()) {
            'HeadObject' => ['ContentType' => 'application/octet-stream', 'ContentDisposition' => 'attachment'],
            default => [],
        }));
    };

    Storage::set('leads', Storage::build([
        'driver' => 's3', 'key' => 'check-key', 'secret' => 'check-secret', 'region' => 'auto',
        'bucket' => 'leads-bucket', 'endpoint' => 'https://bucket-endpoint.example.test',
        'use_path_style_endpoint' => true, 'root' => '_private', 'visibility' => 'private',
        'handler' => $handler,
    ]));
}

/** The signed link answers with $headers (and LSC_BODY), noting the link it was asked for. */
function lscLink(object $test, array $headers = [], int $status = 200, string $body = LSC_BODY): void
{
    $test->fetched = $fetched = new ArrayObject;
    Http::fake(function (ClientRequest $request) use ($fetched, $headers, $status, $body) {
        $fetched[] = $request->url();

        return Http::response($body, $status, $headers + [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="storage-check.txt"',
            'Cache-Control' => 'no-store, private',
        ]);
    });
}

function lscNames(object $test): array
{
    return array_column($test->calls->getArrayCopy(), 'name');
}

it('says OK when the object is kept and handed out only as a download, and leaves nothing behind', function (): void {
    lscDisk($this);
    lscLink($this);

    $this->artisan('forms:check-file-storage')
        ->expectsOutput('OK')
        ->doesntExpectOutputToContain('http')
        ->doesntExpectOutputToContain('storage-check/')
        ->doesntExpectOutputToContain('leads-bucket')
        ->doesntExpectOutputToContain('check-key')
        ->assertExitCode(0);

    expect(lscNames($this))->toBe(['PutObject', 'HeadObject', 'DeleteObject']);
    [$put, $head, $delete] = $this->calls->getArrayCopy();
    expect($put['params']['Key'])->toStartWith('_private/storage-check/')->not->toContain('leads/')
        ->and($put['params']['ContentType'])->toBe('application/octet-stream')
        ->and($put['params']['ContentDisposition'])->toBe('attachment')
        ->and($head['params']['Key'])->toBe($put['params']['Key'])
        ->and($head['params']['Bucket'])->toBe('leads-bucket')
        ->and($delete['params']['Key'])->toBe($put['params']['Key']);

    // the link is the one a download signs: 60 seconds, octet-stream, attachment, no-store
    expect($this->fetched)->toHaveCount(1);
    parse_str((string) parse_url($this->fetched[0], PHP_URL_QUERY), $query);
    expect($query['X-Amz-Expires'])->toBe('60')
        ->and($query['response-content-type'])->toBe('application/octet-stream')
        ->and($query['response-content-disposition'])->toBe('attachment; filename="storage-check.txt"')
        ->and($query['response-cache-control'])->toBe('no-store, private')
        ->and(parse_url($this->fetched[0], PHP_URL_PATH))->toBe('/leads-bucket/'.$put['params']['Key']);
});

it('fails on stored headers that would let the object open in a page, and still deletes it', function (array $head, string $reason): void {
    lscDisk($this, fn (string $name): ?Result => $name === 'HeadObject' ? new Result($head) : null);
    lscLink($this);

    $this->artisan('forms:check-file-storage')->expectsOutput('FAIL: '.$reason)->assertExitCode(1);

    expect(lscNames($this))->toBe(['PutObject', 'HeadObject', 'DeleteObject'])
        ->and($this->fetched)->toHaveCount(0);
})->with([
    'shown as text' => [['ContentType' => 'text/html', 'ContentDisposition' => 'attachment'], 'the stored object is not kept as application/octet-stream'],
    'no disposition' => [['ContentType' => 'application/octet-stream'], 'the stored object is not kept as an attachment'],
    'inline' => [['ContentType' => 'application/octet-stream', 'ContentDisposition' => 'inline'], 'the stored object is not kept as an attachment'],
]);

it('fails on a signed link that is not answered as a saved download, and still deletes the object', function (array $headers, int $status, string $body, string $reason): void {
    lscDisk($this);
    lscLink($this, $headers, $status, $body);

    $this->artisan('forms:check-file-storage')
        ->expectsOutput('FAIL: '.$reason)
        ->doesntExpectOutputToContain('http')
        ->assertExitCode(1);

    expect(lscNames($this))->toBe(['PutObject', 'HeadObject', 'DeleteObject']);
})->with([
    'refused' => [[], 403, 'denied', 'the signed link answered 403, not 200'],
    'other bytes' => [[], 200, 'something else', 'the signed link did not return the check object'],
    'shown as text' => [['Content-Type' => 'text/plain'], 200, LSC_BODY, 'the signed link is not answered as application/octet-stream'],
    'inline' => [['Content-Disposition' => 'inline; filename="x.txt"'], 200, LSC_BODY, 'the signed link is not answered as an attachment'],
    'attachment then inline' => [['Content-Disposition' => 'attachment, inline'], 200, LSC_BODY, 'the signed link is not answered as an attachment'],
    'cacheable' => [['Cache-Control' => 'public, max-age=3600'], 200, LSC_BODY, 'the signed link is not answered as no-store'],
]);

it('fails without a word of the bucket when the object cannot be written, and has nothing to delete', function (): void {
    lscDisk($this, fn (string $name, array $params): ?S3Exception => $name === 'PutObject'
        ? new S3Exception('Access denied to https://bucket-endpoint.example.test/leads-bucket/'.$params['Key'], new Command($name))
        : null);
    lscLink($this);

    $this->artisan('forms:check-file-storage')
        ->expectsOutput('FAIL: could not write the check object')
        ->doesntExpectOutputToContain('bucket-endpoint')
        ->assertExitCode(1);

    expect(lscNames($this))->not->toContain('DeleteObject')->and($this->fetched)->toHaveCount(0);
});

it('fails when the check object cannot be deleted, even after every other step passed', function (): void {
    lscDisk($this, fn (string $name): ?S3Exception => $name === 'DeleteObject' ? new S3Exception('denied', new Command($name)) : null);
    lscLink($this);

    $this->artisan('forms:check-file-storage')->expectsOutput('FAIL: could not delete the check object')->assertExitCode(1);
});

it('fails on a lead-files disk that is not a bucket', function (): void {
    Storage::fake('leads');

    $this->artisan('forms:check-file-storage')->expectsOutput('FAIL: the lead files disk is not an S3 disk')->assertExitCode(1);
});
