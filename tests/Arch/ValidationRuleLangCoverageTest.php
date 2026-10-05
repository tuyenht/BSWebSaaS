<?php

use Illuminate\Support\Facades\File;

/*
 * Laravel's validator falls back to raw English the moment a lang/<locale>/validation.php file
 * is missing for the active locale — and this repo deliberately has none (lang/*.json only,
 * lang:check enforces equal keys across them). A rule used somewhere in app/ without a matching
 * "validation.<rule>" line in lang/vi.json would silently leak English to Vietnamese admins. This
 * scans every PHP file under app/ for the rule names listed below and fails when one of them is
 * used without its vi.json line. The list is the rules this product uses today, not all of
 * Laravel's: a quoted-string scan over the whole set would also match casts ('date', 'boolean',
 * 'json'). Using a rule that is not listed here means adding it here and its line to lang/*.json.
 */

it('has a vi.json message for every validation rule name used in app/', function (): void {
    $vi = json_decode(File::get(lang_path('vi.json')), true);

    // Rule names Laravel's validator resolves by literal string — 'field' => ['required', ...]
    // or 'field' => 'required|max:120'. A fixed, known-rule-name list so the scan can't flag an
    // unrelated quoted string elsewhere in the app.
    $stringRules = [
        'required', 'string', 'email', 'confirmed', 'integer', 'array', 'accepted',
        'required_without', 'required_with', 'current_password', 'file', 'mimes', 'regex',
    ];

    // max/min need a .string/.numeric/.file/.array suffix — Laravel never looks up a bare
    // "validation.max" key for these (FormatsMessages::getSizeMessage).
    $sizeRules = ['max', 'min'];

    // Rule objects that never appear as a literal rule string in this codebase — matched by call
    // site instead.
    $objectRules = [
        'Rule::in(' => 'in',
        'Rule::exists(' => 'exists',
        'Rule::unique(' => 'unique',
        '->uncompromised(' => 'password.uncompromised',
    ];

    $found = [];
    foreach (File::allFiles(base_path('app')) as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $content = File::get($file->getPathname());

        // A rule can sit anywhere in a pipe-delimited string ('nullable|string|required_with:x'),
        // so the boundary on each side is a quote OR a pipe, not only the string's own quotes.
        foreach ([...$stringRules, ...$sizeRules] as $rule) {
            if (preg_match('/[\'"|]'.preg_quote($rule, '/').'(:[^\'"|]*)?[\'"|]/', $content)) {
                $found[$rule] = true;
            }
        }
        foreach ($objectRules as $needle => $rule) {
            if (str_contains($content, $needle)) {
                $found[$rule] = true;
            }
        }
    }

    $found = array_keys($found);
    expect($found)->not->toBeEmpty();

    $missing = [];
    foreach ($found as $rule) {
        if (in_array($rule, $sizeRules, true)) {
            $hasVariant = isset($vi["validation.{$rule}.string"])
                || isset($vi["validation.{$rule}.numeric"])
                || isset($vi["validation.{$rule}.file"])
                || isset($vi["validation.{$rule}.array"]);
            if (! $hasVariant) {
                $missing[] = "validation.{$rule}.*";
            }

            continue;
        }

        if (! isset($vi["validation.{$rule}"])) {
            $missing[] = "validation.{$rule}";
        }
    }

    expect($missing)->toBe([]);
});
