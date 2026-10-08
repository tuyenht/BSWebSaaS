<?php

use App\Modules\Admin\Theme\AdminBrandColor;
use App\Modules\Theme\Theming\BrandPalette;
use App\Modules\Theme\Theming\ReadableColor;

/*
 * The admin brand colour comes from one table (resources/admin-brands.json). The platform picks a
 * row by key; anything else falls back to the default row, and only the table's #rrggbb values
 * ever reach the printed :root block.
 */

function adminBrandFixture(string $json): string
{
    $path = tempnam(sys_get_temp_dir(), 'brands');
    file_put_contents($path, $json);

    return $path;
}

it('holds the nine approved colours, Bảo Sơn first', function (): void {
    $table = AdminBrandColor::table();

    expect(array_keys($table))->toBe(['bao-son', 'navy', 'blue', 'cyan', 'teal', 'green', 'indigo', 'violet', 'plum'])
        ->and($table['bao-son'])->toBe(['name' => 'Bảo Sơn', 'brand' => '#2050a0', 'solid' => '#2050a0', 'ink' => '#2050a0'])
        ->and($table['blue'])->toBe(['name' => 'Xanh dương', 'brand' => '#2f6fd0', 'solid' => '#1859b9', 'ink' => '#2465c5']);
});

it('keeps every row readable: white on the fill, the ink on white and on the page', function (string $key, array $row): void {
    expect(ReadableColor::ratio('#ffffff', $row['solid']))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::ratio($row['ink'], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(ReadableColor::ratio($row['ink'], '#f4f5fa'))->toBeGreaterThanOrEqual(4.5);
})->with(function (): array {
    $cases = [];
    foreach (AdminBrandColor::table() as $key => $row) {
        $cases[$key] = [$key, $row];
    }

    return $cases;
});

it('prints the chosen row: base hue, fill, light ink and the palette of the base hue', function (): void {
    $variables = AdminBrandColor::cssVariables('green');
    $palette = BrandPalette::from('#15803d');

    expect(array_slice($variables, 0, 3, true))->toBe([
        'color-primary-base' => '#15803d',
        'color-primary' => '#056b30',
        'color-primary-text' => '#0a7636',
    ])->and($palette)->not->toBeNull();

    foreach ((array) $palette as $step => $color) {
        expect($variables["color-brand-{$step}"])->toBe($color);
    }
    expect($variables)->toHaveCount(13);
});

it('falls back to the default row for an unknown, empty or non-string key', function (mixed $key): void {
    expect(AdminBrandColor::pick($key)['key'] ?? null)->toBe(AdminBrandColor::DEFAULT)
        ->and(AdminBrandColor::cssVariables($key)['color-primary'])->toBe('#2050a0');
})->with([
    'unknown' => ['hotdog'],
    'empty' => [''],
    'null' => [null],
    'array' => [['navy']],
    'css injection' => ['navy;}</style><script>'],
    'case' => ['NAVY'],
]);

it('ships the config with the same default key', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 3).'/config/admin.php');

    expect($config)->toContain("'color' => env('ADMIN_BRAND_COLOR', '".AdminBrandColor::DEFAULT."')");
});

it('leaves out a malformed row, so nothing but a #rrggbb colour can be printed', function (): void {
    $path = adminBrandFixture((string) json_encode(['brands' => [
        ['key' => 'evil', 'name' => 'x', 'brand' => 'red;}body{display:none', 'solid' => '#000000', 'ink' => '#000000'],
        ['key' => 'Bad Key', 'name' => 'x', 'brand' => '#111111', 'solid' => '#111111', 'ink' => '#111111'],
        ['key' => 'short', 'name' => 'x', 'brand' => '#123', 'solid' => '#123456', 'ink' => '#123456'],
        ['key' => 'ok', 'name' => 'Ổn', 'brand' => '#123456', 'solid' => '#123456', 'ink' => '#123456'],
        'not a row',
    ]]));

    expect(array_keys(AdminBrandColor::table($path)))->toBe(['ok'])
        ->and(AdminBrandColor::pick('evil', $path)['key'] ?? null)->toBe('ok'); // no default row → the first valid one

    foreach (AdminBrandColor::cssVariables('evil', $path) as $name => $value) {
        expect($name)->toMatch('/^color-[a-z0-9-]+$/')->and($value)->toMatch('/^#[0-9a-f]{6}$/');
    }
    unlink($path);
});

it('prints nothing when the table is missing or broken', function (): void {
    $path = adminBrandFixture('{not json');

    expect(AdminBrandColor::table($path))->toBe([])
        ->and(AdminBrandColor::pick('navy', $path))->toBeNull()
        ->and(AdminBrandColor::cssVariables('navy', $path))->toBe([])
        ->and(AdminBrandColor::cssVariables('navy', '/nonexistent/admin-brands.json'))->toBe([]);
    unlink($path);
});
