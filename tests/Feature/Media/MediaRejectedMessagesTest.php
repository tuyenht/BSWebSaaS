<?php

declare(strict_types=1);

use App\Modules\Media\Exceptions\MediaRejectedException;

/*
 * Every reason a file or a link can be refused has its sentence in every interface language — the
 * upload screens show MediaRejectedException::userMessage() as it is, so a missing key would put a
 * raw "media.err_…" key in front of the person uploading.
 */

it('has a sentence for every refusal reason in every interface language', function (): void {
    $locales = array_map(static fn (string $file): string => basename($file, '.json'), glob(lang_path('*.json')) ?: []);
    expect($locales)->not->toBeEmpty();

    foreach ($locales as $locale) {
        app()->setLocale($locale);
        foreach (MediaRejectedException::REASONS as $reason) {
            $message = new MediaRejectedException($reason, ['status' => 404])->userMessage();

            expect($message)->not->toStartWith('media.', "{$locale}: {$reason}")
                ->and($message)->not->toContain(':status', "{$locale}: {$reason}");
        }
    }
});
