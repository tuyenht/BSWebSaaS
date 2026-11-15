<?php

use App\Modules\Theme\Chrome\StorefrontChrome;

/*
 * The phone menu shows three levels: a third-level row is never a parent, its descendants follow it as
 * third-level rows in depth-first order (a menu may be five levels deep), and no link is lost.
 */

/** @param  list<array<string, mixed>>  $children */
function capNode(string $label, array $children = []): array
{
    return ['label' => $label, 'url' => '/'.$label, 'active' => false, 'children' => $children];
}

/** @return list<string> labels depth first, each with its depth */
function capWalk(array $items, int $depth = 1): array
{
    $out = [];
    foreach ($items as $item) {
        $out[] = $depth.':'.$item['label'];
        array_push($out, ...capWalk($item['children'], $depth + 1));
    }

    return $out;
}

it('flattens levels four and five behind their level-three row, depth first', function (): void {
    $menu = [
        capNode('a', [
            capNode('a1', [
                capNode('a11', [
                    capNode('a111', [capNode('a1111')]),
                    capNode('a112'),
                ]),
                capNode('a12'),
            ]),
        ]),
        capNode('b'),
    ];

    $capped = StorefrontChrome::drawerCapDepth($menu);

    expect(capWalk($capped))->toBe([
        '1:a', '2:a1', '3:a11', '3:a111', '3:a1111', '3:a112', '3:a12', '1:b',
    ]);
});

it('keeps every link and never lets a level-three row hold children', function (): void {
    $menu = [capNode('a', [capNode('b', [capNode('c', [capNode('d', [capNode('e')])])])])];

    $capped = StorefrontChrome::drawerCapDepth($menu);
    $urls = [];
    $walk = function (array $items, int $depth) use (&$walk, &$urls): void {
        foreach ($items as $item) {
            $urls[] = $item['url'];
            if ($depth >= 3) {
                expect($item['children'])->toBe([]);
            }
            $walk($item['children'], $depth + 1);
        }
    };
    $walk($capped, 1);

    expect($urls)->toBe(['/a', '/b', '/c', '/d', '/e']);
});

it('leaves a menu of three levels or fewer as it is, and the other fields of a row with it', function (): void {
    $menu = [
        capNode('a', [capNode('a1', [capNode('a11')])]) + ['icon' => 'phone', 'cta' => true],
        capNode('b'),
    ];

    expect(StorefrontChrome::drawerCapDepth($menu))->toBe($menu)
        ->and(StorefrontChrome::drawerCapDepth([]))->toBe([]);
});

it('keeps the active and trail marks of the rows it moves', function (): void {
    $deep = ['label' => 'e', 'url' => '/e', 'active' => true, 'children' => []];
    $menu = [capNode('a', [capNode('b', [capNode('c', [capNode('d', [$deep])])])])];

    $capped = StorefrontChrome::drawerCapDepth($menu);
    $level2 = $capped[0]['children'][0];

    expect(array_column($level2['children'], 'label'))->toBe(['c', 'd', 'e'])
        ->and($level2['children'][2]['active'])->toBeTrue()
        ->and(StorefrontChrome::inTrail($level2))->toBeTrue();   // the group around still opens on the way to the page
});

it('gives a language its Vietnamese name only when that adds something', function (): void {
    expect(StorefrontChrome::languageGloss('en', 'English'))->toBe('Tiếng Anh')
        ->and(StorefrontChrome::languageGloss('ja', '日本語'))->toBe('Tiếng Nhật')
        ->and(StorefrontChrome::languageGloss('vi', 'Tiếng Việt'))->toBeNull()
        ->and(StorefrontChrome::languageGloss('xx', 'XX'))->toBeNull();
});
