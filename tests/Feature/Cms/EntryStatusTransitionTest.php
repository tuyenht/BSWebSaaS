<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * P2 — row status-menu transitions (design §3a). draft/scheduled/private/archived move through
 * TransitionEntryStatus (never a raw write); publishing stays PublishEntry's job. Every move
 * needs the publish ability, so a live page can't be taken offline by a submit-only author.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function newlyPublishedPage(User $editor, string $slug = 'gioi-thieu'): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

    return $page->fresh();
}

it('takes a published page offline (→ draft) and it stops resolving', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = newlyPublishedPage($editor);

        app(TransitionEntryStatus::class)->handle($page, EntryStatus::Draft, $editor->fresh());

        expect($page->fresh()->status)->toBe('draft');
    });

    $this->get('http://a.test/gioi-thieu')->assertNotFound();
});

it('schedules with a future datetime', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = newlyPublishedPage($editor);
        $at = now()->addDay();

        app(TransitionEntryStatus::class)->handle($page, EntryStatus::Scheduled, $editor->fresh(), $at);

        expect($page->fresh()->status)->toBe('scheduled')
            ->and($page->fresh()->published_at->timestamp)->toBe($at->timestamp);
    });
});

it('rejects scheduling without a future datetime', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = newlyPublishedPage($editor);

        expect(fn () => app(TransitionEntryStatus::class)->handle($page, EntryStatus::Scheduled, $editor->fresh(), now()->subHour()))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('refuses to set published directly (that must go through PublishEntry)', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = newlyPublishedPage($editor);

        expect(fn () => app(TransitionEntryStatus::class)->handle($page, EntryStatus::Published, $editor->fresh()))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('denies a submit-only author from taking a live page offline', function (): void {
    $editor = User::factory()->create();
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $author): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        $page = newlyPublishedPage($editor);

        expect(fn () => app(TransitionEntryStatus::class)->handle($page, EntryStatus::Private, $author->fresh()))
            ->toThrow(AuthorizationException::class);
    });
});
