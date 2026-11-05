<?php

use App\Modules\Admin\Support\Locales;

/*
 * A language without a configured label is named in its own language, first letter capitalised
 * letter-wise, so a non-ASCII first letter is capitalised too.
 */
it('capitalises a native language name whose first letter is not ASCII', function (string $code, string $label): void {
    config(["locales.labels.$code" => null]);

    expect(Locales::label($code))->toBe($label);
})->with([
    'Czech' => ['cs', 'Čeština'],
    'Greek' => ['el', 'Ελληνικά'],
    'Ukrainian' => ['uk', 'Українська'],
])->skip(fn (): bool => ! class_exists(Locale::class), 'needs the intl extension');
