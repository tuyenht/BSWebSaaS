<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Publishing must be atomic: if the route sync hits a path already owned by another target, the
 * whole publish rolls back — the entry is never left `published` with no reachable route.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('rolls the publish back when the path is already taken, never leaving it published without a route', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);

        $draft = function (string $title, string $slug) use ($editor): Entry {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

            return $entry;
        };

        // A takes /trung-lap.
        $a = $draft('Trang A', 'trung-lap');
        app(PublishEntry::class)->handle($a->fresh(), 'vi', $editor->fresh());

        // B wants the same path → conflict.
        $b = $draft('Trang B', 'trung-lap');

        expect(fn () => app(PublishEntry::class)->handle($b->fresh(), 'vi', $editor->fresh()))
            ->toThrow(RouteConflictException::class);

        // The status flip was rolled back with the failed sync.
        expect($b->fresh()->status)->toBe('draft')
            ->and($b->fresh()->translationFor('vi')->status)->toBe('draft');
    });
});
