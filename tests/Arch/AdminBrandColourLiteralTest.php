<?php

use App\Modules\Admin\Theme\AdminBrandColor;
use Illuminate\Support\Facades\File;

/*
 * The admin brand colour comes from ONE table, resources/admin-brands.json: the admin layouts print
 * the platform's row as :root tokens (--color-primary-base, --color-primary, --color-primary-text),
 * and everything else derives from those. A view, stylesheet or script that copies one of the
 * table's colours keeps it when the brand changes — the palette has already changed three times.
 * Use the tokens instead: bg-primary / text-primary-text, or var(--color-primary) in a value.
 *
 * The one copy allowed is app.css @theme's build-time default, which must equal the default row.
 */

/** Hex colours of the brand table (brand, solid and ink of every row), lowercase, unique. */
function adminBrandLiterals(): array
{
    $hexes = [];
    foreach (AdminBrandColor::table() as $row) {
        array_push($hexes, $row['brand'], $row['solid'], $row['ink']);
    }

    return array_values(array_unique($hexes));
}

/** One regex for every table colour as #rrggbb or as rgb channels ("64 81 137" / "64, 81, 137"). */
function adminBrandLiteralPattern(): string
{
    $alternatives = [];
    foreach (adminBrandLiterals() as $hex) {
        $alternatives[] = preg_quote($hex, '/').'(?![0-9a-f])';
        $channels = array_map('hexdec', str_split(substr($hex, 1), 2));
        $alternatives[] = '(?<![\d.])'.implode('(?:\s*,\s*|\s+)', $channels).'(?![\d.])';
    }

    return '/'.implode('|', $alternatives).'/i';
}

/**
 * Places that may carry a table colour for a reason other than the brand, by path => colour.
 * Each must say why.
 */
const ADMIN_BRAND_LITERAL_EXCEPTIONS = [
    // The author-avatar palette of the content list: six fixed category hues (Tailwind indigo-700,
    // pink-700…), one of which happens to equal a table colour. Not the brand; never follows it.
    'app/Modules/Admin/resources/views/livewire/entries/index.blade.php' => ['#4338ca'],
];

/** The @theme declarations that hold the build-time default, by token => table field. */
const ADMIN_BRAND_THEME_DEFAULTS = [
    '--color-primary-base' => 'brand',
    '--color-primary' => 'solid',
    '--color-primary-text' => 'ink',
];

it('reads a brand table with the default colour first', function (): void {
    $table = AdminBrandColor::table();

    expect($table)->toHaveCount(9)
        ->and(array_key_first($table))->toBe(AdminBrandColor::DEFAULT)
        ->and(adminBrandLiterals())->not->toBeEmpty();
});

it('copies no brand table colour into an admin view, stylesheet or script', function (): void {
    $pattern = adminBrandLiteralPattern();
    $files = [];
    foreach (File::allFiles(base_path('app')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = 'app/'.$file->getRelativePathname();
        }
    }
    foreach (File::allFiles(base_path('resources')) as $file) {
        if (preg_match('/\.(blade\.php|css|js|mjs)$/', $file->getFilename()) === 1) {
            $files[] = 'resources/'.$file->getRelativePathname();
        }
    }

    $violations = [];
    foreach ($files as $path) {
        // The storefront has its own per-site brand (ThemeResolver); the admin table is not its source.
        if ($path === 'resources/css/storefront.css') {
            continue;
        }
        $content = File::get(base_path($path));
        if ($path === 'resources/css/app.css') {
            // The @theme default is checked against the table below; drop exactly those lines.
            $content = (string) preg_replace('/^\s*(?:'.implode('|', array_map(
                static fn (string $token): string => preg_quote($token, '/'),
                array_keys(ADMIN_BRAND_THEME_DEFAULTS),
            )).'):\s*#[0-9a-f]{6};\s*$/mi', '', $content);
        }
        preg_match_all($pattern, $content, $matches);
        $found = array_diff(
            array_unique(array_map('strtolower', $matches[0])),
            ADMIN_BRAND_LITERAL_EXCEPTIONS[$path] ?? [],
        );
        if ($found !== []) {
            $violations[] = $path.': '.implode(', ', $found);
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the app.css build-time default equal to the default row of the table', function (): void {
    $css = File::get(resource_path('css/app.css'));
    $default = AdminBrandColor::table()[AdminBrandColor::DEFAULT];

    foreach (ADMIN_BRAND_THEME_DEFAULTS as $token => $field) {
        preg_match('/^\s*'.preg_quote($token, '/').':\s*(#[0-9a-f]{6});/mi', $css, $match);
        expect(strtolower($match[1] ?? ''))->toBe($default[$field], "{$token} in app.css @theme");
    }
});
