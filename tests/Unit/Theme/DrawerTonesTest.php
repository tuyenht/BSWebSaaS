<?php

use App\Modules\Theme\Theming\DrawerTones;
use App\Modules\Theme\Theming\ReadableColor;

/*
 * The phone menu's colours are derived per site from three seeds (surface, ink, brand). These cases are the
 * approved recipe's output (one case deeper, noted on it) for a spread of real and awkward palettes (the demo site, light and dark brands,
 * tinted surfaces, a grey ink, an olive ink), so a change to the derivation shows up here; every case is also
 * checked against the contrast floors on what the panel can look like over the page (DrawerTones::seen).
 */

/** @return array<string, array{0: array{string, string, string}, 1: array<string, string>, 2: bool, 3: array<string, string>, 4: bool}> */
function drawerPalettes(): array
{
    return [
        'av1' => [['#ffffff', '#0f172a', '#f59e0b'], ['bg' => '#f8f8f9', 'ink' => '#313748', 'strong' => '#0f172a', 'sub' => '#4d5361', 'muted' => '#5a5e69', 'track' => '#e3e4e6', 'hair' => '#dadbde', 'edge' => '#dadbde', 'thumb' => '#ffffff', 'pill' => '#e5e6e8', 'tap' => '#ecedef', 'press' => '#dadbde', 'ctl' => '#777a83', 'on' => '#0f172a'], true, ['bg' => '#222a3b', 'ink' => '#e4e5e7', 'strong' => '#ffffff', 'sub' => '#c6c8cc', 'muted' => '#a7aab1', 'track' => '#313949', 'hair' => '#383f4f', 'edge' => '#454c5a', 'thumb' => '#222a3b', 'pill' => '#3f4654', 'tap' => '#343b4b', 'press' => '#4c5260', 'ctl' => '#858a93', 'on' => '#ffffff'], false],
        'mặc định' => [['#ffffff', '#111111', '#3b82f6'], ['bg' => '#f8f8f8', 'ink' => '#323232', 'strong' => '#111111', 'sub' => '#4f4f4f', 'muted' => '#5e5e5e', 'track' => '#e3e3e3', 'hair' => '#dadada', 'edge' => '#dadada', 'thumb' => '#ffffff', 'pill' => '#e6e6e6', 'tap' => '#ececec', 'press' => '#dadada', 'ctl' => '#7b7b7b', 'on' => '#111111'], false, ['bg' => '#242424', 'ink' => '#e5e5e5', 'strong' => '#ffffff', 'sub' => '#c6c6c6', 'muted' => '#a7a7a7', 'track' => '#333333', 'hair' => '#3a3a3a', 'edge' => '#474747', 'thumb' => '#242424', 'pill' => '#404040', 'tap' => '#363636', 'press' => '#4e4e4e', 'ctl' => '#878787', 'on' => '#ffffff'], false],
        'vàng' => [['#ffffff', '#1c1917', '#facc15'], ['bg' => '#f8f8f8', 'ink' => '#3c3937', 'strong' => '#1c1917', 'sub' => '#575553', 'muted' => '#615f5e', 'track' => '#e4e4e4', 'hair' => '#dbdbdb', 'edge' => '#dbdbdb', 'thumb' => '#ffffff', 'pill' => '#e6e6e6', 'tap' => '#ededed', 'press' => '#dbdbdb', 'ctl' => '#7c7b7a', 'on' => '#1c1917'], true, ['bg' => '#2e2b2a', 'ink' => '#e6e6e5', 'strong' => '#ffffff', 'sub' => '#c9c8c8', 'muted' => '#adacac', 'track' => '#3d3a39', 'hair' => '#43403f', 'edge' => '#4f4d4c', 'thumb' => '#2e2b2a', 'pill' => '#494746', 'tap' => '#3f3c3b', 'press' => '#565352', 'ctl' => '#8f8d8d', 'on' => '#ffffff'], false],
        'xanh navy' => [['#ffffff', '#0b1120', '#1e3a8a'], ['bg' => '#f8f8f8', 'ink' => '#2d323f', 'strong' => '#0b1120', 'sub' => '#4a4f5a', 'muted' => '#5b5e66', 'track' => '#e3e3e5', 'hair' => '#d9dadc', 'edge' => '#d9dadc', 'thumb' => '#ffffff', 'pill' => '#e5e6e7', 'tap' => '#ececed', 'press' => '#d9dadc', 'ctl' => '#787b82', 'on' => '#0b1120'], false, ['bg' => '#1f2432', 'ink' => '#e4e5e6', 'strong' => '#ffffff', 'sub' => '#c5c6ca', 'muted' => '#a5a7ad', 'track' => '#2f3340', 'hair' => '#353a47', 'edge' => '#434753', 'thumb' => '#1f2432', 'pill' => '#3c404d', 'tap' => '#313642', 'press' => '#4a4e59', 'ctl' => '#84878e', 'on' => '#ffffff'], true],
        'đỏ' => [['#ffffff', '#111111', '#dc2626'], ['bg' => '#f8f8f8', 'ink' => '#323232', 'strong' => '#111111', 'sub' => '#4f4f4f', 'muted' => '#5e5e5e', 'track' => '#e3e3e3', 'hair' => '#dadada', 'edge' => '#dadada', 'thumb' => '#ffffff', 'pill' => '#e6e6e6', 'tap' => '#ececec', 'press' => '#dadada', 'ctl' => '#7b7b7b', 'on' => '#111111'], false, ['bg' => '#242424', 'ink' => '#e5e5e5', 'strong' => '#ffffff', 'sub' => '#c6c6c6', 'muted' => '#a7a7a7', 'track' => '#333333', 'hair' => '#3a3a3a', 'edge' => '#474747', 'thumb' => '#242424', 'pill' => '#404040', 'tap' => '#363636', 'press' => '#4e4e4e', 'ctl' => '#878787', 'on' => '#ffffff'], false],
        'xanh lá+nâu' => [['#fffdf7', '#3b2a1a', '#16a34a'], ['bg' => '#f9f7f0', 'ink' => '#564839', 'strong' => '#3b2a1a', 'sub' => '#6b5f51', 'muted' => '#675f55', 'track' => '#e8e5dd', 'hair' => '#e0dcd4', 'edge' => '#e0dcd4', 'thumb' => '#fffdf7', 'pill' => '#eae7df', 'tap' => '#efede5', 'press' => '#e0dcd4', 'ctl' => '#837b71', 'on' => '#3b2a1a'], false, ['bg' => '#463c34', 'ink' => '#f2f0ec', 'strong' => '#fffdf7', 'sub' => '#cfcbc4', 'muted' => '#c8c4bf', 'track' => '#534a42', 'hair' => '#594f48', 'edge' => '#645b53', 'thumb' => '#463c34', 'pill' => '#5e554d', 'tap' => '#554b44', 'press' => '#696159', 'ctl' => '#a6a19a', 'on' => '#fffdf7'], false],
        'tím' => [['#ffffff', '#1e1b4b', '#9333ea'], ['bg' => '#f8f8fa', 'ink' => '#3e3b64', 'strong' => '#1e1b4b', 'sub' => '#59567a', 'muted' => '#605e76', 'track' => '#e4e4ea', 'hair' => '#dcdbe3', 'edge' => '#dcdbe3', 'thumb' => '#ffffff', 'pill' => '#e7e6ec', 'tap' => '#ededf1', 'press' => '#dcdbe3', 'ctl' => '#7a798f', 'on' => '#1e1b4b'], false, ['bg' => '#302d59', 'ink' => '#e6e6eb', 'strong' => '#ffffff', 'sub' => '#c9c8d4', 'muted' => '#b4b3c4', 'track' => '#3e3c65', 'hair' => '#45426a', 'edge' => '#514f74', 'thumb' => '#302d59', 'pill' => '#4b486f', 'tap' => '#413e66', 'press' => '#575579', 'ctl' => '#9392a9', 'on' => '#ffffff'], true],
        'hồng+kem' => [['#fff7ed', '#431407', '#ec4899'], ['bg' => '#f9f0e6', 'ink' => '#5d3427', 'strong' => '#431407', 'sub' => '#744f43', 'muted' => '#72574e', 'track' => '#e9dcd2', 'hair' => '#e1d3c9', 'edge' => '#e1d3c9', 'thumb' => '#fff7ed', 'pill' => '#eaded4', 'tap' => '#f0e5db', 'press' => '#e1d3c9', 'ctl' => '#8a7268', 'on' => '#431407'], false, ['bg' => '#52271b', 'ink' => '#eaded4', 'strong' => '#fff7ed', 'sub' => '#d2c1b6', 'muted' => '#c3afa6', 'track' => '#5e362a', 'hair' => '#633c30', 'edge' => '#6e483d', 'thumb' => '#52271b', 'pill' => '#684236', 'tap' => '#60382c', 'press' => '#734f43', 'ctl' => '#a78e84', 'on' => '#fff7ed'], false],
        'đen tuyền' => [['#ffffff', '#000000', '#111111'], ['bg' => '#f7f7f7', 'ink' => '#242424', 'strong' => '#000000', 'sub' => '#424242', 'muted' => '#5c5c5c', 'track' => '#e1e1e1', 'hair' => '#d7d7d7', 'edge' => '#d7d7d7', 'thumb' => '#ffffff', 'pill' => '#e3e3e3', 'tap' => '#ebebeb', 'press' => '#d7d7d7', 'ctl' => '#797979', 'on' => '#000000'], false, ['bg' => '#141414', 'ink' => '#e3e3e3', 'strong' => '#ffffff', 'sub' => '#c2c2c2', 'muted' => '#a1a1a1', 'track' => '#242424', 'hair' => '#2c2c2c', 'edge' => '#3a3a3a', 'thumb' => '#141414', 'pill' => '#333333', 'tap' => '#272727', 'press' => '#414141', 'ctl' => '#7e7e7e', 'on' => '#ffffff'], true],
        // A grey ink: the recipe's dark panel left white at 8.8:1, so the panel is taken one shade deeper to reach 9:1.
        'chữ xám nhạt' => [['#ffffff', '#4b5563', '#0ea5e9'], ['bg' => '#fafafa', 'ink' => '#494f58', 'strong' => '#47515e', 'sub' => '#5f646d', 'muted' => '#61656b', 'track' => '#eaebec', 'hair' => '#e3e5e6', 'edge' => '#e3e5e6', 'thumb' => '#ffffff', 'pill' => '#e9eaeb', 'tap' => '#f1f2f2', 'press' => '#e0e1e3', 'ctl' => '#7e8288', 'on' => '#434d59'], true, ['bg' => '#3d454e', 'ink' => '#fcfcfc', 'strong' => '#ffffff', 'sub' => '#cdcfd1', 'muted' => '#cacdcf', 'track' => '#4b525a', 'hair' => '#505860', 'edge' => '#5c636a', 'thumb' => '#3d454e', 'pill' => '#525961', 'tap' => '#4d545c', 'press' => '#5e656c', 'ctl' => '#a4a8ad', 'on' => '#ffffff'], false],
        'ngọc' => [['#f8fafc', '#134e4a', '#14b8a6'], ['bg' => '#f1f5f7', 'ink' => '#2a5452', 'strong' => '#134e4a', 'sub' => '#416563', 'muted' => '#4a6463', 'track' => '#dde6e7', 'hair' => '#d4dfe1', 'edge' => '#d4dfe1', 'thumb' => '#f8fafc', 'pill' => '#dde6e7', 'tap' => '#e6edee', 'press' => '#d2dedf', 'ctl' => '#688281', 'on' => '#134e4a'], true, ['bg' => '#204b48', 'ink' => '#fbfcfc', 'strong' => '#fafcfd', 'sub' => '#c2cece', 'muted' => '#c3cece', 'track' => '#2f5755', 'hair' => '#365d5a', 'edge' => '#436765', 'thumb' => '#204b48', 'pill' => '#385e5c', 'tap' => '#315956', 'press' => '#456967', 'ctl' => '#97acab', 'on' => '#fbfdfd'], false],
        'kem+vàng đồng' => [['#fefce8', '#1f2937', '#ca8a04'], ['bg' => '#f7f6e3', 'ink' => '#3e4750', 'strong' => '#1f2937', 'sub' => '#596065', 'muted' => '#5a5e5f', 'track' => '#e4e4d4', 'hair' => '#dbdbcd', 'edge' => '#dbdbcd', 'thumb' => '#fefce8', 'pill' => '#e6e6d5', 'tap' => '#ececda', 'press' => '#dbdbcd', 'ctl' => '#767a78', 'on' => '#1f2937'], true, ['bg' => '#313a47', 'ink' => '#eaeadc', 'strong' => '#fefce8', 'sub' => '#c9cabe', 'muted' => '#bdbeb9', 'track' => '#3f4852', 'hair' => '#464d57', 'edge' => '#525961', 'thumb' => '#313a47', 'pill' => '#4c535c', 'tap' => '#414a54', 'press' => '#585f66', 'ctl' => '#989c9a', 'on' => '#fefce8'], false],
        'vàng nhạt' => [['#ffffff', '#1f2937', '#fde68a'], ['bg' => '#f8f9f9', 'ink' => '#3e4753', 'strong' => '#1f2937', 'sub' => '#59616b', 'muted' => '#5d6269', 'track' => '#e4e6e8', 'hair' => '#dcdee0', 'edge' => '#dcdee0', 'thumb' => '#ffffff', 'pill' => '#e7e8e9', 'tap' => '#edefef', 'press' => '#dcdee0', 'ctl' => '#777b81', 'on' => '#1f2937'], true, ['bg' => '#313a47', 'ink' => '#e8e9eb', 'strong' => '#ffffff', 'sub' => '#c9cccf', 'muted' => '#bbbec2', 'track' => '#3f4854', 'hair' => '#464e59', 'edge' => '#525a64', 'thumb' => '#313a47', 'pill' => '#4c545f', 'tap' => '#414a56', 'press' => '#585f6a', 'ctl' => '#969ba2', 'on' => '#ffffff'], false],
        'ô liu' => [['#ffffff', '#101100', '#dc2626'], ['bg' => '#f8f8f7', 'ink' => '#313224', 'strong' => '#101100', 'sub' => '#4e4f42', 'muted' => '#606057', 'track' => '#e3e3e1', 'hair' => '#dadad7', 'edge' => '#dadad7', 'thumb' => '#ffffff', 'pill' => '#e5e6e3', 'tap' => '#ececeb', 'press' => '#dadad7', 'ctl' => '#7a7b73', 'on' => '#101100'], false, ['bg' => '#232419', 'ink' => '#e5e5e3', 'strong' => '#ffffff', 'sub' => '#c6c6c3', 'muted' => '#a7a7a3', 'track' => '#323329', 'hair' => '#393a30', 'edge' => '#46473e', 'thumb' => '#232419', 'pill' => '#404037', 'tap' => '#35362b', 'press' => '#4d4e45', 'ctl' => '#868781', 'on' => '#ffffff'], false],
    ];
}

function dtLeast(string $color, array $backgrounds): float
{
    return min(array_map(fn (string $b): float => (float) ReadableColor::ratio($color, $b), $backgrounds));
}

it('matches the approved recipe for every sample palette, both tones', function (array $seeds, array $light, bool $lightRing, array $dark, bool $darkRing): void {
    $tones = DrawerTones::derive(...$seeds);

    expect($tones)->not->toBeNull()
        ->and($tones['light'])->toBe($light)
        ->and($tones['dark'])->toBe($dark)
        ->and($tones['light_ring'])->toBe($lightRing)
        ->and($tones['dark_ring'])->toBe($darkRing);
})->with(drawerPalettes());

function dtAssertFloors(array $seeds): void
{
    $ink = $seeds[1];
    $tones = (array) DrawerTones::derive(...$seeds);
    $readableInk = $tones['scrim'];                 // the ink made readable on the surface, which tints the scrim

    foreach (['light' => false, 'dark' => true] as $name => $dark) {
        $t = $tones[$name];
        $seen = DrawerTones::seen($t['bg'], $readableInk, $ink, $dark);
        $label = $dark ? DrawerTones::DARK_LABEL : DrawerTones::LABEL;

        expect(dtLeast($t['ink'], $seen))->toBeGreaterThanOrEqual($label - 0.005, "$name ink")
            ->and(dtLeast($t['strong'], $seen))->toBeGreaterThanOrEqual($label - 0.005, "$name strong")
            ->and(dtLeast($t['sub'], [...$seen, $t['track'], $t['hair']]))->toBeGreaterThanOrEqual(DrawerTones::SECONDARY - 0.005, "$name sub")
            ->and(dtLeast($t['muted'], [...$seen, $t['track'], $t['hair']]))->toBeGreaterThanOrEqual(DrawerTones::SECONDARY - 0.005, "$name muted")
            ->and(dtLeast($t['ctl'], [...$seen, $t['track'], $t['hair']]))->toBeGreaterThanOrEqual(DrawerTones::CONTROL - 0.005, "$name ctl")
            ->and(dtLeast($t['on'], [$t['pill']]))->toBeGreaterThanOrEqual(DrawerTones::LABEL - 0.005, "$name on pill")
            ->and(dtLeast($t['on'], [$t['press']]))->toBeGreaterThanOrEqual(DrawerTones::SECONDARY - 0.005, "$name on press")
            ->and(dtLeast($t['ink'], [$t['tap'], $t['track']]))->toBeGreaterThanOrEqual(DrawerTones::SECONDARY - 0.005, "$name ink on tap");
    }
}

it('keeps every role above its contrast floor on the solid panel and over a white or black page', function (array $seeds): void {
    dtAssertFloors($seeds);
})->with(drawerPalettes());

it('keeps the floors on a mid-grey or tinted surface, which leaves the light panel little room', function (string $surface, string $ink, string $brand): void {
    dtAssertFloors([$surface, $ink, $brand]);
})->with([
    'xám 9e' => ['#9e9e9e', '#333333', '#3b82f6'],
    'xám 80' => ['#808080', '#000000', '#dc2626'],
    'xám ấm' => ['#a8a29e', '#1c1917', '#f59e0b'],
    'xanh nhạt' => ['#7dd3fc', '#0c4a6e', '#0369a1'],
]);

it('prints the variables of one tone as a style attribute of hex colours only', function (): void {
    $css = (string) DrawerTones::css('#ffffff', '#0f172a', '#f59e0b', false);

    expect($css)->toStartWith('--sf-dw-bg:#f8f8f9;--sf-dw-ink:#313748;--sf-dw-strong:#0f172a;')
        ->toContain('--sf-dw-panel:#f8f8f9f5;--sf-dw-scrim:#0f172a57;')
        ->toEndWith('--sf-dw-cta-ring:inset 0 0 0 1.5px #777a83')
        ->and((string) DrawerTones::css('#ffffff', '#0f172a', '#f59e0b', true))
        ->toContain('--sf-dw-panel:#222a3bf7;--sf-dw-scrim:#0f172a70;')->toEndWith('--sf-dw-cta-ring:none')
        ->and(preg_replace('/--sf-dw-[a-z-]+:(#[0-9a-f]{6}(?:[0-9a-f]{2})?|none|inset 0 0 0 1\.5px #[0-9a-f]{6});?/', '', $css))->toBe('');
});

it('gives no variables when a seed is not a hex colour, so the stylesheet’s fallbacks apply', function (string $surface, string $ink): void {
    expect(DrawerTones::css($surface, $ink, '#f59e0b', false))->toBeNull()
        ->and(DrawerTones::derive($surface, $ink, '#f59e0b'))->toBeNull();
})->with([
    'named surface' => ['white', '#111111'],
    'empty ink' => ['#ffffff', ''],
    'injected text' => ['#fff;}body{display:none', '#111111'],
]);

it('gives the call-to-action row no ring when the brand is not a hex colour', function (): void {
    expect(DrawerTones::css('#ffffff', '#111111', 'var(--x)', false))->toEndWith('--sf-dw-cta-ring:none');
});

/** The accent a tone prints, as a hex colour (null when it prints none). */
function dtAccent(string $brand, bool $dark, string $surface = '#ffffff', string $ink = '#111111', bool $on = true): ?string
{
    return preg_match('/--sf-dw-accent:(#[0-9a-f]{6});/', (string) DrawerTones::css($surface, $ink, $brand, $dark, $on), $m) === 1 ? $m[1] : null;
}

it('prints no accent for the neutral setting, so the drawer is exactly what it was', function (): void {
    expect((string) DrawerTones::css('#ffffff', '#111111', '#ffd400', false))->not->toContain('--sf-dw-accent')
        ->and((string) DrawerTones::css('#ffffff', '#111111', '#ffd400', true, false))->not->toContain('--sf-dw-accent')
        ->and(DrawerTones::css('#ffffff', '#111111', '#ffd400', false, false))->toBe(DrawerTones::css('#ffffff', '#111111', '#ffd400', false));
});

it('darkens a light brand until it reads 3:1 on every backdrop of the light panel, and lightens a dark brand on the dark panel as needed', function (string $brand, string $surface, string $ink): void {
    foreach ([false, true] as $dark) {
        $accent = dtAccent($brand, $dark, $surface, $ink);
        $tones = (array) DrawerTones::derive($surface, $ink, $brand);
        $t = $tones[$dark ? 'dark' : 'light'];
        $seen = DrawerTones::seen($t['bg'], $tones['scrim'], $ink, $dark);

        expect($accent)->not->toBeNull()
            ->and(dtLeast((string) $accent, [...$seen, $t['pill'], $t['press'], $t['track']]))->toBeGreaterThanOrEqual(DrawerTones::CONTROL - 0.005, ($dark ? 'dark ' : 'light ').$brand);
    }
})->with([
    'vàng tươi' => ['#ffd400', '#ffffff', '#111111'],
    'vàng nhạt' => ['#fde68a', '#ffffff', '#1f2937'],
    'xanh navy' => ['#1e3a8a', '#ffffff', '#0b1120'],
    'đen tuyền' => ['#111111', '#ffffff', '#000000'],
    'nền xám' => ['#f59e0b', '#9e9e9e', '#333333'],
]);

it('moves a light brand toward black on the light panel and keeps a dark brand as it is when it already reads 3:1', function (): void {
    $light = (string) dtAccent('#ffd400', false);

    expect(ReadableColor::ratio($light, '#ffffff'))->toBeGreaterThanOrEqual(3.0)
        ->and($light)->not->toBe('#ffd400')
        ->and(dtAccent('#1e3a8a', false))->toBe('#1e3a8a')    // navy is far above 3:1 on the light panel
        ->and(dtAccent('#FFD400', true))->toBe('#ffd400');     // a yellow already reads on the dark panel; the hex is written in lower case
});

it('prints no accent for a brand that is not a hex colour', function (string $brand): void {
    expect(DrawerTones::css('#ffffff', '#111111', $brand, false, true))->not->toBeNull()->not->toContain('--sf-dw-accent');
})->with(['a name' => ['gold'], 'a variable' => ['var(--x)'], 'empty' => [''], 'injected text' => ['#fff;}body{display:none']]);
