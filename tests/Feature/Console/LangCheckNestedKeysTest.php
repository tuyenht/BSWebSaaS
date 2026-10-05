<?php

use Illuminate\Support\Facades\File;

/*
 * validation.attributes is one JSON key holding an object of field names. lang:check used to
 * compare top-level keys only, so a field name added to en.json alone passed and then showed as
 * the raw code name to every other language. Nested keys are compared one by one now.
 */

it('fails when a nested field name exists in the base language only', function (): void {
    $dir = sys_get_temp_dir().'/lang-check-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    File::put($dir.'/en.json', json_encode(['a' => 'A', 'validation.attributes' => ['x' => 'x', 'y' => 'y']]));
    File::put($dir.'/vi.json', json_encode(['a' => 'Á', 'validation.attributes' => ['x' => 'ích']]));
    app()->useLangPath($dir);

    try {
        $this->artisan('lang:check')
            ->expectsOutputToContain('validation.attributes.y')
            ->assertFailed();
    } finally {
        File::deleteDirectory($dir);
    }
});
