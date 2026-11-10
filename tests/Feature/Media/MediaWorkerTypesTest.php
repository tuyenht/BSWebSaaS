<?php

use App\Modules\Media\Actions\UploadMedia;

// The media Worker serves a key only when its extension is in deploy/media-worker/src/keys.js, with
// the type listed there. A type the library starts to accept but the Worker does not list would be
// stored fine and then answer 404 on the public host, so the two lists are checked together here.

/** @return array<string, string> extension => Content-Type, as the Worker lists them */
function mediaWorkerTypes(): array
{
    $source = (string) file_get_contents(base_path('deploy/media-worker/src/keys.js'));
    preg_match_all("/\\['([a-z0-9]+)',\\s*'([a-z0-9.+\\/-]+)'\\]/", $source, $matches, PREG_SET_ORDER);

    $types = [];
    foreach ($matches as $match) {
        $types[$match[1]] = $match[2];
    }

    return $types;
}

it('serves every file type the media library accepts, under the same type', function (): void {
    $types = mediaWorkerTypes();

    expect($types)->not->toBeEmpty();
    foreach (UploadMedia::MIME_EXT as $mime => $ext) {
        expect($types)->toHaveKey($ext);
        expect($types[$ext])->toBe($mime);
    }
});

it('never lists a type a browser could run as a page or a script', function (): void {
    foreach (mediaWorkerTypes() as $ext => $type) {
        expect($type)->not->toContain('html')
            ->and($type)->not->toContain('xml')
            ->and($type)->not->toContain('javascript')
            ->and($ext)->not->toBeIn(['html', 'htm', 'svg', 'xml', 'js', 'mjs']);
    }
});
