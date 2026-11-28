<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Forms\Support\LeadFileKinds;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Shared\Support\Bytes;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Collection;

/**
 * A lead's «Tệp đính kèm» as the inbox lists them (board «Khách liên hệ»): each file's name, what
 * kind of file it is and its size («Bản vẽ 3D (STEP) · 2,4 MB»), its icon, and for a file that was
 * not kept why, as far as its row tells: over the field's number of files (the files past it), larger
 * than the limit, else not of the kind the field takes (a wrong or disguised file, or one the
 * browser could not send whole). The reasons are read against today's limits (the field's number of
 * files, the size limit): a row keeps no reason of its own.
 */
final class LeadFileRows
{
    /** @var array<string, string> extension => its kind's label key (leads.file_type.*) */
    private const array TYPES = [
        'step' => 'step', 'stp' => 'step', 'iges' => 'iges', 'igs' => 'iges', 'dwg' => 'dwg', 'dxf' => 'dxf',
        'pdf' => 'pdf', 'doc' => 'word', 'docx' => 'word', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png',
    ];

    /**
     * @param  Collection<int, FormSubmissionFile>  $files  the lead's files, in the order they came
     * @return list<array{ulid: string, name: string, meta: string, icon: string, bad: bool, reason: string}>
     */
    public static function of(Collection $files, ?Form $form, string $locale): array
    {
        $fields = $form?->fields->keyBy('key');
        $seen = [];
        $out = [];
        foreach ($files as $file) {
            $n = $seen[$file->field_key] = ($seen[$file->field_key] ?? 0) + 1;
            $field = $fields?->get($file->field_key);
            $bad = $file->status !== FormSubmissionFile::STORED;
            $extension = LeadFileKinds::extension($file->original_name);
            $out[] = [
                'ulid' => $file->ulid,
                'name' => $file->original_name,
                'meta' => self::type($extension).' · '.self::size($file->size_bytes, $locale),
                'icon' => $bad ? 'ri-file-forbid' : ($extension === 'pdf' ? 'ri-file-pdf-2' : 'ri-file-3'),
                'bad' => $bad,
                'reason' => ! $bad ? '' : Cast::string(match (true) {
                    $field !== null && $field->type === FormFieldTypes::FILE && $n > FormFieldTypes::maxFiles($field) => __('leads.file_over', ['n' => FormFieldTypes::maxFiles($field)]),
                    $file->size_bytes > LeadFileStorage::maxBytes() => __('leads.file_too_big', ['mb' => intdiv(LeadFileStorage::maxBytes(), 1024 * 1024)]),
                    default => __('leads.file_bad'),
                }),
            ];
        }

        return $out;
    }

    /** «2,4 MB», «860 KB»: the size as people read it, in the page's number format. */
    public static function size(int $bytes, string $locale): string
    {
        return Bytes::size($bytes, $locale);
    }

    private static function type(?string $extension): string
    {
        $key = self::TYPES[$extension ?? ''] ?? null;

        return $key !== null ? Cast::string(__('leads.file_type.'.$key)) : ($extension !== null ? mb_strtoupper($extension) : Cast::string(__('leads.file_type.none')));
    }
}
