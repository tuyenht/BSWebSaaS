<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Support\DateChangeAudit;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\ChangeEntryExpiry;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Actions\UnpublishExpiredEntry;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\ExpiredContentException;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Cms\Validation\DisplayDateNotAfterToday;
use App\Modules\Cms\Validation\ExpiryTime;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\ContentSectionCategory;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\StorefrontCards;

/*
 * A post's or page's end time («Gỡ khỏi website lúc», entries.expires_at). From that time a visitor
 * gets 404 at once (the address resolver), the edge keeps the page no longer than until then, and the
 * every-minute sweep (entries:unpublish-due) takes it down: archived, out of the lists, the sitemap
 * and the search, the edge purged, a status audit row by «scheduler». Under an approval workflow the
 * sweep goes through the engine (the flow's own move off, else forced and logged). Staff still preview
 * it; the Home page never takes one. Every change of a date writes an audit row with from, to and who.
 */

const EXP_HOST = 'http://mau.platform.test';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
});

/** The provisioned site with one live news post at /tin-tuc/tuyen-tho-han ending at $expiresAt. */
function expSite(?CarbonImmutable $expiresAt): array
{
    $siteId = StorefrontCards::site();
    $entry = app(Tenancy::class)->enter($siteId, function () use ($expiresAt): Entry {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Tuyển thợ hàn', 'tuyen-tho-han', CarbonImmutable::now()->subDay(), [], 'Tuyển thợ hàn bậc 3.');
        $post->forceFill(['expires_at' => $expiresAt])->save();

        return $post->refresh();
    });

    return [$siteId, $entry];
}

/** The key of the content section that covers the news category, made when the site has none (a category belongs to one section only). */
function expNewsSection(): string
{
    $news = StorefrontCards::news()->id;
    $key = ContentSection::query()
        ->whereIn('id', ContentSectionCategory::query()->where('taxonomy_id', $news)->select('content_section_id'))
        ->value('key');
    if ($key !== null) {
        return Cast::string($key);
    }
    ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $news]);

    return 'tin-tuc';
}

/** A member of $siteId who may change any entry's status. */
function expPublisher(int $siteId, array $permissions = ['entries.view', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/**
 * A new request's state: the scoped instances forgotten, and so the controllers the router keeps from
 * an earlier request (the sitemap's holds the current site it was built with) built again with them.
 */
function expFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $route->flushController();
    }
    app(TeamContext::class)->forget();
}

it('answers 404 to a visitor from the end time on, before the sweep has run', function (): void {
    expSite(CarbonImmutable::now()->addMinutes(2));
    $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertOk();

    $this->travel(2)->minutes();
    expFresh();

    $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertNotFound();
});

it('lets the edge keep the page only until its end time, with no stale window', function (): void {
    expSite(CarbonImmutable::now()->addSeconds(90));

    $cache = (string) $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertOk()->headers->get('Cache-Control');

    expect($cache)->toContain('s-maxage=90')->toContain('stale-while-revalidate=0');
});

it('keeps the usual edge lifetime for a page with no end time', function (): void {
    expSite(null);

    $cache = (string) $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertOk()->headers->get('Cache-Control');

    expect($cache)->not->toContain('stale-while-revalidate=0')->not->toContain('s-maxage=90');
});

it('takes a due post down at the sweep: archived, out of the list, the sitemap and the search, the edge purged, a scheduler audit row', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    expect((string) $this->get(EXP_HOST.'/tin-tuc')->getContent())->toContain('Tuyển thợ hàn')
        ->and((string) $this->get(EXP_HOST.'/sitemap.xml')->getContent())->toContain('/tin-tuc/tuyen-tho-han');

    $this->travel(2)->minutes();
    expFresh();
    $edge = edgeCacheRecorder();
    $this->artisan('entries:unpublish-due')->assertSuccessful();

    expect($edge->calls)->not->toBe([]);
    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh()->status)->toBe(EntryStatus::Archived->value);
        $row = AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('auditable_id', $entry->id)->sole();
        expect($row->actor_id)->toBeNull()
            ->and($row->actor_label)->toBe('scheduler')
            ->and($row->meta)->toMatchArray(['from' => 'published', 'to' => 'archived', 'via' => 'scheduler', 'reason' => 'expired']);
    });

    expFresh();
    expect((string) $this->get(EXP_HOST.'/tin-tuc')->getContent())->not->toContain('Tuyển thợ hàn')
        ->and((string) $this->get(EXP_HOST.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->getContent())->not->toContain('/tin-tuc/tuyen-tho-han')
        ->and((string) $this->get(EXP_HOST.'/tim-kiem?q='.rawurlencode('thợ hàn'))->assertOk()->getContent())->not->toContain('Tuyển thợ hàn');
    $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertNotFound();
});

it('leaves a post whose end time is still to come, and does not count it twice', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addHour());

    $this->artisan('entries:unpublish-due')->assertSuccessful();
    app(Tenancy::class)->enter($siteId, fn () => expect($entry->fresh()->status)->toBe('published'));

    $this->travel(2)->hours();
    $this->artisan('entries:unpublish-due')->assertSuccessful();
    $this->artisan('entries:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, fn () => expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->count())->toBe(1));
});

it('still shows the staff preview of a post past its end time', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->subMinute()->addSecond());
    $this->travel(1)->minutes();
    $member = expPublisher($siteId);

    $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertNotFound();
    expFresh();
    $this->actingAs($member)->get(EXP_HOST.'/admin/content/'.$entry->ulid.'/preview')->assertOk()->assertSee('Tuyển thợ hàn');
});

it('holds the Home page at the sweep and refuses an end time on it', function (): void {
    $siteId = StorefrontCards::site();
    $member = expPublisher($siteId);
    $home = app(Tenancy::class)->enter($siteId, function (): Entry {
        $home = Entry::query()->where('type', 'page')->whereHas('translations', fn ($q) => $q->whereIn('slug', ['home', '']))->firstOrFail();
        $home->forceFill(['expires_at' => now()->addMinute()])->save();

        return $home;
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due')->assertSuccessful()->expectsOutputToContain('1 held');
    expFresh();
    $this->get(EXP_HOST.'/')->assertOk();

    // held once: its end time is cleared, so the next minute does not pick it again
    $this->artisan('entries:unpublish-due')->assertSuccessful()->expectsOutputToContain('0 held');

    app(Tenancy::class)->enter($siteId, function () use ($home, $member): void {
        expect($home->fresh())->status->toBe('published')->expires_at->toBeNull();
        expect(fn () => app(ChangeEntryExpiry::class)->handle($home, now()->addDay(), $member))->toThrow(HomePageLockedException::class);
    });
});

it('takes a governed post off through the flow\'s own move, logged with no person', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('archived')->workflow_state->toBe('archived');
        $log = WorkflowLog::query()->where('entry_id', $entry->id)->sole();
        expect($log->transition_key)->toBe('archive')->and($log->actor_id)->toBeNull()
            ->and($log->from_state)->toBe('published')->and($log->to_state)->toBe('archived')
            ->and($log->note)->toBe(__('workflow.note_expired'));
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->sole()->meta['workflow'])->toBe('moved');
    });
});

it('forces a governed post off when its flow has no move off the website, and logs it as the system\'s', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
        WorkflowTransition::query()->where('from_state', 'published')->delete();
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due')->assertSuccessful()->expectsOutputToContain('1 more forced off');

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('archived')->workflow_state->toBe('archived');
        $log = WorkflowLog::query()->where('entry_id', $entry->id)->sole();
        expect($log->transition_key)->toBe('system_withdraw')->and($log->actor_id)->toBeNull()
            ->and($log->labels['transition'])->toBe(__('workflow.action_system_withdraw'));
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->sole()->meta['workflow'])->toBe(UnpublishExpiredEntry::FORCED);
    });
});

it('refuses to put a post back online past its end time, on every road', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    $member = expPublisher($siteId);
    $this->travel(2)->minutes();
    $this->artisan('entries:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, function () use ($entry, $member): void {
        expect(fn () => app(PublishEntry::class)->handle($entry->fresh(), 'vi', $member))->toThrow(ExpiredContentException::class)
            ->and(fn () => app(TransitionEntryStatus::class)->handle($entry->fresh(), EntryStatus::Scheduled, $member, now()->addDay()))->toThrow(ExpiredContentException::class)
            ->and($entry->fresh()->status)->toBe('archived');

        // cleared first: it goes live again
        app(ChangeEntryExpiry::class)->handle($entry->fresh(), null, $member);
        app(PublishEntry::class)->handle($entry->fresh(), 'vi', $member);
        expect($entry->fresh()->status)->toBe('published');
    });
});

it('refuses an approver\'s publish move past the end time under a workflow', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->subMinute()->addSecond());
    $approver = expPublisher($siteId, ['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']);
    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
        $entry->forceFill(['status' => 'pending', 'workflow_state' => 'pending'])->save();
    });
    $this->travel(1)->minutes();

    app(Tenancy::class)->enter($siteId, function () use ($entry, $approver): void {
        expect(fn () => app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh(), 'vi', $approver, 'approve'))->toThrow(ExpiredContentException::class);
        expect($entry->fresh()->status)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});

it('sets, moves and clears an end time, one audit row each with from, to and who', function (): void {
    [$siteId, $entry] = expSite(null);
    $member = expPublisher($siteId);
    $first = CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC');
    $second = CarbonImmutable::parse('2026-12-05 10:00:00', 'UTC');

    Auth::login($member);
    app(Tenancy::class)->enter($siteId, function () use ($entry, $member, $first, $second): void {
        $action = app(ChangeEntryExpiry::class);
        $action->handle($entry, $first, $member);
        $action->handle($entry, $second, $member);
        $action->handle($entry, null, $member);

        $rows = AuditLog::query()->where('action', Entry::DATES_CHANGED)->orderBy('id')->get();
        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('actor_id')->unique()->all())->toBe([$member->id])
            ->and($rows->map(fn (AuditLog $row): array => [$row->meta['field'], $row->meta['from'], $row->meta['to'], $row->meta['via']])->all())->toBe([
                ['expires_at', null, $first->toIso8601String(), 'admin'],
                ['expires_at', $first->toIso8601String(), $second->toIso8601String(), 'admin'],
                ['expires_at', $second->toIso8601String(), null, 'admin'],
            ])
            ->and($rows->first()->meta['ulid'])->toBe($entry->ulid);
    });
});

it('refuses an end time in the past, one not after the scheduled publish time, or from a member who may not publish', function (): void {
    [$siteId, $entry] = expSite(null);
    $member = expPublisher($siteId);
    $author = expPublisher($siteId, ['entries.view', 'entries.edit.any']);

    app(Tenancy::class)->enter($siteId, function () use ($entry, $member, $author): void {
        $action = app(ChangeEntryExpiry::class);
        expect(fn () => $action->handle($entry, now()->subMinute(), $member))->toThrow(ValidationException::class)
            ->and(fn () => $action->handle($entry, now()->addDay(), $author))->toThrow(AuthorizationException::class);

        $entry->forceFill(['status' => 'scheduled', 'published_at' => now()->addDays(3)])->save();
        try {
            $action->handle($entry, now()->addDays(2), $member);
            $this->fail('An end before the scheduled publish time was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors()['expiresAt'][0])->toBe(__('entries.expiry_before_publish', ['time' => now()->addDays(3)->setTimezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y')]));
        }
        $action->handle($entry, now()->addDays(4), $member);

        expect($entry->fresh()->expires_at?->toIso8601String())->toBe(now()->addDays(4)->toIso8601String())
            ->and(AuditLog::query()->where('action', Entry::DATES_CHANGED)->where('meta->field', 'expires_at')->count())->toBe(1);
    });
});

it('refuses, in the database too, an end time not after the publish time', function (): void {
    [$siteId, $entry] = expSite(null);

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect(fn () => DB::transaction(fn () => $entry->forceFill(['expires_at' => $entry->published_at])->save()))
            ->toThrow(QueryException::class);
    });
});

it('records a change of the publish time and of the shown day, and names the content pack when it made it', function (): void {
    [$siteId, $entry] = expSite(null);
    $member = expPublisher($siteId);
    $was = $entry->published_at;

    Auth::login($member);
    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        $entry->update(['published_at' => now()->subDays(2), 'meta' => [...(array) $entry->meta, DisplayDate::KEY => '2026-11-01']]);
    });
    Auth::logout();
    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        app(DateChangeAudit::class)->during(DateChangeAudit::VIA_CONTENT_PACK, fn () => $entry->fresh()->update(['meta' => [DisplayDate::KEY => '2026-11-02']]));
    });

    app(Tenancy::class)->enter($siteId, function () use ($member, $was): void {
        $rows = AuditLog::query()->where('action', Entry::DATES_CHANGED)->orderBy('id')->get();
        expect($rows->map(fn (AuditLog $row): array => [$row->meta['field'], $row->meta['from'], $row->meta['to'], $row->meta['via'], $row->actor_id, $row->actor_label])->all())->toBe([
            ['published_at', $was->toIso8601String(), now()->subDays(2)->toIso8601String(), 'admin', $member->id, $member->email],
            [DisplayDate::KEY, null, '2026-11-01', 'admin', $member->id, $member->email],
            [DisplayDate::KEY, '2026-11-01', '2026-11-02', 'content_pack', null, 'content_pack'],
        ]);
    });
});

it('writes no date audit row for a save that changes no date', function (): void {
    [$siteId, $entry] = expSite(null);

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        $entry->update(['meta' => ['note' => 'x']]);
        $entry->update(['published_at' => $entry->published_at]);

        expect(AuditLog::query()->where('action', Entry::DATES_CHANGED)->exists())->toBeFalse();
    });
});

it('refuses a shown day after today on the site\'s clock, and lets today, a past day or none through', function (): void {
    $zone = 'Asia/Ho_Chi_Minh';
    // 10:00 UTC is 17:00 in Hà Nội: still the 25th there
    $check = fn (?string $day): bool => Validator::make(['d' => $day], ['d' => ['nullable', new DisplayDateNotAfterToday($zone)]])->passes();

    expect($check('2026-11-25'))->toBeTrue()
        ->and($check('2024-01-31'))->toBeTrue()
        ->and($check(null))->toBeTrue()
        ->and($check('2026-11-26'))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-25 18:00:00', 'UTC')); // 01:00 on the 26th in Hà Nội
    expect($check('2026-11-26'))->toBeTrue()
        ->and(Validator::make(['d' => '2026-11-27'], ['d' => [new DisplayDateNotAfterToday($zone)]])->errors()->first('d'))->toBe(__('entries.ed.pd_future'));
});

it('reads an end time on the site\'s clock and names what is wrong with it', function (): void {
    $zone = 'Asia/Ho_Chi_Minh';
    $publishAt = CarbonImmutable::parse('2026-11-27 01:00:00', 'UTC');
    $check = fn (string $value, ?CarbonImmutable $at = null) => Validator::make(['t' => $value], ['t' => [new ExpiryTime($zone, $at)]])->errors()->first('t');

    expect(ExpiryTime::parse('2026-11-26T09:30', $zone)?->utc()->format('Y-m-d H:i'))->toBe('2026-11-26 02:30')
        ->and($check('2026-11-26T09:30'))->toBe('')
        ->and($check('2026-11-25T16:59'))->toBe(__('entries.expiry_past'))
        ->and($check('hôm qua'))->toBe(__('entries.expiry_invalid'))
        ->and($check('2026-11-27T08:00', $publishAt))->toBe(__('entries.expiry_before_publish', ['time' => '08:00 27/11/2026']))
        ->and($check('2026-11-27T08:01', $publishAt))->toBe('');
});

it('reads the stage a governed post is withdrawn to as a member\'s move does: a flow with no end of the line leaves it a draft', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
        // no move off the website and no end of the line: the engine falls back to the flow's start
        WorkflowTransition::query()->where('from_state', 'published')->delete();
        WorkflowState::query()->update(['is_terminal' => false]);
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('draft')->workflow_state->toBe('draft');
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->sole()->meta)->toMatchArray(['from' => 'published', 'to' => 'draft']);
    });
});

it('never publishes a scheduled post whose end time passed before its publish run: archived, with its audit row', function (): void {
    [$siteId, $entry] = expSite(null);
    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        $entry->forceFill(['status' => 'scheduled', 'published_at' => now()->addMinutes(10), 'expires_at' => now()->addMinutes(30)])->save();
    });
    // the scheduler was down past both times
    $this->travel(1)->hours();
    expFresh();
    $edge = edgeCacheRecorder();

    $this->artisan('entries:publish-due')->assertSuccessful()->expectsOutputToContain('Published 0 scheduled')->expectsOutputToContain('1 entr(y/ies) past their end time');

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('archived')->scheduled_by->toBeNull();
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('auditable_id', $entry->id)->sole()->meta)
            ->toMatchArray(['from' => 'scheduled', 'to' => 'archived', 'via' => 'scheduler', 'reason' => 'expired']);
    });
    $this->get(EXP_HOST.'/tin-tuc/tuyen-tho-han')->assertNotFound();
});

it('never puts an approved-for-later post live past its end time, nor holds it as a lost right', function (): void {
    [$siteId, $entry] = expSite(null);
    $member = expPublisher($siteId);
    app(Tenancy::class)->enter($siteId, function () use ($entry, $member): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
        $entry->forceFill(['status' => 'scheduled', 'scheduled_by' => $member->id, 'workflow_state' => 'pending', 'published_at' => now()->addMinutes(10), 'expires_at' => now()->addMinutes(30)])->save();
    });
    $this->travel(1)->hours();
    expFresh();

    $this->artisan('entries:publish-due')->assertSuccessful()->expectsOutputToContain('0 approved entr(y/ies) held back');

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('archived')->scheduled_by->toBeNull()->workflow_state->toBeNull();
        expect(WorkflowLog::query()->where('entry_id', $entry->id)->exists())->toBeFalse();
    });
});

it('judges the shown day only when this save changes it', function (): void {
    $zone = 'Asia/Ho_Chi_Minh';
    $later = CarbonImmutable::now()->addDays(5)->setTimezone($zone)->format('Y-m-d');
    $other = CarbonImmutable::now()->addDays(6)->setTimezone($zone)->format('Y-m-d');

    expect(Validator::make(['d' => $later], ['d' => [new DisplayDateNotAfterToday($zone, $later)]])->passes())->toBeTrue()
        ->and(Validator::make(['d' => $other], ['d' => [new DisplayDateNotAfterToday($zone, $later)]])->passes())->toBeFalse()
        ->and(Validator::make(['d' => $later], ['d' => [new DisplayDateNotAfterToday($zone)]])->passes())->toBeFalse();
});

it('reads a stage in the middle of the flow as waiting for review when the flow\'s move off the website leads there', function (): void {
    [$siteId, $entry] = expSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        app(InstantiateWorkflow::class)->handle('admin_approval', expNewsSection(), active: true);
        // the one move off the website goes back to review
        $moves = WorkflowTransition::query()->where('from_state', 'published')->orderBy('id')->get();
        $moves->skip(1)->each->delete();
        $moves->first()->update(['to_state' => 'pending']);
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due')->assertSuccessful();

    app(Tenancy::class)->enter($siteId, function () use ($entry): void {
        expect($entry->fresh())->status->toBe('pending')->workflow_state->toBe('pending');
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->sole()->meta)->toMatchArray(['from' => 'published', 'to' => 'pending', 'workflow' => 'moved']);
    });
});

it('reads the due posts batch after batch until none is left', function (): void {
    [$siteId] = expSite(CarbonImmutable::now()->addMinute());
    app(Tenancy::class)->enter($siteId, function (): void {
        foreach (['tuyen-tho-tien', 'tuyen-tho-phay'] as $slug) {
            StorefrontCards::post(StorefrontCards::news(), $slug, $slug, CarbonImmutable::now()->subDay(), [], 'Tuyển.')
                ->forceFill(['expires_at' => CarbonImmutable::now()->addMinute()])->save();
        }
    });
    $this->travel(2)->minutes();

    $this->artisan('entries:unpublish-due', ['--batch' => 2])->assertSuccessful()->expectsOutputToContain('Took 3 entr(y/ies)');

    app(Tenancy::class)->enter($siteId, fn () => expect(Entry::query()->where('status', 'published')->whereNotNull('expires_at')->count())->toBe(0));
});

it('stores an end time given in the site\'s own zone as the same instant', function (): void {
    [$siteId, $entry] = expSite(null);
    $member = expPublisher($siteId);
    // 23:30 on 1/12 in Hà Nội is 16:30 UTC; written without its zone it would read 23:30 UTC, 7 hours late
    $local = CarbonImmutable::parse('2026-12-01 23:30:00', 'Asia/Ho_Chi_Minh');

    Auth::login($member);
    app(Tenancy::class)->enter($siteId, function () use ($entry, $member, $local): void {
        app(ChangeEntryExpiry::class)->handle($entry, $local, $member);

        expect($entry->fresh()->expires_at->utc()->toIso8601String())->toBe('2026-12-01T16:30:00+00:00')
            ->and(DB::selectOne("SELECT to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS at FROM entries WHERE id = ?", [$entry->id])->at)->toBe('2026-12-01 16:30');
    });
});
