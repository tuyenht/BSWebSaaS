<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Exceptions\SchedulePastException;
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

it('never schedules the live Home page, which keeps answering at «/», but schedules a Home page not yet live', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $home = newlyPublishedPage($editor, 'home');

        // a schedule would leave it unpublished until its time, and «/» would answer 404 meanwhile
        expect(fn () => app(TransitionEntryStatus::class)->handle($home, EntryStatus::Scheduled, $editor->fresh(), now()->addDay()))
            ->toThrow(HomePageLockedException::class, __('entries.home_sched_why'));
        expect($home->fresh()->status)->toBe('published');
    });

    $this->get('http://a.test/')->assertOk();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        // a Home page that is not on the website yet may be given its time to go live
        $home = Entry::query()->where('type', 'page')->firstOrFail();
        $home->forceFill(['status' => EntryStatus::Draft->value])->save();   // the menu never takes the live Home page there
        app(TransitionEntryStatus::class)->handle($home->fresh(), EntryStatus::Scheduled, $editor->fresh(), now()->addDay());

        expect($home->fresh()->status)->toBe('scheduled');
    });
});

it('never takes the live Home page off the website — to a draft, private or archived — so «/» keeps answering, while another page still moves', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $home = newlyPublishedPage($editor, 'home');
        $other = newlyPublishedPage($editor);

        foreach ([EntryStatus::Draft, EntryStatus::Private, EntryStatus::Archived] as $target) {
            expect(fn () => app(TransitionEntryStatus::class)->handle($home->fresh(), $target, $editor->fresh()))
                ->toThrow(HomePageLockedException::class, __('entries.home_offline_why'));
            expect($home->fresh()->status)->toBe('published');
        }

        app(TransitionEntryStatus::class)->handle($other, EntryStatus::Private, $editor->fresh());
        expect($other->fresh()->status)->toBe('private');
    });

    $this->get('http://a.test/')->assertOk();
    $this->get('http://a.test/gioi-thieu')->assertNotFound();
});

it('publishes a Home page that is not on the website yet', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $home = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $home->translations()->create(['locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'home', 'status' => 'draft', 'body' => ['blocks' => []]]);

        app(PublishEntry::class)->handle($home->fresh(), 'vi', $editor->fresh());
        expect($home->fresh()->status)->toBe('published');
    });

    $this->get('http://a.test/')->assertOk();
});

it('rejects scheduling without a future datetime', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = newlyPublishedPage($editor);

        expect(fn () => app(TransitionEntryStatus::class)->handle($page, EntryStatus::Scheduled, $editor->fresh(), now()->subHour()))
            ->toThrow(SchedulePastException::class, __('entries.err_schedule_in_past'));
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
