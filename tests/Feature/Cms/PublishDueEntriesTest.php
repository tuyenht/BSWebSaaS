<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
 * P8 — the entries:publish-due scheduler. A scheduled entry whose published_at has passed goes
 * live (status=published, translations published, routes synced) the next time the command runs;
 * one still in the future stays scheduled. The command sweeps per-site inside Tenancy::enter (RLS).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
});

/** A page whose scheduled slot has just passed, with one vi translation. */
function dueScheduledPage(string $slug): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Trang hẹn giờ', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh() ?? throw new RuntimeException('Page vanished after creation.');
}

/** Nothing of a publish happened to this entry: no route, no revision, no status line. */
function expectNotPublished(Entry $entry): void
{
    expect(SiteRoute::query()->where('entry_id', $entry->id)->exists())->toBeFalse()
        ->and($entry->revisions()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('auditable_id', $entry->id)->exists())->toBeFalse()
        ->and($entry->translations()->where('status', 'published')->exists())->toBeFalse();
}

it('publishes a scheduled entry whose slot has passed and syncs its route', function (): void {
    $tenancy = app(Tenancy::class);
    $entry = null;

    $tenancy->enter(1, function () use (&$entry): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Khuyến mãi', 'slug' => 'khuyen-mai', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'KM']]]],
        ]);
    });

    $this->artisan('entries:publish-due')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->value('path'))->toBe('/khuyen-mai');
    });

    $this->get('http://a.test/khuyen-mai')->assertOk();
});

it('leaves a future-scheduled entry untouched', function (): void {
    $tenancy = app(Tenancy::class);
    $entry = null;

    $tenancy->enter(1, function () use (&$entry): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Tương lai', 'slug' => 'tuong-lai', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });

    $this->artisan('entries:publish-due')->assertSuccessful();

    $tenancy->enter(1, function () use ($entry): void {
        expect($entry->fresh()->status)->toBe('scheduled');
    });
});

it('does not let one broken entry abort the sweep for the rest of that site or the sites after it', function (): void {
    $tenancy = app(Tenancy::class);
    $ids = [];

    $tenancy->enter(1, function () use (&$ids): void {
        // A live page already owns /lien-he…
        $live = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()->subDay()]);
        $live->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($live, 'vi', 'lien-he');

        // …and a scheduled page wants the very same path: RouteSync will refuse it.
        $clashing = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $clashing->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ 2', 'slug' => 'lien-he', 'status' => 'draft', 'body' => ['blocks' => []]]);

        // A healthy one due in the same site, sorted after it.
        $healthy = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $healthy->translations()->create(['locale' => 'vi', 'title' => 'Tin nội bộ', 'slug' => 'tin-noi-bo', 'status' => 'draft', 'body' => ['blocks' => []]]);

        $ids['clashing'] = $clashing->id;
        $ids['healthy'] = $healthy->id;
    });

    $tenancy->enter(2, function () use (&$ids): void {
        $other = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $other->translations()->create(['locale' => 'vi', 'title' => 'Bảng giá', 'slug' => 'bang-gia', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $ids['other_site'] = $other->id;
    });

    // The sweep reports the failure and carries on — the cron runs every minute, so an aborting
    // sweep would keep every later tenant permanently unpublished.
    $this->artisan('entries:publish-due')
        ->expectsOutputToContain('1 failed')
        ->assertSuccessful();

    $tenancy->enter(1, function () use ($ids): void {
        expect(Entry::query()->whereKey($ids['clashing'])->value('status'))->toBe('scheduled')
            ->and(Entry::query()->whereKey($ids['healthy'])->value('status'))->toBe('published');
    });

    $tenancy->enter(2, function () use ($ids): void {
        expect(Entry::query()->whereKey($ids['other_site'])->value('status'))->toBe('published');
    });
});

/*
 * The sweep reads the due rows first and publishes them one by one afterwards. Between the two, an
 * editor can still trash a post, set it back to draft or move its slot: the publisher must judge
 * the row as it is when it holds the lock, not as the sweep read it.
 */

it('does not publish a due entry that was put in the Trash after the sweep read it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $stale = dueScheduledPage('vao-thung-rac');
        Entry::query()->whereKey($stale->id)->firstOrFail()->delete();

        $published = app(PublishScheduledEntry::class)->handle($stale);

        $row = Entry::withTrashed()->whereKey($stale->id)->firstOrFail();
        expect($row->status)->toBe('scheduled')
            ->and($row->trashed())->toBeTrue();
        expectNotPublished($row);
        expect($published)->toBeFalse();
    });
});

it('does not publish a due entry that was set back to draft after the sweep read it', function (): void {
    $editor = User::factory()->create();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $stale = dueScheduledPage('ve-nhap');
        app(TransitionEntryStatus::class)->handle($stale->fresh(), EntryStatus::Draft, $editor->fresh());

        $published = app(PublishScheduledEntry::class)->handle($stale);

        $row = Entry::query()->whereKey($stale->id)->firstOrFail();
        expect($row->status)->toBe('draft')
            ->and(SiteRoute::query()->where('entry_id', $row->id)->exists())->toBeFalse()
            ->and($row->revisions()->exists())->toBeFalse();
        expect($published)->toBeFalse();
    });
});

it('does not publish an entry whose slot was moved into the future after the sweep read it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $stale = dueScheduledPage('doi-gio');
        Entry::query()->whereKey($stale->id)->update(['published_at' => now()->addDay()]);

        $published = app(PublishScheduledEntry::class)->handle($stale);

        $row = Entry::query()->whereKey($stale->id)->firstOrFail();
        expect($row->status)->toBe('scheduled');
        expectNotPublished($row);
        expect($published)->toBeFalse();
    });
});

it('publishes a due entry and says so', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $due = dueScheduledPage('dung-gio');

        $published = app(PublishScheduledEntry::class)->handle($due);

        expect(Entry::query()->whereKey($due->id)->value('status'))->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $due->id)->value('path'))->toBe('/dung-gio');
        expect($published)->toBeTrue();
    });
});

it('stops a site at a lock conflict, leaves its batch for the next run, and still sweeps the sites after it', function (): void {
    // A lock conflict inside the publisher's savepoint aborts the whole site transaction
    // (Laravel rethrows it without rolling the savepoint back), so nothing more may run in it.
    // A trigger raises the real SQLSTATE for one slug only.
    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_publish_due_lock_conflict() returns trigger language plpgsql as $fn$
        begin
            raise exception 'could not serialize access due to concurrent update' using errcode = '40001';
        end
        $fn$;
        drop trigger if exists test_publish_due_lock_conflict on entry_translations;
        create trigger test_publish_due_lock_conflict before update on entry_translations
            for each row when (new.slug = 'xung-dot-khoa' and new.status = 'published')
            execute function test_publish_due_lock_conflict();
        SQL);

    try {
        $tenancy = app(Tenancy::class);
        $ids = [];

        $tenancy->enter(1, function () use (&$ids): void {
            $ids['conflict'] = dueScheduledPage('xung-dot-khoa')->id;
            $ids['after'] = dueScheduledPage('xep-sau')->id;
        });
        $tenancy->enter(2, function () use (&$ids): void {
            $ids['other_site'] = dueScheduledPage('site-khac')->id;
        });

        Exceptions::fake();

        expect(Artisan::call('entries:publish-due'))->toBe(0);

        // Reported once, and nothing ran on in the aborted transaction to fail a second time.
        Exceptions::assertReported(DeadlockException::class);
        Exceptions::assertReportedCount(1);

        $tenancy->enter(1, function () use ($ids): void {
            expect(Entry::query()->whereKey($ids['conflict'])->value('status'))->toBe('scheduled')
                ->and(Entry::query()->whereKey($ids['after'])->value('status'))->toBe('scheduled');
        });
        $tenancy->enter(2, function () use ($ids): void {
            expect(Entry::query()->whereKey($ids['other_site'])->value('status'))->toBe('published');
        });

        // The count says what went live, and that one site is still to do.
        expect(Artisan::output())->toContain('Published 1 scheduled entr(y/ies), skipped 0 governed by an approval workflow, 0 failed, 1 site(s) left for the next run after a lock conflict.');
    } finally {
        $admin->unprepared('drop trigger if exists test_publish_due_lock_conflict on entry_translations; drop function if exists test_publish_due_lock_conflict();');
    }

    // The conflict was momentary: the next minute's run publishes the whole batch.
    $this->artisan('entries:publish-due')
        ->expectsOutputToContain('Published 2 scheduled entr(y/ies)')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($ids): void {
        expect(Entry::query()->whereKey($ids['conflict'])->value('status'))->toBe('published')
            ->and(Entry::query()->whereKey($ids['after'])->value('status'))->toBe('published');
    });
});

it('rolls back the rows a site already published in its batch when a later row hits a lock conflict', function (): void {
    // The conflict comes on the SECOND due row: the first one has gone live inside the site's
    // transaction by then (route, revision, audit line) and must go back with the rest.
    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_publish_due_second_row_conflict() returns trigger language plpgsql as $fn$
        begin
            raise exception 'could not serialize access due to concurrent update' using errcode = '40001';
        end
        $fn$;
        drop trigger if exists test_publish_due_second_row_conflict on entry_translations;
        create trigger test_publish_due_second_row_conflict before update on entry_translations
            for each row when (new.slug = 'xung-dot-sau' and new.status = 'published')
            execute function test_publish_due_second_row_conflict();
        SQL);

    try {
        $ids = [];
        app(Tenancy::class)->enter(1, function () use (&$ids): void {
            $ids['first'] = dueScheduledPage('len-truoc')->id;
            $ids['conflict'] = dueScheduledPage('xung-dot-sau')->id;
        });

        Exceptions::fake();

        $this->artisan('entries:publish-due')
            ->expectsOutputToContain('Published 0 scheduled entr(y/ies), skipped 0 governed by an approval workflow, 0 failed, 1 site(s) left for the next run after a lock conflict.')
            ->assertSuccessful();

        app(Tenancy::class)->enter(1, function () use ($ids): void {
            $first = Entry::query()->whereKey($ids['first'])->firstOrFail();
            expect($first->status)->toBe('scheduled')
                ->and(Entry::query()->whereKey($ids['conflict'])->value('status'))->toBe('scheduled');
            expectNotPublished($first);
        });
    } finally {
        $admin->unprepared('drop trigger if exists test_publish_due_second_row_conflict on entry_translations; drop function if exists test_publish_due_second_row_conflict();');
    }
});

it('defers the site instead of claiming success when its audit line hits a lock conflict', function (): void {
    // The audit trail is best-effort, but a lock conflict in its savepoint leaves the site's
    // transaction aborted: swallowed there, the sweep counted the site as published while its
    // COMMIT silently rolled everything back. A trigger raises the real SQLSTATE for site 1's
    // audit lines only.
    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_publish_due_audit_conflict() returns trigger language plpgsql as $fn$
        begin
            raise exception 'could not serialize access due to concurrent update' using errcode = '40001';
        end
        $fn$;
        drop trigger if exists test_publish_due_audit_conflict on audit_logs;
        create trigger test_publish_due_audit_conflict before insert on audit_logs
            for each row when (new.site_id = 1)
            execute function test_publish_due_audit_conflict();
        SQL);

    try {
        $tenancy = app(Tenancy::class);
        $ids = [];

        $tenancy->enter(1, function () use (&$ids): void {
            $ids['audited'] = dueScheduledPage('ghi-nhat-ky')->id;
            $ids['after'] = dueScheduledPage('ghi-sau')->id;
        });
        $tenancy->enter(2, function () use (&$ids): void {
            $ids['other_site'] = dueScheduledPage('site-khac')->id;
        });

        Exceptions::fake();

        $this->artisan('entries:publish-due')
            ->expectsOutputToContain('Published 1 scheduled entr(y/ies), skipped 0 governed by an approval workflow, 0 failed, 1 site(s) left for the next run after a lock conflict.')
            ->assertSuccessful();

        Exceptions::assertReported(DeadlockException::class);
        Exceptions::assertReportedCount(1);

        $tenancy->enter(1, function () use ($ids): void {
            $audited = Entry::query()->whereKey($ids['audited'])->firstOrFail();
            expect($audited->status)->toBe('scheduled')
                ->and(Entry::query()->whereKey($ids['after'])->value('status'))->toBe('scheduled');
            expectNotPublished($audited);
        });
        $tenancy->enter(2, function () use ($ids): void {
            expect(Entry::query()->whereKey($ids['other_site'])->value('status'))->toBe('published');
        });
    } finally {
        $admin->unprepared('drop trigger if exists test_publish_due_audit_conflict on audit_logs; drop function if exists test_publish_due_audit_conflict();');
    }

    // The next run publishes the site's whole batch.
    $this->artisan('entries:publish-due')
        ->expectsOutputToContain('Published 2 scheduled entr(y/ies)')
        ->assertSuccessful();
});
