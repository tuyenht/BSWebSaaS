<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;

/*
 * The topbar of the tenant admin sizes its controls by the shared tokens: each icon button is a
 * main control (40 at the default text size, 44 from the next size up) and 44 on touch; each row of
 * the language and account menus is a menu row (40 / 44 / 48 / 48, 48 on touch) at the user's text
 * size, never a fixed 14px.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * The rendered tenant admin shell, as an editor.
 */
function topbarShell(): HTMLDocument
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));
    $html = test()->actingAs($user->fresh())->get('http://a.test/admin/content')->assertOk()->getContent();

    return HTMLDocument::createFromString((string) $html, LIBXML_NOERROR);
}

it('sizes every topbar icon button as a main control, 44 on touch', function (): void {
    $buttons = topbarShell()->querySelectorAll('header.admin-topbar button[aria-label]');

    // menu (phone) · collapse (desktop) · language · fullscreen · light/dark · notifications
    expect($buttons->length)->toBe(6);
    foreach ($buttons as $button) {
        expect(explode(' ', (string) $button->getAttribute('class')))
            ->toContain('size-control', 'any-pointer-coarse:size-hit')
            ->not->toContain('size-10');
    }
});

it('sizes the language and account menu rows as menu rows at the user text size', function (): void {
    $rows = topbarShell()->querySelectorAll('header.admin-topbar [role=menu] [role=menuitem]');

    expect($rows->length)->toBeGreaterThanOrEqual(2);
    foreach ($rows as $row) {
        expect(explode(' ', (string) $row->getAttribute('class')))
            ->toContain('min-h-menu-row', 'any-pointer-coarse:min-h-menu-row-touch', 'text-body')
            ->not->toContain('text-sm');
    }
});
