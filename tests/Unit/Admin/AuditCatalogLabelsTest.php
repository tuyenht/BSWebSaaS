<?php

use App\Modules\Admin\Support\AuditCatalog;

/*
 * Every action the activity log can show has a plain-words label in every language: a code
 * without one would show the raw key to the reader.
 */

it('has a label for every catalogued action in every language', function (string $file): void {
    $labels = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];

    foreach (AuditCatalog::GROUPS as $codes) {
        foreach ($codes as $code) {
            if (! isset($labels['admin.audit.act.'.$code])) {
                $missing[] = $code;
            }
        }
    }

    expect($missing)->toBe([]);
})->with(fn (): array => glob(dirname(__DIR__, 3).'/lang/*.json') ?: []);
