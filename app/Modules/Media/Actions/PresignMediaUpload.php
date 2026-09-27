<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issues a short-lived presigned direct-to-R2 PUT for a browser upload (DAM P1). The bytes go
 * straight to R2 under an opaque temp key, so PHP's upload limit, the Livewire-temp endpoint and the
 * reverse-proxy never touch them. The client-declared mime/size are validated here ONLY for fast
 * feedback — the real gate is RegisterMediaUpload. The temp key's site ownership is recorded so a
 * foreign key cannot later be registered (cross-tenant guard).
 */
final readonly class PresignMediaUpload
{
    public function __construct(private CurrentSite $current) {}

    /**
     * @return array{url: string, headers: array<string, mixed>, tempKey: string}
     */
    public function handle(string $filename, string $declaredMime, int $declaredSize, string $siteName): array
    {
        MediaKey::assertConfigured(); // fail loud + clean if the R2 disk has no bucket/creds (.env drift)

        $ext = UploadMedia::MIME_EXT[$declaredMime] ?? null;
        if ($ext === null) {
            throw new MediaRejectedException('unsupported_type');
        }
        if ($declaredSize > UploadMedia::MAX_KB * 1024) {
            throw new MediaRejectedException('too_large');
        }

        // Leading `_incoming/` prefix — the site slug is nested INSIDE it, not before it — so ONE R2
        // lifecycle rule on prefix `_incoming/` can expire every site's abandoned staging uploads.
        // Do NOT move the site slug ahead of `_incoming/`: that makes the key un-coverable by a single
        // rule (a nested `{site}/_incoming/` segment is not a left-anchored prefix).
        $tempKey = '_incoming/'.Str::slug($siteName).'/'.Str::ulid().'.'.$ext;

        // Cross-tenant guard: record which site this temp key belongs to (register verifies + pulls).
        Cache::put(self::ownershipKey($tempKey), $this->current->id(), now()->addMinutes(5));

        /** @var array{url: string, headers: array<string, mixed>} $signed */
        $signed = Storage::disk(MediaKey::disk())->temporaryUploadUrl($tempKey, now()->addMinutes(5));

        return ['url' => $signed['url'], 'headers' => $signed['headers'], 'tempKey' => $tempKey];
    }

    public static function ownershipKey(string $tempKey): string
    {
        return 'media:tempkey-owner:'.$tempKey;
    }
}
