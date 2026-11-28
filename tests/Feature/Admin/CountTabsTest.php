<?php

/*
 * Tab counts are drawn as the boards' round bubble (admin-base [data-tab-n]: a 1px inner ring in the
 * input border colour, the tint alone being invisible on the card), on every tab row: the kind tabs
 * («Thành viên / Lời mời») and the shared count-tabs (canvas .dli-tabs) that the «Thông báo» pages use.
 */

it('draws a tab count as a ringed round bubble, the chosen tab\'s in the accent ink', function (): void {
    $off = (string) $this->blade('<x-bs-admin::tab-count>6</x-bs-admin::tab-count>');
    $on = (string) $this->blade('<x-bs-admin::tab-count on>6</x-bs-admin::tab-count>');

    expect($off)->toMatch('/<span data-tab-n class="[^"]*\brounded-full\b[^"]*">6<\/span>/')
        ->toContain('shadow-[inset_0_0_0_1px_var(--color-border-input)]')
        ->toContain('whitespace-nowrap')
        ->toContain('bg-surface-hover text-text')
        ->and($on)->toContain('text-primary-text')->not->toContain('bg-surface-hover');
});

it('gives the kind tabs the shared count bubble', function (): void {
    $html = (string) $this->blade('<x-bs-admin::kind-tabs :tabs="[[\'key\' => \'m\', \'label\' => \'Thành viên\', \'count\' => 6], [\'key\' => \'i\', \'label\' => \'Lời mời\', \'count\' => 0]]" active="m" model="tab" panel="mv" label="Xem" />');

    expect($html)->toMatch('/id="mv-tab-m".*?<span data-tab-n class="[^"]*shadow-\[inset_0_0_0_1px_var\(--color-border-input\)\][^"]*">6<\/span>/s')
        ->toMatch('/id="mv-tab-i".*?<span data-tab-n [^>]*>0<\/span>/s');
});

it('draws a server-side tab row with counts that calls the host method, moves by arrow keys and scrolls sideways', function (): void {
    $html = (string) $this->blade('<x-bs-admin::count-tabs :tabs="[[\'key\' => \'tat-ca\', \'label\' => \'Tất cả\', \'count\' => 1234], [\'key\' => \'moi\', \'label\' => \'Chưa đọc\', \'count\' => \'99+\']]" active="tat-ca" action="show" panel="tb-panel" ids="tb-tab" label="Lọc thông báo" class="-mb-px" />');

    expect($html)->toMatch('/<div role="tablist" aria-label="Lọc thông báo"/')
        ->toContain("['ArrowLeft', 'ArrowRight', 'Home', 'End']")
        ->toContain('overflow-x-auto')->toContain('[scrollbar-width:none]')->toContain('-mb-px')
        ->toMatch('/id="tb-tab-tat-ca" aria-controls="tb-panel"[^>]*aria-selected="true" tabindex="0"[^>]*wire:click="show\(&#039;tat-ca&#039;\)"/')
        ->toMatch('/id="tb-tab-moi"[^>]*aria-selected="false" tabindex="-1"[^>]*wire:click="show\(&#039;moi&#039;\)"/')
        ->toMatch('/<span data-tab-n class="[^"]*text-primary-text[^"]*">1(\.|,)234<\/span>/')
        ->toMatch('/<span data-tab-n [^>]*>99\+<\/span>/');

    // a host that keeps the chosen tab in a property sets it instead
    $set = (string) $this->blade('<x-bs-admin::count-tabs :tabs="[[\'key\' => \'a\', \'label\' => \'A\']]" active="a" model="tab" panel="p" label="L" />');
    expect($set)->toContain('id="p-tab-a"')->toContain('wire:click="$set(&#039;tab&#039;, &#039;a&#039;)"')->not->toContain('data-tab-n');
});

it('draws the «Thông báo» tabs through the shared count-tabs, not a hand-built row', function (): void {
    $view = (string) file_get_contents(base_path('app/Modules/Admin/resources/views/livewire/notifications.blade.php'));

    expect($view)->toContain('<x-bs-admin::count-tabs ')
        ->toContain('action="show" panel="tb-panel" ids="tb-tab"')
        ->not->toContain('role="tab"')
        ->and((string) file_get_contents(resource_path('js/admin-notifications.js')))->not->toContain('tabKey');
});
