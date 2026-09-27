<?php

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Direct-to-R2 upload endpoints for the DAM (P1). `presign` issues a short-lived R2 PUT URL; the
 * browser PUTs straight to R2 (no PHP upload limit / Livewire-temp / proxy); `register` validates +
 * records the row. `upload` is the local-disk fallback (dev) that proxies the file through Laravel.
 * All sit in the tenant admin group (auth + SetCurrentSite → RLS) and re-gate media.upload.
 *
 * These are a fetch()/JSON API for the Uppy island, so validation + domain errors are returned as
 * JSON 422 here — the app renders framework ValidationExceptions as JSON only for api/* (see
 * bootstrap/app.php), and a redirect-back would be meaningless to a fetch client. Only a refusal
 * (MediaRejectedException) is shown as it is, in the admin's language; any other failure is logged
 * and answered with a plain "try again" (500) — a storage SDK's message can name buckets and paths.
 */
final class MediaUploadController
{
    public function presign(Request $request, PresignMediaUpload $presign, CurrentSite $current): JsonResponse
    {
        try {
            $request->validate([
                'filename' => ['required', 'string', 'max:255'],
                'mime' => ['required', 'string', 'max:100'],
                'size' => ['required', 'integer', 'min:1'],
            ]);

            $result = $presign->handle(
                (string) $request->string('filename'),
                (string) $request->string('mime'),
                $request->integer('size'),
                $this->siteName($current),
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (MediaRejectedException $e) {
            return response()->json(['message' => $e->userMessage()], 422);
        } catch (RuntimeException $e) {
            return $this->unexpected($e);
        }

        return response()->json($result);
    }

    public function register(Request $request, RegisterMediaUpload $register): JsonResponse
    {
        try {
            $request->validate([
                'tempKey' => ['required', 'string', 'max:255'],
                'filename' => ['required', 'string', 'max:255'],
                'width' => ['nullable', 'integer'],
                'height' => ['nullable', 'integer'],
            ]);

            $media = $register->handle(
                (string) $request->string('tempKey'),
                (string) $request->string('filename'),
                $this->actorId(),
                $request->has('width') ? $request->integer('width') : null,
                $request->has('height') ? $request->integer('height') : null,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (MediaRejectedException $e) {
            return response()->json(['message' => $e->userMessage()], 422);
        } catch (RuntimeException $e) {
            return $this->unexpected($e);
        }

        return response()->json(['ulid' => $media->ulid]);
    }

    public function upload(Request $request, UploadMedia $upload, CurrentSite $current): JsonResponse
    {
        try {
            $request->validate([
                'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:'.UploadMedia::MAX_KB],
            ]);

            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                return response()->json(['message' => __('media.err_file_invalid')], 422);
            }

            $media = $upload->handle($file, $this->siteName($current), 'library', $this->actorId());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (MediaRejectedException $e) {
            return response()->json(['message' => $e->userMessage()], 422);
        } catch (RuntimeException $e) {
            return $this->unexpected($e);
        }

        return response()->json(['ulid' => $media->ulid]);
    }

    /** A failure the person uploading cannot act on: logged for the operator, a plain "try again" for them. */
    private function unexpected(RuntimeException $e): JsonResponse
    {
        report($e);

        return response()->json(['message' => __('media.err_unexpected')], 500);
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
