<?php

use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;

/*
 * A taken address met while a change goes live («Cập nhật website» or «Duyệt đăng»): the page's own
 * address blames its link field; a sub-page's that moves along with it names that address and its
 * language, never the page's own free link. Both moves map it the same way.
 */

it('says slug_taken when the page’s own address is taken', function (): void {
    $refused = EntryDraftRejectedException::fromRouteConflict(new RouteConflictException('/ve-chung-toi', 7, 'vi'), 7);

    expect($refused->reason)->toBe('slug_taken')
        ->and($refused->path)->toBe('')
        ->and($refused->locale)->toBe('');
});

it('says slug_taken when the address belongs to no entry (a product or a category)', function (): void {
    expect(EntryDraftRejectedException::fromRouteConflict(new RouteConflictException('/san-pham'), 7)->reason)->toBe('slug_taken');
});

it('says subpage_path_taken with the address and its language when a sub-page’s address is taken', function (): void {
    $refused = EntryDraftRejectedException::fromRouteConflict(new RouteConflictException('/about-us/team', 8, 'en'), 7);

    expect($refused->reason)->toBe('subpage_path_taken')
        ->and($refused->path)->toBe('/about-us/team')
        ->and($refused->locale)->toBe('en');
});

it('maps a route conflict through the shared rule on both moves that put a change live', function (string $action): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Cms/Actions/'.$action.'.php');

    expect($source)->toContain('EntryDraftRejectedException::fromRouteConflict($e, $entry->id)')
        ->and($source)->not->toContain("new EntryDraftRejectedException('slug_taken')")
        ->and($source)->not->toContain("new EntryDraftRejectedException('subpage_path_taken'");
})->with(['ApplyEntryDraft', 'ApplyEntryDraftTransition']);
