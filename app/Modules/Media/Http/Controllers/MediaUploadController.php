<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Direct-to-R2 upload endpoints for the DAM (P1). `presign` issues a short-lived R2 PUT URL; the
 * browser PUTs straight to R2 (no PHP upload limit / Livewire-temp / proxy); `register` validates +
 * records the row. `upload` is the local-disk fallback (dev) that proxies the file through Laravel.
 * All sit in the tenant admin group (auth + SetCurrentSite → RLS) and re-gate media.upload.
 */
final class MediaUploadController
{
    public function presign(Request $request, PresignMediaUpload $presign, CurrentSite $current): JsonResponse
    {
        $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $presign->handle(
                (string) $request->string('filename'),
                (string) $request->string('mime'),
                $request->integer('size'),
                $this->siteName($current),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function register(Request $request, RegisterMediaUpload $register): JsonResponse
    {
        $request->validate([
            'tempKey' => ['required', 'string', 'max:255'],
            'filename' => ['required', 'string', 'max:255'],
            'width' => ['nullable', 'integer'],
            'height' => ['nullable', 'integer'],
        ]);

        try {
            $media = $register->handle(
                (string) $request->string('tempKey'),
                (string) $request->string('filename'),
                $this->actorId(),
                $request->has('width') ? $request->integer('width') : null,
                $request->has('height') ? $request->integer('height') : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ulid' => $media->ulid]);
    }

    public function upload(Request $request, UploadMedia $upload, CurrentSite $current): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:'.UploadMedia::MAX_KB],
        ]);

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'Tệp không hợp lệ.'], 422);
        }

        try {
            $media = $upload->handle($file, $this->siteName($current), 'library', $this->actorId());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ulid' => $media->ulid]);
    }

    private function siteName(CurrentSite $current): string
    {
        return (string) Site::query()->find($current->id())?->name;
    }

    private function actorId(): ?int
    {
        $id = Auth::id();

        return is_int($id) ? $id : null;
    }
}
