<?php

use App\Modules\Admin\Livewire\ComponentGallery;
use App\Modules\Admin\Support\TreeRows;
use Livewire\Livewire;

/*
 * The shared admin tree (<x-bs-admin::tree>): one treegrid for pages, categories, menus and product
 * categories. The server draws every row — level, place among siblings, guide lines (├ └ │) — and
 * the browser module (resources/js/admin-tree.js) adds the one drag rule and the keyboard moves,
 * sending ONE call (key, parent key or null, position) to the screen's move method. Sizes come from
 * the shared tokens only.
 */

beforeEach(function (): void {
    app()->setLocale('vi');
});

/** @return list<array{key: string, parent: string|null, label: string}> */
function treeSampleNodes(): array
{
    return [
        ['key' => 'b', 'parent' => null, 'label' => 'B'],
        ['key' => 'a1', 'parent' => 'a', 'label' => 'A1'],
        ['key' => 'a', 'parent' => null, 'label' => 'A'],
        ['key' => 'a2', 'parent' => 'a', 'label' => 'A2'],
        ['key' => 'b1', 'parent' => 'b', 'label' => 'B1'],
        ['key' => 'b1a', 'parent' => 'b1', 'label' => 'B1a'],
        ['key' => 'b2', 'parent' => 'b', 'label' => 'B2'],
    ];
}

function treeCss(): string
{
    return (string) file_get_contents(resource_path('css/admin-tree.css'));
}

it('orders the records parent first, each group in the order given, with level and place among siblings', function (): void {
    $rows = TreeRows::build(treeSampleNodes());

    expect(array_column($rows, 'key'))->toBe(['b', 'b1', 'b1a', 'b2', 'a', 'a1', 'a2'])
        ->and(array_column($rows, 'level'))->toBe([1, 2, 3, 2, 1, 2, 2])
        ->and(array_column($rows, 'parent'))->toBe([null, 'b', 'b1', 'b', null, 'a', 'a'])
        ->and(array_column($rows, 'pos'))->toBe([1, 1, 1, 2, 2, 1, 2])
        ->and(array_column($rows, 'size'))->toBe([2, 2, 1, 2, 2, 2, 2])
        ->and(array_column($rows, 'kids'))->toBe([2, 1, 0, 0, 2, 0, 0])
        ->and($rows[1]['node']['label'])->toBe('B1');
});

it('draws the guide lines: ├ before a later sibling, └ on the last, │ passing by, and the start under a parent', function (): void {
    $guides = array_column(TreeRows::build(treeSampleNodes()), 'guides', 'key');

    expect($guides['b'])->toBe([['d' => 1, 'k' => 'down']])
        ->and($guides['b1'])->toBe([['d' => 1, 'k' => 't'], ['d' => 2, 'k' => 'down']])
        ->and($guides['b1a'])->toBe([['d' => 1, 'k' => 'v'], ['d' => 2, 'k' => 'l']])
        ->and($guides['b2'])->toBe([['d' => 1, 'k' => 'l']])
        ->and($guides['a1'])->toBe([['d' => 1, 'k' => 't']])
        ->and($guides['a2'])->toBe([['d' => 1, 'k' => 'l']]);
});

it('still draws every record once when a parent is missing or the parents loop', function (): void {
    $rows = TreeRows::build([
        ['key' => 'x', 'parent' => 'gone'],
        ['key' => 'p', 'parent' => 'q'],
        ['key' => 'q', 'parent' => 'p'],
        ['key' => '12', 'parent' => null],
        ['key' => '13', 'parent' => '12'],
    ]);

    expect(array_column($rows, 'key'))->toBe(['x', '12', '13', 'p', 'q'])
        ->and(array_column($rows, 'level'))->toBe([1, 1, 2, 1, 1])
        ->and(array_column($rows, 'parent'))->toBe([null, null, '12', null, null]);
});

it('draws a live tree as a named treegrid with its drag rule, live line and result line', function (): void {
    $html = (string) $this->blade(
        '<x-bs-admin::tree label="Trình đơn chính" move="moveItem" :max-depth="3" noun="chuyên mục con">'
        .'@foreach ($rows as $r)<x-bs-admin::tree.row :row="$r" :name="$r[\'node\'][\'label\']"><x-bs-admin::tree.name>{{ $r[\'node\'][\'label\'] }}</x-bs-admin::tree.name></x-bs-admin::tree.row>@endforeach'
        .'</x-bs-admin::tree>',
        ['rows' => TreeRows::build(treeSampleNodes())],
    );

    preg_match('/x-data="bsTree\\(JSON\\.parse\\(\'(.*?)\'\\)\\)"/', $html, $m);
    $config = json_decode((string) json_decode('"'.($m[1] ?? '').'"'), true);

    expect($html)->toMatch('/<div role="treegrid" aria-label="Trình đơn chính" class="tree"/')
        ->and($html)->toContain('x-on:drop.prevent="dragDone()"')
        ->and($html)->toContain('x-on:dragend="dragCancel()"')
        ->and($html)->toContain('<div class="tree-sr" role="status" aria-live="assertive" x-ref="live"></div>')
        // one hidden placeholder, moved between the rows while dragging
        ->and($html)->toMatch('/<div aria-hidden="true" data-lv="1" data-bad="0"(?=[^>]*\\bx-ref="ph")(?=[^>]*\\bclass="tree-ph")(?=[^>]*\\shidden\\b)[^>]*>/')
        // the move call, the depth cap and the words the live line reads, in the screen's own noun
        ->and($config['move'])->toBe('moveItem')
        ->and($config['maxDepth'])->toBe(3)
        ->and($config['texts']['drag'])->toBe([
            'start' => 'Đang kéo «:name» — thả vào tên để thành chuyên mục con, thả vào khe để nằm ngang hàng. Esc để hủy.',
            'child' => 'Sẽ thành chuyên mục con đầu tiên của «:name» · cấp :level',
            'before' => 'Sẽ nằm ngay trước «:name» · cấp :level',
            'after' => 'Sẽ nằm ngay sau «:name» · cấp :level',
            'self' => 'Không thả vào chính nó hoặc chuyên mục con của nó',
        ])
        ->and($config['texts']['tooDeep'])->toBe('Tối đa :max cấp')
        ->and($config['texts']['kept'])->toBe(':why Cây giữ nguyên.')
        ->and($config['texts']['movedChild'])->toBe('Đã chuyển «:name» thành chuyên mục con đầu tiên của «:parent».')
        ->and($config['texts']['movedUp'])->toBe('Đã chuyển «:name» lên.')
        ->and($config['texts']['undone'])->toBe('Đã hoàn tác.');
});

it('marks each row with its level, place, open state and opaque key, and draws its guide lines', function (): void {
    $html = (string) $this->blade(
        '<x-bs-admin::tree label="T" move="moveItem">@foreach ($rows as $r)<x-bs-admin::tree.row :row="$r" :name="$r[\'node\'][\'label\']"><x-bs-admin::tree.name>{{ $r[\'node\'][\'label\'] }}</x-bs-admin::tree.name></x-bs-admin::tree.row>@endforeach</x-bs-admin::tree>',
        ['rows' => TreeRows::build(treeSampleNodes())],
    );

    // a parent: expanded, with a toggle named for it
    expect($html)->toMatch('/<div role="row" data-tree-row data-k="b1" data-parent="b" data-lv="2" data-name="B1"\s+aria-level="2" aria-posinset="1" aria-setsize="2"\s+data-kids="1" aria-expanded="true"\s+x-bind:aria-expanded="String\(isOpen\(\'b1\'\)\)"/')
        ->and($html)->toContain('aria-label="Thu gọn “B1”"')
        ->and($html)->toContain('x-on:click="toggle(\'b1\')"')
        // a leaf at level 3: no aria-expanded, a spacer instead of the toggle, draggable, hidden while its parent is collapsed
        ->and($html)->toMatch('/data-k="b1a" data-parent="b1" data-lv="3" data-name="B1a"\s+aria-level="3" aria-posinset="1" aria-setsize="1"\s+draggable="true"\s+x-bind:hidden="hid\(\$el\)"/')
        ->and($html)->toContain('<span class="tree-g" aria-hidden="true"><span class="tree-gl" data-d="1" data-k="v"></span><span class="tree-gl" data-d="2" data-k="l"></span></span>')
        ->and($html)->toContain('<span class="tree-tg0" aria-hidden="true"></span>')
        ->and($html)->toContain('<div role="gridcell" class="tree-c tree-c-main">')
        ->and($html)->toContain('<button type="button" data-tree-name class="tree-nm">B1a</button>');
});

it('shows a locked row: the reason beside the name, a greyed grip, and no drag', function (): void {
    $rows = TreeRows::build([['key' => 'k', 'parent' => null, 'label' => 'Khuôn mẫu']]);
    $html = (string) $this->blade(
        '<x-bs-admin::tree label="T" move="m"><x-bs-admin::tree.row :row="$rows[0]" name="Khuôn mẫu" locked="Lê Hoàng Phúc đang sửa"><x-bs-admin::tree.name>Khuôn mẫu</x-bs-admin::tree.name><x-slot:actions><button type="button">Sửa</button></x-slot:actions></x-bs-admin::tree.row></x-bs-admin::tree>',
        ['rows' => $rows],
    );

    expect($html)->toContain('data-lock="Lê Hoàng Phúc đang sửa"')
        ->and($html)->toContain('draggable="false"')
        ->and($html)->toContain('<span class="tree-grip" aria-hidden="true"  data-off="1"  title="Lê Hoàng Phúc đang sửa">')
        ->and($html)->toContain('<span>Lê Hoàng Phúc đang sửa</span>')
        ->and($html)->toContain('<div role="gridcell" class="tree-c tree-c-act"><button type="button">Sửa</button></div>');
});

it('draws a still tree with no behaviour: plain names, no drag, the drag states in place', function (): void {
    $rows = TreeRows::build([['key' => 'g', 'parent' => null, 'label' => 'Giới thiệu'], ['key' => 'l', 'parent' => 'g', 'label' => 'Lịch sử']]);
    $html = (string) $this->blade(
        '<x-bs-admin::tree label="Mẫu"><x-bs-admin::tree.row :row="$rows[0]" name="Giới thiệu" state="drop-in"><x-bs-admin::tree.name>Giới thiệu</x-bs-admin::tree.name></x-bs-admin::tree.row>'
        .'<x-bs-admin::tree.placeholder :level="2">Sẽ thành mục con đầu tiên của «Giới thiệu» · cấp 2</x-bs-admin::tree.placeholder>'
        .'<x-bs-admin::tree.row :row="$rows[1]" name="Lịch sử" state="dragging"><x-bs-admin::tree.name>Lịch sử</x-bs-admin::tree.name></x-bs-admin::tree.row>'
        .'<x-bs-admin::tree.placeholder :level="3" bad>Tối đa 3 cấp</x-bs-admin::tree.placeholder></x-bs-admin::tree>',
        ['rows' => $rows],
    );

    expect($html)->not->toContain('x-data')
        ->and($html)->not->toContain('draggable')
        ->and($html)->not->toContain('role="status"')
        ->and($html)->toContain('data-drop="in"')
        ->and($html)->toContain('data-drag="1"')
        ->and($html)->toContain('<span class="tree-nm">Giới thiệu</span>')
        ->and($html)->toContain('<span class="tree-tg" aria-hidden="true">')
        ->and($html)->toMatch('/<div aria-hidden="true" data-lv="2" data-bad="0" class="tree-ph">.*<span data-tree-ph-text>Sẽ thành mục con đầu tiên của «Giới thiệu» · cấp 2<\/span>/s')
        ->and($html)->toMatch('/<div aria-hidden="true" data-lv="3" data-bad="1" class="tree-ph">.*<span data-tree-ph-text>Tối đa 3 cấp<\/span>/s');
});

it('lists the keyboard moves under "Phím tắt"', function (): void {
    $html = (string) $this->blade('<x-bs-admin::tree.keys />');

    expect($html)->toContain('<summary>Phím tắt</summary>')
        ->and($html)->toContain('<kbd>Alt</kbd> + <kbd>↑</kbd> / <kbd>↓</kbd></dt><dd>Chuyển lên / chuyển xuống trong nhóm</dd>')
        ->and($html)->toContain('<kbd>Alt</kbd> + <kbd>→</kbd></dt><dd>Thành mục con của mục ngay trên</dd>')
        ->and($html)->toContain('<kbd>Alt</kbd> + <kbd>←</kbd></dt><dd>Đưa ra ngoài một cấp</dd>')
        ->and($html)->toContain('<kbd>Ctrl</kbd> + <kbd>Z</kbd></dt><dd>Hoàn tác lần chuyển vừa rồi</dd>');
});

it('sizes the tree from the shared tokens, 32 per level and 24 when narrow, never a hard 40 or 44', function (): void {
    $css = treeCss();

    expect($css)->toContain('--tree-tg: var(--spacing-control);')
        ->and($css)->toContain('--tree-tg: var(--spacing-hit);')
        ->and($css)->toContain('--tree-step: 32px;')
        ->and($css)->toMatch('/@container tree \(max-width: 37\.4375rem\) \{\s*\.tree \{\s*--tree-step: 24px;/')
        ->and($css)->not->toMatch('/\b4[04]px\b/')
        ->and($css)->not->toContain('text-overflow: ellipsis');
});

it('moves a gallery record to a place among its new siblings', function (): void {
    $gallery = Livewire::test(ComponentGallery::class);

    // "Tiện CNC" to the first place of the outer level
    $gallery->call('moveTreeItem', 'tien-cnc', null, 0)->assertReturned(null);
    $nodes = collect($gallery->get('treeNodes'))->keyBy('key');
    expect($nodes['tien-cnc']['parent'])->toBeNull()
        ->and(array_column(TreeRows::build($gallery->get('treeNodes')), 'key')[0])->toBe('tien-cnc');

    // "Liên hệ" as the second child of "Giới thiệu"
    $gallery->call('moveTreeItem', 'lien-he', 'gioi-thieu', 1)->assertReturned(null);
    $kids = array_values(array_filter(TreeRows::build($gallery->get('treeNodes')), fn (array $r): bool => $r['parent'] === 'gioi-thieu'));
    expect(array_column($kids, 'key'))->toBe(['lich-su', 'lien-he', 'doi-ngu']);
});

it('refuses a gallery move into a locked record, out of a locked one, into itself or past three levels', function (string $key, ?string $parent, string $why): void {
    $gallery = Livewire::test(ComponentGallery::class);
    $before = $gallery->get('treeNodes');

    $gallery->call('moveTreeItem', $key, $parent, 0)->assertReturned($why);
    expect($gallery->get('treeNodes'))->toBe($before);
})->with([
    'into a locked record' => ['lien-he', 'khuon-mau', '«Khuôn mẫu» đang khóa: Lê Hoàng Phúc đang sửa.'],
    'a locked record' => ['khuon-mau', null, '«Khuôn mẫu» đang khóa — Lê Hoàng Phúc đang sửa.'],
    'into its own child' => ['san-pham', 'gia-cong-cnc', 'Không thả vào chính nó hoặc mục con của nó.'],
    'a fourth level' => ['gia-cong-cnc', 'lich-su', 'Chỉ được lồng tối đa 3 cấp.'],
    'a record that is gone' => ['khong-co', null, 'Mục này không còn nữa — danh sách đã được tải lại.'],
]);
