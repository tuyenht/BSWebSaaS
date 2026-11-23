<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\LeadFileKinds;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Keeps one file a visitor sent with a lead, for the form field `$fieldKey` of kind `$kind`
 * (LeadFileKinds::KINDS). Called in the request that stored the lead, in the lead's own website.
 *
 *  - A spam lead keeps nothing: no object, no row (null comes back).
 *  - A file over LeadFileStorage::maxBytes(), with a blocked name part, of a type the kind does not
 *    take, or whose bytes do not carry its type's signature is recorded as `rejected`: its name and
 *    size, no object, no key, so the lead's detail can say it was not taken. The lead stays.
 *  - Any other file is streamed from the upload's temporary file to the private disk under
 *    `leads/{site ulid}/{file ulid}` (never read whole into memory), then its `stored` row is
 *    written. When the object cannot be written nothing is recorded and a RuntimeException is
 *    thrown: the caller decides what the visitor is told.
 *
 * The object is written before its row, inside the caller's transaction. If that transaction rolls
 * back, the object is deleted again (afterRollBack); if the row cannot be written, at once. A worker
 * killed between the write and the commit leaves one object behind that no row points at: its key
 * names no one, but its body is the visitor's file, and neither the sweeper nor an erase can reach
 * it: forms:reconcile-files (ReconcileLeadFiles) lists `leads/{site ulid}/` against the rows daily
 * and deletes such objects once they are over an hour old.
 */
final class StoreLeadFile
{
    public function handle(FormSubmission $lead, string $fieldKey, string $kind, UploadedFile $file): ?FormSubmissionFile
    {
        if (! array_key_exists($kind, LeadFileKinds::KINDS)) {
            throw new InvalidArgumentException("Unknown file kind [{$kind}].");
        }
        if ($fieldKey === '' || strlen($fieldKey) > 40) {
            throw new InvalidArgumentException('A field key is 1 to 40 characters.');
        }
        // a file row with no lead would be swept as an orphan at once, and its object with it
        if (! $lead->exists) {
            throw new LogicException('A lead\'s files are kept only once the lead is saved.');
        }
        $current = app(CurrentSite::class);
        if (! $current->hasId() || $current->id() !== $lead->site_id) {
            throw new LogicException('A lead\'s files are kept in the lead\'s own website.');
        }
        if ($lead->is_spam || $lead->status === 'spam') {
            return null;
        }

        $name = LeadFileKinds::cleanName($file->getClientOriginalName());
        $path = $file->isValid() ? $file->getRealPath() : false;
        $size = $path === false ? self::sentSize($file) : max(0, (int) filesize($path));

        $refused = $path === false
            || $size > LeadFileStorage::maxBytes()
            || LeadFileKinds::refusal($kind, $name, $path) !== null;
        if ($refused) {
            return $this->rejected($lead, $fieldKey, $name, $size);
        }

        $extension = Cast::string(LeadFileKinds::extension($name));
        $ulid = (string) Str::ulid();
        $key = LeadFileStorage::key(Cast::string(DB::table('sites')->where('id', $lead->site_id)->value('ulid')), $ulid);
        $disk = LeadFileStorage::disk();

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }
        try {
            $written = $disk->writeStream($key, $stream, LeadFileStorage::OBJECT_OPTIONS);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($written !== true) {
            throw new RuntimeException('The file could not be written to the lead files disk.');
        }
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(static function () use ($key): void {
                LeadFileStorage::disk()->delete($key);
            });
        }

        try {
            return FormSubmissionFile::query()->create([
                'ulid' => $ulid,
                'form_submission_id' => $lead->getKey(),
                'field_key' => $fieldKey,
                'original_name' => $name,
                'mime' => LeadFileKinds::MIME[$extension] ?? null,
                'size_bytes' => $size,
                'storage_key' => $key,
                'status' => FormSubmissionFile::STORED,
            ]);
        } catch (Throwable $e) {
            $disk->delete($key);

            throw $e;
        }
    }

    /**
     * Records $file as refused without looking at it: a file over the field's «Số tệp tối đa».
     * Its bytes are never read or kept; a spam lead keeps nothing (null), as in handle().
     */
    public function refuse(FormSubmission $lead, string $fieldKey, UploadedFile $file): ?FormSubmissionFile
    {
        if ($fieldKey === '' || strlen($fieldKey) > 40) {
            throw new InvalidArgumentException('A field key is 1 to 40 characters.');
        }
        if (! $lead->exists) {
            throw new LogicException('A lead\'s files are kept only once the lead is saved.');
        }
        $current = app(CurrentSite::class);
        if (! $current->hasId() || $current->id() !== $lead->site_id) {
            throw new LogicException('A lead\'s files are kept in the lead\'s own website.');
        }
        if ($lead->is_spam || $lead->status === 'spam') {
            return null;
        }

        return $this->rejected($lead, $fieldKey, LeadFileKinds::cleanName($file->getClientOriginalName()), self::sentSize($file));
    }

    private function rejected(FormSubmission $lead, string $fieldKey, string $name, int $size): FormSubmissionFile
    {
        return FormSubmissionFile::query()->create([
            'form_submission_id' => $lead->getKey(),
            'field_key' => $fieldKey,
            'original_name' => $name,
            'mime' => null,
            'size_bytes' => $size,
            'storage_key' => null,
            'status' => FormSubmissionFile::REJECTED,
        ]);
    }

    /**
     * The size of an upload as received: the temporary file's, else the size the browser gave. An
     * upload PHP refused has no temporary file to ask (asking throws), and PHP reports 0 for one
     * over its own limits: such a file is recorded at the least it can have been, one byte over the
     * larger of the two limits, so that it reads as too large rather than as an empty file.
     */
    private static function sentSize(UploadedFile $file): int
    {
        try {
            $path = $file->getRealPath();
            $size = max(0, (int) ($path !== false && $path !== '' && is_file($path) ? filesize($path) : $file->getSize()));
        } catch (Throwable) {
            $size = 0;
        }
        if ($size === 0 && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return max(LeadFileStorage::maxBytes(), (int) min(UploadedFile::getMaxFilesize(), PHP_INT_MAX - 1)) + 1;
        }

        return $size;
    }
}
