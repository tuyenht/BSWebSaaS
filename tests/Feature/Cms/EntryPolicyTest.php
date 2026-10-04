<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * W3 — EntryPolicy (defense layer #2). Maps the entries.* permission registry to
 * abilities with own/any scoping (author_id). Authority is per-site (team = site_id),
 * so a grant on site A does not carry to site B; a platform super_admin overrides
 * every gate via Gate::before. (grantRole lives in tests/Pest.php — shared helper.)
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets an author edit/delete only their OWN entry, and never publish', function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $other = User::factory()->create();

    $tenancy->enter(1, function () use ($author, $other): void {
        grantRole($author, 'author', [
            'entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own', 'entries.submit',
        ]);

        $mine = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
        $theirs = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $other->id]);

        $a = $author->fresh();
        expect($a->can('create', Entry::class))->toBeTrue()
            ->and($a->can('update', $mine))->toBeTrue()
            ->and($a->can('delete', $mine))->toBeTrue()
            ->and($a->can('submit', $mine))->toBeTrue()
            ->and($a->can('update', $theirs))->toBeFalse()
            ->and($a->can('delete', $theirs))->toBeFalse()
            ->and($a->can('publish', $mine))->toBeFalse();
    });
});

it('lets an editor edit/delete ANY entry and publish', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($editor, $author): void {
        grantRole($editor, 'editor', [
            'entries.view', 'entries.edit.any', 'entries.delete.any', 'entries.publish',
        ]);
        $someone = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);

        $e = $editor->fresh();
        expect($e->can('update', $someone))->toBeTrue()
            ->and($e->can('delete', $someone))->toBeTrue()
            ->and($e->can('publish', $someone))->toBeTrue();
    });
});

it('denies a viewer every mutating ability even on their own entry', function (): void {
    $tenancy = app(Tenancy::class);
    $viewer = User::factory()->create();

    $tenancy->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $viewer->id]);

        $v = $viewer->fresh();
        expect($v->can('viewAny', Entry::class))->toBeTrue()
            ->and($v->can('create', Entry::class))->toBeFalse()
            ->and($v->can('update', $entry))->toBeFalse()
            ->and($v->can('publish', $entry))->toBeFalse();
    });
});

it('scopes authority per site — an editor on site 1 has nothing on site 2', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $entryOnTwo = $tenancy->enter(2, fn () => Entry::query()->create([
        'type' => 'page', 'status' => 'draft', 'author_id' => $editor->id,
    ]));

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
    });

    $tenancy->enter(2, function () use ($editor, $entryOnTwo): void {
        expect($editor->fresh()->can('update', $entryOnTwo))->toBeFalse()
            ->and($editor->fresh()->can('publish', $entryOnTwo))->toBeFalse();
    });
});

it('lets a platform super_admin pass every entry gate via Gate::before', function (): void {
    $tenancy = app(Tenancy::class);
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    $tenancy->enter(1, function () use ($user): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => null]);

        $u = $user->fresh();
        expect($u->can('update', $entry))->toBeTrue()
            ->and($u->can('delete', $entry))->toBeTrue()
            ->and($u->can('publish', $entry))->toBeTrue();
    });
});

it('lets a person send for review only an entry they may edit', function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $colleague = User::factory()->create();
    $copyEditor = User::factory()->create();
    $sender = User::factory()->create();

    $tenancy->enter(1, function () use ($author, $colleague, $copyEditor, $sender): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        // Custom roles may mix any permissions: edit anything and send it on, but never publish.
        grantRole($copyEditor, 'copy-editor', ['entries.view', 'entries.edit.any', 'entries.submit']);
        // Sending without any right to edit — a custom role nobody should be able to use to move work.
        grantRole($sender, 'sender', ['entries.view', 'entries.submit']);

        $mine = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
        $theirs = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $colleague->id]);
        $orphan = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => null]);
        $sendersOwn = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $sender->id]);

        $a = $author->fresh();
        $c = $copyEditor->fresh();
        $s = $sender->fresh();
        expect($a->can('submit', $mine))->toBeTrue()
            ->and($a->can('submit', $theirs))->toBeFalse()
            // No author: nobody owns it, so only someone who may edit ANY entry can send it.
            ->and($a->can('submit', $orphan))->toBeFalse()
            ->and($c->can('submit', $theirs))->toBeTrue()
            ->and($c->can('submit', $orphan))->toBeTrue()
            ->and($c->can('publish', $theirs))->toBeFalse()
            ->and($s->can('submit', $sendersOwn))->toBeFalse()
            ->and($s->can('submit', $theirs))->toBeFalse();
    });
});
