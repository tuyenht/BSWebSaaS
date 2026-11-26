<?php

declare(strict_types=1);

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * A row tick on the content list is answered in the browser: the box calls the list's own pick()
 * and the selection reaches the server as plain state with the next request, so several quick
 * ticks cost one round trip. The server still draws the bulk bar from that state.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('ticks a row in the browser and draws the bulk bar from the state it sends', function (): void {
    $user = User::factory()->create();
    app()->setLocale('vi');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.delete.any']);
        $ulids = [];
        foreach (['mot', 'hai', 'ba'] as $slug) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $user->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => 'trang-'.$slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            $ulids[] = $entry->ulid;
        }

        $list = Livewire::actingAs($user->fresh())->test(Index::class);

        // no row box asks the server by itself; each one names its own row
        $html = $list->html();
        expect($html)->not->toContain("toggleSelectAll(['".$ulids[0]."'])")
            ->and(substr_count($html, 'x-on:click="pick($el, '))->toBe(3)
            ->and($html)->toContain("pick(\$el, '".$ulids[0]."')")
            ->and($html)->toContain('x-ref="tick"');

        // two ticks made in the browser arrive together: both rows tinted, the bar is drawn
        $list->set('selected', [$ulids[0], $ulids[2]])->assertSeeHtml('data-guide="bulk-bar"');
        $html = $list->html();
        expect(substr_count($html, 'data-sel="1"'))->toBe(2)
            ->and(substr_count($html, 'role="checkbox" aria-checked="true"'))->toBe(2);

        // unticking both in the browser removes the bar
        $list->set('selected', [])->assertDontSeeHtml('data-guide="bulk-bar"');
    });
});
