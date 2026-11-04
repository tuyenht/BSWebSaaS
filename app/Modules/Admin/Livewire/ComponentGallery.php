<?php

namespace App\Modules\Admin\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dev-only showcase of the admin design-system primitives (button/card/badge/input/table)
 * rendered in the real shell — for visual fidelity review against Velzon. Never reachable
 * in production: mount() aborts. Route is auth + tenant-scoped like the rest of the admin.
 */
#[Layout('admin::layouts.app')]
final class ComponentGallery extends Component
{
    /** Live values for the list-tools sample, so its radios and checkboxes really bind. */
    public string $galleryStatus = 'live';

    /** @var list<string> */
    public array $galleryKinds = [];

    public string $gallerySearch = '';

    public string $galleryGroup = 'all';

    public string $gallerySort = 'name';

    /**
     * The shared tree sample: a three-level menu, one record locked. Moves change it in place, the
     * way a real screen's move action would (Locked: the page never writes it directly).
     *
     * @var list<array{key: string, parent: string|null, label: string, locked: string|null}>
     */
    #[Locked]
    public array $treeNodes = [];

    /** The Sort menu sample: one of the shown orders. */
    public function setGallerySort(string $key): void
    {
        $this->gallerySort = in_array($key, ['name', 'size', 'new'], true) ? $key : 'name';
    }

    public function clearGalleryFilters(): void
    {
        $this->galleryStatus = 'live';
        $this->galleryKinds = [];
        $this->galleryGroup = 'all';
    }

    public function mount(): void
    {
        abort_if(app()->isProduction(), 404);
        $this->treeNodes = self::treeSample();
    }

    /**
     * The tree sample's move: one record to `position` among the other children of `parent` (null:
     * the outer level). Null when done; otherwise the reason it is refused, in plain words — the
     * sample refuses what a real screen does: a locked record, a locked parent, a loop, a fourth level.
     */
    public function moveTreeItem(string $key, ?string $parent, int $position): ?string
    {
        $byKey = array_column($this->treeNodes, null, 'key');
        $node = $byKey[$key] ?? null;
        if ($node === null || ($parent !== null && ! isset($byKey[$parent]))) {
            return __('admin.tree.gone');
        }
        if ($node['locked'] !== null) {
            return __('admin.tree.locked_self', ['name' => $node['label'], 'why' => $node['locked']]);
        }
        $level = 1;
        for ($up = $parent; $up !== null; $up = $byKey[$up]['parent'] ?? null) {
            if ($up === $key) {
                return __('admin.tree.no_self', ['noun' => __('admin.tree.noun')]).'.';
            }
            $level++;
        }
        if ($parent !== null && $byKey[$parent]['locked'] !== null) {
            return __('admin.tree.locked_target', ['name' => $byKey[$parent]['label']]).': '.$byKey[$parent]['locked'].'.';
        }
        if ($level + $this->treeHeight($key) - 1 > 3) {
            return __('admin.tree.too_deep_why', ['max' => 3]);
        }

        $rest = array_values(array_filter($this->treeNodes, fn (array $n): bool => $n['key'] !== $key));
        $siblings = array_keys(array_filter($rest, fn (array $n): bool => $n['parent'] === $parent));
        $position = max(0, min($position, count($siblings)));
        $at = $position < count($siblings) ? $siblings[$position] : ($siblings === [] ? count($rest) : $siblings[count($siblings) - 1] + 1);
        $node['parent'] = $parent;
        array_splice($rest, $at, 0, [$node]);
        $this->treeNodes = $rest;

        return null;
    }

    /** How many levels a sample record and everything under it take. */
    private function treeHeight(string $key): int
    {
        $height = 1;
        foreach ($this->treeNodes as $n) {
            if ($n['parent'] === $key) {
                $height = max($height, 1 + $this->treeHeight($n['key']));
            }
        }

        return $height;
    }

    /** @return list<array{key: string, parent: string|null, label: string, locked: string|null}> */
    private static function treeSample(): array
    {
        $rows = [
            ['trang-chu', null, 'Trang chủ', null],
            ['gioi-thieu', null, 'Giới thiệu', null],
            ['lich-su', 'gioi-thieu', 'Lịch sử công ty', null],
            ['doi-ngu', 'gioi-thieu', 'Đội ngũ kỹ sư và chuyên gia gia công cơ khí chính xác', null],
            ['san-pham', null, 'Sản phẩm', null],
            ['gia-cong-cnc', 'san-pham', 'Gia công CNC', null],
            ['phay-5-truc', 'gia-cong-cnc', 'Phay CNC 5 trục', null],
            ['tien-cnc', 'gia-cong-cnc', 'Tiện CNC', null],
            ['khuon-mau', 'san-pham', 'Khuôn mẫu', 'Lê Hoàng Phúc đang sửa'],
            ['tin-tuc', null, 'Tin tức', null],
            ['lien-he', null, 'Liên hệ', null],
        ];

        return array_map(fn (array $r): array => ['key' => $r[0], 'parent' => $r[1], 'label' => $r[2], 'locked' => $r[3]], $rows);
    }

    public function render(): View
    {
        return view('admin::livewire.component-gallery');
    }
}
