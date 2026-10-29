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

    // once in each section's own panel (from 1024px) and once in the Filter sheet (below 1024px)
    expect(substr_count($html, 'type="radio"'))->toBe(4)
        ->and(substr_count($html, 'type="checkbox"'))->toBe(4)
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

it('gives every section its own light button from 1024px, marked when it filters', function (): void {
    app()->setLocale('vi');
    $sections = filterSections();
    $sections[1]['value'] = ['pdf'];
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => $sections]);

    expect($html)->toMatch('/data-fsec="status" data-act="0"/')
        ->and($html)->toMatch('/data-fsec="kind" data-act="1"/')
        ->and($html)->toContain('role="group" aria-label="Loại tệp"')
        ->and($html)->toContain(__('admin.list.choose_many'))
        ->and($html)->toContain(__('admin.list.choose_one'));
});

it('opens the Filter sheet as a named modal dialog that can be closed by its own button', function (): void {
    app()->setLocale('vi');
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => filterSections()]);

    expect($html)->toContain('aria-modal="true"')
        ->and($html)->toContain(__('admin.list.filter_panel_title'))
        ->and($html)->toContain('aria-label="'.__('admin.list.close_filter_panel').'"')
        ->and($html)->toContain('aria-label="'.__('admin.list.filter_sections').'"');
});

it('says in words what each section is set to, in the two-column list of sections', function (): void {
    app()->setLocale('vi');
    $sections = filterSections();
    $sections[1]['value'] = ['image', 'pdf'];
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => $sections]);

    expect($html)->toContain('aria-label="Đi tới phần Trạng thái: Tệp đang có"')
        ->and($html)->toContain('aria-label="Đi tới phần Loại tệp: Ảnh, Tài liệu PDF"')
        ->and($html)->toContain(': Tệp đang có')
        ->and($html)->toContain(' · 2 đã chọn');
});

it('says All, dimmed, for a section with nothing chosen and shortens long choices', function (): void {
    app()->setLocale('vi');
    $many = ['key' => 'fmt', 'label' => 'Định dạng', 'model' => 'formats', 'multiple' => true, 'value' => ['jpg', 'png', 'webp', 'gif', 'pdf'], 'options' => [
        ['value' => 'jpg', 'label' => 'JPG'], ['value' => 'png', 'label' => 'PNG'], ['value' => 'webp', 'label' => 'WEBP'],
        ['value' => 'gif', 'label' => 'GIF'], ['value' => 'pdf', 'label' => 'PDF'],
    ]];
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => [...filterSections(), $many]]);

    expect($html)->toContain('aria-label="Đi tới phần Loại tệp: Tất cả"')
        ->and($html)->toMatch('/data-all[^>]*>\s*Tất cả/')
        ->and($html)->toContain('aria-label="Đi tới phần Định dạng: JPG, PNG, WEBP và 2 mục khác"');
});

it('keeps the same ids across renders, so a live update never renames the radio groups', function (): void {
    $render = fn (): string => (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => filterSections()]);

    preg_match('/aria-controls="(lf-[A-Za-z0-9]{8})"/', $render(), $a);
    preg_match('/aria-controls="(lf-[A-Za-z0-9]{8})"/', $render(), $b);

    expect($a[1])->toBe($b[1]);
});

it('gives the section panel and the sheet their own radio group names', function (): void {
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => filterSections()]);

    preg_match_all('/type="radio"\s+name="([^"]+)"/', $html, $m);

    expect(array_values(array_unique($m[1])))->toHaveCount(2);
});

it('shows the chosen one-choice value after the section name when the list has one column', function (): void {
    app()->setLocale('vi');
    $sections = filterSections();
    $sections[0]['value'] = 'trash';
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => $sections]);

    expect($html)->toMatch('/<span x-show="cols === 1"[^>]*>: Thùng rác<\/span>/')
        ->and($html)->toMatch('/data-fsec="status" data-act="1"/');
});

it('copes with a one-choice section whose first option is an empty "all" value', function (): void {
    app()->setLocale('vi');
    $site = ['key' => 'site', 'label' => 'Website', 'model' => 'site', 'options' => [
        ['value' => '', 'label' => 'Mọi website'], ['value' => 'av', 'label' => 'An Vinh'],
    ]];
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => [$site]]);

    expect($html)->toMatch('/data-fsec="site" data-act="0"/')
        ->and($html)->toContain('aria-label="Đi tới phần Website: Mọi website"');
});

it('still names and marks a chosen value that is not among the options', function (): void {
    app()->setLocale('vi');
    $sections = filterSections();
    $sections[1]['value'] = ['svg'];
    $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$sections" />', ['sections' => $sections]);

    expect($html)->toMatch('/data-fsec="kind" data-act="1"/')
        ->and($html)->toContain('aria-label="Đi tới phần Loại tệp: svg"');
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

it('puts More right after the last group and Trash at the far end', function (): void {
    $html = (string) $this->blade('<x-bs-admin::group-row :groups="$groups" active="all" model="group" label="Nhóm tệp" />', ['groups' => array_reverse(statusGroups())]);

    expect(strpos($html, 'data-g="all"'))->toBeLessThan(strpos($html, 'aria-haspopup="menu"'))
        ->and(strpos($html, 'aria-haspopup="menu"'))->toBeLessThan(strpos($html, 'data-g="trash"'))
        ->and($html)->toMatch('/data-g="trash" data-pin="1"\s+data-trash/');
});

it('says what is filtered in plain words, with Clear and one removable chip per condition', function (): void {
    app()->setLocale('vi');
    $html = (string) $this->blade('<x-bs-admin::filter-status group="Tạm ngưng" summary="1 website" :chips="$chips" clear="clearFilters" />', [
        'chips' => [['label' => 'Người tạo: Minh Tuấn', 'remove' => "removeFilter('author')"]],
    ]);

    expect($html)->toContain('role="status"')
        ->and($html)->toContain('<strong')
        ->and($html)->toContain('Tạm ngưng')
        ->and($html)->toContain('1 website')
        ->and($html)->toContain('wire:click="clearFilters"')
        ->and($html)->toContain('aria-label="'.__('admin.list.remove_filter', ['name' => 'Người tạo: Minh Tuấn']).'"')
        ->and(strpos($html, 'clearFilters'))->toBeLessThan(strpos($html, 'Người tạo: Minh Tuấn'));
});

it('shows no status line while every group and condition is off', function (): void {
    $html = (string) $this->blade('<x-bs-admin::filter-status summary="12 tệp" clear="clearFilters" />');

    expect(trim($html))->toBe('');
});

it('never starts the status line with a separator', function (): void {
    $html = (string) $this->blade('<x-bs-admin::filter-status :chips="$chips" clear="clearFilters" />', [
        'chips' => [['label' => 'Ảnh', 'remove' => "removeFilter('kind')"]],
    ]);

    expect($html)->not->toContain('<span aria-hidden="true">·</span>');
});
