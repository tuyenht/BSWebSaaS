<?php

use App\Modules\Admin\Livewire\Menus\Index as MenusIndex;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Navigation\MenuItemOptions;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Chrome\StorefrontIcons;

/*
 * The icons a menu item may carry: one family of glyphs (outline marks that follow the text colour,
 * filled marks for brands), literal SVG of ours, the same names in the picker, the write path and
 * the website.
 */

it('keeps every glyph to plain shapes with geometry attributes and an outline or filled kind', function (): void {
    $attributes = ['d', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'fill-rule', 'clip-rule'];

    foreach (StorefrontIcons::GLYPHS as $name => [$kind, $body]) {
        expect($kind)->toBeIn(['o', 'f']);
        $without = preg_replace('#<(path|circle|rect|line|polyline|polygon|ellipse)\b(?:\s+[a-z][a-z0-9-]*="[^"]*")*\s*/>#', '', $body);
        expect($without)->toBe('', "$name has an element or attribute outside the plain shapes");
        preg_match_all('#\s([a-z][a-z0-9-]*)="#', $body, $found);
        expect(array_diff(array_unique($found[1]), $attributes))->toBe([], $name);
    }
});

it('names every picker icon in the website set and gives each group and icon its words in English', function (): void {
    $names = array_unique(array_merge(...array_values(MenusIndex::ICON_GROUPS)));
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(MenusIndex::ICON_GROUPS)->toHaveCount(7)
        ->and(array_keys(StorefrontIcons::GLYPHS))->toContain(...$names);
    foreach (array_keys(MenusIndex::ICON_GROUPS) as $group) {
        expect($english)->toHaveKey('menus.icon_group_'.$group);
    }
    foreach ($names as $name) {
        expect($english['menus.icon_'.$name] ?? '')->not->toBe('', $name)
            ->and($english['menus.icon_kw_'.$name] ?? '')->not->toBe('', $name);
    }
});

it('accepts a new icon name for a menu item and still refuses an unknown one', function (): void {
    $set = fn (string $icon): array => MenuItemOptions::normalize([], ['icon' => $icon], ['vi'], true, false, 'Asia/Ho_Chi_Minh');

    expect($set('home'))->toBe(['icon' => 'home'])
        ->and($set('whatsapp'))->toBe(['icon' => 'whatsapp'])
        ->and(fn () => $set('ri-home-4-line'))->toThrow(MenuChangeRejectedException::class);
});

it('draws a new icon in the desktop menu and in the phone drawer', function (): void {
    $item = ['label' => 'Trang chủ', 'icon' => 'home'];
    $desktop = StorefrontChrome::menuLabel($item)->toHtml();
    $drawer = StorefrontChrome::menuLabel($item, drawer: true)->toHtml();

    expect($desktop)->toContain('<svg class="sf-icon" width="16" height="16"', 'stroke="currentColor"', '<path d="M15 21v-8', '<span>Trang chủ</span>')
        ->and($drawer)->toContain('sf-icon sf-nav__ic', 'width="20" height="20"', '<path d="M15 21v-8');
});

it('stores "no icon" apart from "not chosen", round-trips it through the screen and keeps refusing unknown names', function (): void {
    $stored = MenuItemOptions::normalize([], ['icon' => 'none'], ['vi'], true, false, 'Asia/Ho_Chi_Minh');

    expect($stored)->toBe(['icon' => 'none'])
        ->and(MenuItemOptions::iconOff($stored))->toBeTrue()
        ->and(MenuItemOptions::iconOff([]))->toBeFalse()
        ->and(MenuItemOptions::iconOff(['icon' => 'home']))->toBeFalse()
        ->and(MenuItemOptions::forScreen($stored)['icon'])->toBe('none')
        ->and(MenuItemOptions::forScreen([])['icon'])->toBeNull()
        ->and(MenuItemOptions::forRender($stored, 'vi', now())['icon'])->toBeNull()
        ->and(fn () => MenuItemOptions::normalize([], ['icon' => 'bogus'], ['vi'], true, false, 'Asia/Ho_Chi_Minh'))->toThrow(MenuChangeRejectedException::class)
        ->and(fn () => MenuItemOptions::normalize([], ['icon' => 'None'], ['vi'], true, false, 'Asia/Ho_Chi_Minh'))->toThrow(MenuChangeRejectedException::class);
});

it('draws the automatic icon in the phone drawer only, and lets the own icon and a call-to-action row win', function (): void {
    $auto = ['label' => 'Tin tức', 'icon' => null, 'auto_icon' => 'newspaper'];

    expect(StorefrontChrome::menuLabel($auto, false, true)->toHtml())->toContain('sf-icon sf-nav__ic')
        ->and(StorefrontChrome::menuLabel($auto)->toHtml())->toBe('Tin tức')
        ->and(StorefrontChrome::menuLabel(['label' => 'A', 'icon' => 'home', 'auto_icon' => 'newspaper'], false, true)->toHtml())->toContain('M15 21v-8')->not->toContain('M15 18h-5')
        ->and(StorefrontChrome::drawerHasIcons([$auto]))->toBeTrue()
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'auto_icon' => 'newspaper', 'cta' => true]]))->toBeFalse()
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'icon' => null, 'auto_icon' => null]]))->toBeFalse()
        ->and(StorefrontChrome::drawerHasIcons([['label' => 'A', 'auto_icon' => 'no-such-icon']]))->toBeFalse()
        ->and(StorefrontChrome::menuIcon($auto))->toBeNull()
        ->and(StorefrontChrome::menuIcon($auto, true))->toBe('newspaper')
        ->and((string) StorefrontChrome::menuLabel(['label' => 'Tin tức'], false, true))->not->toContain('sf-icon');
});
