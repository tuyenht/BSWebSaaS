<?php

namespace App\Modules\Media\Actions;

use App\Modules\Media\Support\MediaKey;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues a short-lived presigned direct-to-R2 PUT for a browser upload (DAM P1). The bytes go
 * straight to R2 under an opaque temp key, so PHP's upload limit, the Livewire-temp endpoint and the
 * reverse-proxy never touch them. The client-declared mime/size are validated here ONLY for fast
 * feedback — the real gate is RegisterMediaUpload. The temp key's site ownership is recorded so a
 * foreign key cannot later be registered (cross-tenant guard).
 */
final class PresignMediaUpload
{
    public function __construct(private readonly CurrentSite $current) {}

    /**
     * @return array{url: string, headers: array<string, mixed>, tempKey: string}
     */
    public function handle(string $filename, string $declaredMime, int $declaredSize, string $siteName): array
    {
        $ext = UploadMedia::MIME_EXT[$declaredMime] ?? null;
        if ($ext === null) {
            throw new RuntimeException('Định dạng tệp không được hỗ trợ.');
        }
        if ($declaredSize > UploadMedia::MAX_KB * 1024) {
            throw new RuntimeException('Tệp vượt quá dung lượng cho phép (tối đa 10MB).');
        }

        $tempKey = Str::slug($siteName).'/_incoming/'.(string) Str::ulid().'.'.$ext;

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
