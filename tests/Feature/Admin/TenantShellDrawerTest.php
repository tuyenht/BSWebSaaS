<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\Element;
use Dom\HTMLDocument;

/*
 * The tenant admin's phone drawer: one labelled menu button that opens and closes it, Esc and
 * the overlay close it with focus back on the button, Tab stays inside while it is open, the
 * page is never hidden from screen readers (the drawer has no close button of its own), the
 * closed drawer is inert, touch gets 44px rows and buttons, and reduced motion stops the slide.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function tenantShellOne(HTMLDocument $dom, string $selector): Element
{
    $el = $dom->querySelector($selector);
    expect($el)->toBeInstanceOf(Element::class, "missing {$selector}");

    return $el;
}

it('wires the phone drawer for keyboard, screen readers and touch', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $html = $this->actingAs($user->fresh())->get('http://a.test/admin/content')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString((string) $html, LIBXML_NOERROR);

    $shell = tenantShellOne($dom, 'div.admin-shell-wrapper');
    expect($shell->getAttribute('@keydown.escape.window'))->toBe('close($event)')
        ->and((string) $shell->getAttribute('x-data'))->toContain('event?.defaultPrevented')
        ->and((string) $shell->getAttribute('x-data'))->toContain("\$watch('\$store.adminTheme.open'")
        ->and((string) $shell->getAttribute('x-data'))->toContain('$refs.menuButton?.focus()')
        ->and((string) $shell->getAttribute('x-data'))->toContain('#admin-sidebar nav :is(a[href], button)');

    $aside = tenantShellOne($dom, 'aside#admin-sidebar');
    expect($aside->getAttribute('x-trap.noscroll.noreturn.noautofocus'))->toBe('open && mobile')
        ->and(array_filter($aside->getAttributeNames(), fn (string $name): bool => str_contains($name, 'aria-modal')))->toBe([])
        ->and($aside->getAttribute(':role'))->toBe("open && mobile ? 'dialog' : null")
        ->and((string) $aside->getAttribute(':aria-label'))->toContain(__('admin.menu'))
        ->and($aside->getAttribute('x-effect'))->toBe('$el.inert = mobile && ! open')
        ->and(explode(' ', (string) $aside->getAttribute('class')))->toContain('motion-reduce:transition-none');

    $overlay = tenantShellOne($dom, 'div.admin-shell-wrapper > div[x-show="open"]');
    expect($overlay->getAttribute('@click'))->toBe('close()')
        ->and((string) $overlay->getAttribute('x-transition:enter'))->toContain('motion-reduce:transition-none');

    $button = tenantShellOne($dom, 'header button[aria-controls="admin-sidebar"]');
    expect($button->getAttribute('aria-label'))->toBe(__('admin.menu'))
        ->and($button->getAttribute('x-ref'))->toBe('menuButton')
        ->and($button->getAttribute('@click'))->toBe('open ? close() : (open = true)')
        ->and($button->getAttribute(':aria-expanded'))->toBe("open ? 'true' : 'false'")
        ->and(explode(' ', (string) $button->getAttribute('class')))->toContain('pointer-coarse:size-hit');

    $rows = $dom->querySelectorAll('aside#admin-sidebar nav :is(a[href], button)');
    expect($rows->length)->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect(explode(' ', (string) $row->getAttribute('class')))
            ->toContain('min-h-control')
            ->toContain('pointer-coarse:min-h-hit');
    }
});
