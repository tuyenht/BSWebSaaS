<?php

use App\Modules\Media\Models\Media;

/*
 * A file's type and size as a visitor reads them on a download button: the unit follows the
 * rounded KB, so a file just under 1 MB never reads "1.024 KB", and a type the library has no
 * label for still shows one (its mime subtype), never an empty type.
 */

it('labels a file with its type and a size in KB or MB', function (string $mime, int $bytes, string $label): void {
    expect(Media::typeAndSize($mime, $bytes))->toBe($label);
})->with([
    'a tiny file is at least 1 KB' => ['application/pdf', 10, 'PDF · 1 KB'],
    'the last size that rounds below 1.024 KB' => ['application/pdf', 1_048_063, 'PDF · 1.023 KB'],
    'the first size that rounds to 1.024 KB' => ['application/pdf', 1_048_064, 'PDF · 1,0 MB'],
    'one byte under 1 MB' => ['application/pdf', 1_048_575, 'PDF · 1,0 MB'],
    'exactly 1 MB' => ['application/pdf', 1_048_576, 'PDF · 1,0 MB'],
    'a large file' => ['application/pdf', 9_448_965, 'PDF · 9,0 MB'],
    'a Word document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 122_880, 'DOCX · 120 KB'],
    'an unmapped type shows its subtype' => ['application/zip', 9_448_965, 'ZIP · 9,0 MB'],
    'no type at all shows the size alone' => ['', 2048, '2 KB'],
]);
