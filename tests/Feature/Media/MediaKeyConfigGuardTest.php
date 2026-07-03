<?php

declare(strict_types=1);

use App\Modules\Media\Support\MediaKey;

/*
 * MediaKey::assertConfigured() fails LOUD + clean when the media disk is an S3-family disk with no
 * bucket (R2 creds missing from .env) — instead of the cryptic "bucket must be string, null given"
 * TypeError deep in the adapter that silently broke upload. Called at the upload entry points.
 */

it('throws a clear error when the r2 media disk has no bucket', function (): void {
    config(['media.disk' => 'r2', 'filesystems.disks.r2.driver' => 's3', 'filesystems.disks.r2.bucket' => null]);

    expect(fn () => MediaKey::assertConfigured())->toThrow(RuntimeException::class);
});

it('passes when the r2 disk has a bucket', function (): void {
    config(['media.disk' => 'r2', 'filesystems.disks.r2.driver' => 's3', 'filesystems.disks.r2.bucket' => 'a-bucket']);

    MediaKey::assertConfigured();
    expect(true)->toBeTrue(); // reached without throwing
});

it('ignores a non-s3 disk (local) even without a bucket', function (): void {
    config(['media.disk' => 'local', 'filesystems.disks.local.driver' => 'local']);

    MediaKey::assertConfigured();
    expect(true)->toBeTrue();
});
