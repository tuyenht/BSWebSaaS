<?php

/*
 * The list tools row: the search field and the Filter panel shared by every record list. The
 * field's name is the full label even when the visible hint is shorter; the Filter button says how
 * many conditions are on, and its panel offers each section as native radios or checkboxes bound
 * live to the host component.
 */

it('names the search field by its full label and puts host attributes on the input', function (): void {
    $html = (string) $this->blade('<x-bs-admin::search label="Tìm theo tên hoặc mã" placeholder="Tên hoặc mã" wire:model.live.debounce.300ms="q" />');

    expect($html)->toContain('aria-label="Tìm theo tên hoặc mã"')
        ->and($html)->toContain('placeholder="Tên hoặc mã"')
        ->and($html)->toMatch('/<input[^>]*wire:model\.live\.debounce\.300ms="q"/')
        ->and($html)->toMatch('/<label class="[^"]*\bmin-h-control\b[^"]*\bfocus-ring-within\b/');
});

it('shows the label as the hint when no shorter hint is given', function (): void {
    $html = (string) $this->blade('<x-bs-admin::search label="Tìm tệp" />');

    expect($html)->toContain('placeholder="Tìm tệp"');
});

/**
 * @return list<array{key: string, label: string, model: string, multiple?: bool, options: list<array{value: string, label: string, count?: int|null}>}>
 */
function filterSections(): array
{
    return [
        ['key' => 'status', 'label' => 'Trạng thái', 'model' => 'status', 'options' => [
            ['value' => 'live', 'label' => 'Tệp đang có', 'count' => 1234],
            ['value' => 'trash', 'label' => 'Thùng rác', 'count' => 3],
        ]],
        ['key' => 'kind', 'label' => 'Loại tệp', 'model' => 'kinds', 'multiple' => true, 'options' => [
            ['value' => 'image', 'label' => 'Ảnh', 'count' => 19],
            ['value' => 'pdf', 'label' => 'Tài liệu PDF'],
        ]],
    ];
}

it('offers one choice as radios and several as checkboxes, bound live with their counts', function (): void {
    app()->setLocale('vi');
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => filterSections()]);

    expect(substr_count($html, 'type="radio"'))->toBe(2)
        ->and(substr_count($html, 'type="checkbox"'))->toBe(2)
        ->and($html)->toContain('wire:model.live="status"')
        ->and($html)->toContain('wire:model.live="kinds"')
        ->and($html)->toContain('1.234')
        ->and($html)->toContain('<legend')
        ->and($html)->toContain('Loại tệp');
});

it('names the Filter button by how many conditions are on and offers Clear only then', function (): void {
    app()->setLocale('vi');
    $idle = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" clear="clearFilters" />', ['sections' => filterSections()]);
    $on = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" :active="2" clear="clearFilters" results="Xem 12 tệp" />', ['sections' => filterSections()]);

    expect($idle)->toContain('aria-label="'.__('admin.list.filter').'"')
        ->and($idle)->not->toContain('wire:click="clearFilters"')
        ->and($idle)->toContain(__('admin.list.close_filters'))
        ->and($on)->toContain('aria-label="'.__('admin.list.filter_active', ['n' => 2]).'"')
        ->and($on)->toContain('wire:click="clearFilters"')
        ->and($on)->toContain('Xem 12 tệp');
});

it('ties the Filter button to its dialog and draws the filter icon', function (): void {
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => filterSections()]);

    expect(preg_match('/aria-controls="(lf-[A-Za-z0-9]{8})"/', $html, $m))->toBe(1)
        ->and($html)->toContain('id="'.$m[1].'"')
        ->and($html)->toContain('role="dialog"')
        ->and($html)->toContain('M10 18H14V16H10V18ZM3 6V8H21V6H3ZM6 13H18V11H6V13Z');
});

/**
 * @return list<array{key: string, label: string, icon?: string|null, count?: int|null, trash?: bool}>
 */
function statusGroups(): array
{
    return [
        ['key' => 'all', 'label' => 'Tất cả', 'count' => 1234],
        ['key' => 'used', 'label' => 'Đang dùng', 'count' => 10],
        ['key' => 'unused', 'label' => 'Chưa dùng', 'count' => 9],
        ['key' => 'trash', 'label' => 'Thùng rác', 'count' => 3, 'trash' => true],
    ];
}

it('offers status groups as pressed buttons that set the host property, never as tabs', function (): void {
    app()->setLocale('vi');
    $html = (string) $this->blade('<x-bs-admin::group-row :groups="$groups" active="used" model="group" label="Nhóm tệp" />', ['groups' => statusGroups()]);

    expect($html)->toContain('<nav aria-label="Nhóm tệp"')
        ->and($html)->not->toContain('role="tab')
        ->and(substr_count($html, 'aria-pressed="true"'))->toBe(1)
        ->and(substr_count($html, 'aria-pressed="false"'))->toBe(3)
        ->and($html)->toContain('wire:click="$set(&#039;group&#039;, &#039;unused&#039;)"')
        ->and($html)->toContain('1.234');
});

it('keeps the chosen group and Trash in the row when groups overflow into More', function (): void {
    $html = (string) $this->blade('<x-bs-admin::group-row :groups="$groups" active="used" model="group" label="Nhóm tệp" />', ['groups' => statusGroups()]);

    expect($html)->toMatch('/data-g="all" data-pin="0"/')
        ->and($html)->toMatch('/data-g="used" data-pin="1"/')
        ->and($html)->toMatch('/data-g="unused" data-pin="0"/')
        ->and($html)->toMatch('/data-g="trash" data-pin="1"/')
        ->and(substr_count($html, 'role="menuitemradio"'))->toBe(4)
        ->and($html)->toContain('aria-haspopup="menu"');
});
