<?php

/*
 * The phone card of the shared facts list and its card line. A facts list whose boards draw the row
 * buttons on the card (15 «Biểu mẫu», 22 «Lời mời», 28 «Thư viện khối») opts in with `card-acts`:
 * below 768 the card keeps its status pill at the top right and its buttons + ⋮ on a row at the
 * bottom. The others (03 «Khách hàng», 20 «Đăng nhập hỗ trợ», 22 «Thành viên») keep only ⋮ beside
 * the name. The card line joins its pieces with «·» that never opens a wrapped line and is never
 * drawn inside a status pill. In a tree (categories, board 10) the language versions are the
 * «Chưa dịch» note alone below 900 and the chips alone from 900.
 */

function phoneCardCss(): string
{
    return (string) file_get_contents(resource_path('css/admin-list.css'));
}

function phoneCardBand(string $from, string $to): string
{
    $css = phoneCardCss();
    $start = strpos($css, $from);
    $end = strpos($css, $to, (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($css, (int) $start, (int) $end - (int) $start);
}

function phoneCardView(string $path): string
{
    return (string) file_get_contents(base_path("app/Modules/Admin/resources/views/livewire/{$path}.blade.php"));
}

it('marks a facts list that keeps its row buttons on the card, and only a facts list', function (): void {
    $on = (string) $this->blade('<x-bs-admin::list kind="facts" card-acts label="L"></x-bs-admin::list>');
    $off = (string) $this->blade('<x-bs-admin::list kind="facts" label="L"></x-bs-admin::list>');
    $posts = (string) $this->blade('<x-bs-admin::list kind="posts" card-acts label="L"></x-bs-admin::list>');

    expect($on)->toContain('data-kind="facts"')->toContain('data-card="acts"')
        ->and($off)->not->toContain('data-card=')
        ->and($posts)->not->toContain('data-card=');
});

it('keeps the status pill and the row buttons on a card-acts card below 768', function (): void {
    $band = phoneCardBand('/* A facts list whose card keeps its row buttons', '/* data-cols="forms"');
    $sel = ".list-rows[data-kind='facts'][data-card='acts']";

    expect($band)->toContain('@container list (width < 48rem)')
        ->toContain("{$sel} .list-c-act [data-row-btn] {\n            display: inline-flex !important;")
        ->toMatch('/'.preg_quote($sel, '/').' \.list-c-st \{\s*display: flex;\s*order: 2;/')
        ->toMatch('/'.preg_quote($sel, '/').' \.list-c-act \{\s*order: 4;\s*flex: 1 1 100%;/')
        ->toMatch('/'.preg_quote($sel, '/').' \.list-line \{\s*display: flex;/')
        // the block library also shows its code, region and usage on the card, «Sửa» filling its row
        ->toMatch('/'.preg_quote($sel, '/').'\[data-cols=\'blocks\'\] \.list-c-f \{\s*display: flex;/')
        ->toMatch('/'.preg_quote($sel, '/').'\[data-cols=\'blocks\'\] \.list-c-f2::before \{\s*content: none;/');

    // the plain facts card still hides the row buttons (boards 03, 20): the opt-in is what keeps them
    expect(phoneCardCss())->toMatch("/@container list \\(width < 37\\.5rem\\) \\{.*?\\.list-rows\\[data-kind='facts'\\] \\.list-c-act \\[data-row-btn\\] \\{\\s*display: none !important;/s");
});

it('opts in exactly the facts lists whose boards draw the row buttons on the card', function (string $view, bool $acts): void {
    preg_match('/<x-bs-admin::list kind="facts"[^>]*>/', phoneCardView($view), $tag);

    expect($tag)->not->toBeEmpty();
    $acts ? expect($tag[0])->toContain(' card-acts ') : expect($tag[0])->not->toContain('card-acts');
})->with([
    'blocks (board 28)' => ['blocks/library', true],
    'forms (board 15)' => ['forms/builder', true],
    'invitations (board 22)' => ['users/invitations', true],
    'members (board 22)' => ['users/index', false],
    'customers (board 03)' => ['platform/tenants/index', false],
    'support sessions (board 20)' => ['platform/support/index', false],
    // roles and redirects keep their buttons through their own card rules (to 900)
    'roles' => ['roles/index', false],
    'redirects' => ['settings/redirects', false],
]);

it('shows an invitation\'s status once on its card: the pill at the top right, not in the line', function (): void {
    preg_match('/<p class="list-line m-0">(.*?)<\/p>/s', phoneCardView('users/invitations'), $line);

    expect($line)->not->toBeEmpty()
        ->and($line[1])->not->toContain('status-pill')
        ->and(phoneCardView('users/invitations'))->toMatch('/<x-bs-admin::list\.cell col="st">\s*<x-bs-admin::status-pill/');
});

it('never starts a wrapped card line with «·» and never draws one inside a status pill', function (): void {
    $css = phoneCardCss();

    expect($css)->not->toContain('.list-line > * + *::before')
        ->toMatch('/\.list-line \{[^}]*clip-path: inset\(-100vh -100vw -100vh calc\(-1 \* var\(--list-dg\)\)\);/')
        ->toMatch('/\.list-line > \*::before \{\s*content: \'·\';\s*display: inline-block;\s*width: var\(--list-dw\);\s*margin-inline: calc\(-1 \* \(var\(--list-dw\) \+ var\(--list-dg\)\)\) var\(--list-dg\);/')
        ->toMatch('/\.list-line > \[data-s\]::before \{\s*content: none;/');

    // no list keeps a copy of the old separator for its own widths
    preg_match_all("/\\.list-line > [^{]*::before \\{\\s*content: '·'/", $css, $dots);
    expect($dots[0])->toHaveCount(1);
});

it('shows a tree row\'s missing translations as the note alone below 900 and the chips alone from 900', function (): void {
    $css = phoneCardCss();

    expect($css)->toMatch("/@container tree \\(width < 56\\.25rem\\) \\{\\s*\\.tree \\.list-m\\[data-for='langs'\\] \\.list-lcg \\{\\s*display: none;\\s*\\}\\s*\\.tree \\.list-m\\[data-for='langs'\\]:not\\(:has\\(\\.list-lmiss, \\.list-lsw\\)\\) \\{\\s*display: none;/")
        ->toMatch("/@container tree \\(width >= 56\\.25rem\\) \\{\\s*\\.tree \\.list-m\\[data-for='langs'\\] \\.list-lmiss \\{\\s*display: none;/");

    // the categories draw their languages inside the tree's sub line
    expect(phoneCardView('categories/index'))->toMatch('/<x-slot:sub>\s*<span class="list-meta mt-0">.*?<x-bs-admin::list\.langs /s');
});
