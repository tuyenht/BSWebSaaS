<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadRetention;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * The nightly `leads:purge-old`: each website's leads older than its «Thời hạn lưu giữ liên hệ»
 * (12/24/36 months, 24 when never set, none for «Không tự xóa») are deleted for good, with their
 * search words and the bell's notices of them; a shortened period waits 7 days, keeping the one
 * before meanwhile. Spam goes once sent and last changed over 30 days ago, whatever the period.
 * Leads in the Trash are left to leads:purge-trash. One audit row per website and kind, with counts
 * only; no transaction holds more than 200 rows; a second run deletes nothing.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    $this->travelTo(Carbon::parse(LeadRetention::SINCE, 'UTC')->addDays(30)->setTime(1, 0));
});

/**
 * A lead of $site sent $age ago (a modifier such as '-25 months'), last changed $changed ago (the
 * same when null), with one «new lead» notice of it sent at the same time.
 *
 * @param  array<string, mixed>  $attrs
 */
function polLead(int $site, string $name, string $age, array $attrs = [], ?string $changed = null): FormSubmission
{
    $lead = app(Tenancy::class)->enter($site, function () use ($name, $attrs): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge(['form_id' => $form->id, 'name' => $name, 'phone' => '0912 345 678', 'status' => 'read', 'data' => ['name' => $name]], $attrs));
    });
    $sent = CarbonImmutable::now()->modify($age)->utc();
    $last = CarbonImmutable::now()->modify($changed ?? $age)->utc();
    $admin = DB::connection('pgsql_admin');
    $admin->table('form_submissions')->where('id', $lead->id)->update(['created_at' => $sent->toDateTimeString().'+00', 'updated_at' => $last->toDateTimeString().'+00']);
    $admin->table('site_notifications')->insert([
        'ulid' => (string) Str::ulid(), 'site_id' => $site, 'user_id' => User::factory()->create()->id, 'kind' => 'lead.new',
        'data' => json_encode(['submission' => $lead->ulid, 'name' => $name]), 'search_text' => Str::ascii($name), 'created_at' => $sent->toDateTimeString().'+00',
    ]);

    return $lead;
}

/** @return array{lead: bool, terms: int, notices: int} what is left of the lead, read past RLS */
function polLeft(FormSubmission $lead): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'lead' => $admin->table('form_submissions')->where('id', $lead->id)->exists(),
        'terms' => $admin->table('lead_search_terms')->where('lead_id', $lead->id)->count(),
        'notices' => $admin->table('site_notifications')->whereRaw("data->>'submission' = ?", [$lead->ulid])->count(),
    ];
}

/** @param array<string, mixed> $value */
function polSetting(int $site, array $value): void
{
    app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->updateOrCreate(['group_name' => LeadRetention::GROUP, 'key' => LeadRetention::KEY], ['value' => $value]));
}

/** @return list<array{site: int, action: string, meta: array<string, mixed>}> */
function polAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'like', 'forms.leads.%_purged')->orderBy('site_id')->orderBy('action')->get()
        ->map(function ($row): array {
            $meta = json_decode((string) $row->meta, true);
            ksort($meta);

            return ['site' => (int) $row->site_id, 'action' => (string) $row->action, 'meta' => $meta];
        })->all();
}

it('keeps 24 months when the website never chose, and deletes older leads with their search words and notices', function (): void {
    $old = polLead(1, 'Khách 25 tháng', '-25 months');
    $kept = polLead(1, 'Khách 23 tháng', '-23 months');
    expect(polLeft($old)['terms'])->toBeGreaterThan(0);

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 1 lead(s)')->assertSuccessful();

    expect(polLeft($old))->toBe(['lead' => false, 'terms' => 0, 'notices' => 0])
        ->and(polLeft($kept)['lead'])->toBeTrue()
        ->and(polLeft($kept)['notices'])->toBe(1)
        ->and(polLeft($kept)['terms'])->toBeGreaterThan(0);
    $audits = polAudits();
    expect($audits)->toBe([['site' => 1, 'action' => 'forms.leads.retention_purged', 'meta' => ['count' => 1, 'months' => 24, 'scheduled' => true]]])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->get()->toJson(JSON_UNESCAPED_UNICODE))->not->toContain('Khách', '0912', $old->ulid);

    // a second run finds nothing more to do
    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 0 lead(s)')->assertSuccessful();
    expect(polAudits())->toHaveCount(1)->and(polLeft($kept)['lead'])->toBeTrue();
});

it('honours each website\'s own period, and touches no other website', function (): void {
    polSetting(1, ['months' => 12, 'was' => null, 'shortened_at' => null]);
    polSetting(2, ['months' => 36, 'was' => null, 'shortened_at' => null]);
    $a13 = polLead(1, 'A 13 tháng', '-13 months');
    $a11 = polLead(1, 'A 11 tháng', '-11 months');
    $b25 = polLead(2, 'B 25 tháng', '-25 months');
    $b37 = polLead(2, 'B 37 tháng', '-37 months');

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 2 lead(s)')->assertSuccessful();

    expect(polLeft($a13)['lead'])->toBeFalse()
        ->and(polLeft($a11)['lead'])->toBeTrue()
        ->and(polLeft($b25)['lead'])->toBeTrue()
        ->and(polLeft($b37)['lead'])->toBeFalse()
        ->and(array_map(fn (array $a): array => [$a['site'], $a['meta']['months']], polAudits()))->toBe([[1, 12], [2, 36]]);
});

it('deletes nothing for age under «Không tự xóa», and still clears old spam', function (): void {
    polSetting(1, ['months' => null, 'was' => null, 'shortened_at' => null]);
    $ancient = polLead(1, 'Khách 5 năm', '-60 months');
    $spam = polLead(1, 'Thư rác cũ', '-60 days', ['status' => 'spam']);

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 0 lead(s) past their website\'s retention period and 1 spam')->assertSuccessful();

    expect(polLeft($ancient)['lead'])->toBeTrue()
        ->and(polLeft($spam))->toBe(['lead' => false, 'terms' => 0, 'notices' => 0])
        ->and(polAudits())->toBe([['site' => 1, 'action' => 'forms.leads.spam_purged', 'meta' => ['count' => 1, 'days' => 30, 'scheduled' => true]]]);
});

it('waits 7 days after a shortening, keeping the period before meanwhile', function (): void {
    polSetting(1, ['months' => 12, 'was' => 24, 'shortened_at' => now()->subDays(3)->utc()->toIso8601String()]);
    polSetting(2, ['months' => 12, 'was' => null, 'shortened_at' => now()->subDays(3)->utc()->toIso8601String()]);
    $a13 = polLead(1, 'A 13 tháng', '-13 months');
    $a25 = polLead(1, 'A 25 tháng', '-25 months');
    $b30 = polLead(2, 'B 30 tháng', '-30 months');

    $this->artisan('leads:purge-old')->assertSuccessful();
    expect(polLeft($a13)['lead'])->toBeTrue()
        ->and(polLeft($a25)['lead'])->toBeFalse()
        ->and(polLeft($b30)['lead'])->toBeTrue();

    // the wait is over four days later (7 days after the shortening)
    $this->travel(4)->days();
    $this->artisan('leads:purge-old')->assertSuccessful();
    expect(polLeft($a13)['lead'])->toBeFalse()
        ->and(polLeft($b30)['lead'])->toBeFalse();
});

it('clears spam only once both sent and marked over 30 days ago, never in the Trash', function (): void {
    $gone = polLead(1, 'Rác 60 ngày', '-60 days', ['status' => 'spam']);
    $markedToday = polLead(1, 'Rác đánh dấu hôm nay', '-200 days', ['status' => 'spam'], '-1 hour');
    $young = polLead(1, 'Rác 10 ngày', '-10 days', ['status' => 'spam']);
    $notSpam = polLead(1, 'Khách 60 ngày', '-60 days', ['status' => 'read']);
    $trashed = polLead(1, 'Rác trong Thùng rác', '-60 days', ['status' => 'spam']);
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $trashed->id)->update(['deleted_at' => now()->subDays(2)->utc()->toDateTimeString().'+00']);

    $this->artisan('leads:purge-old')->expectsOutputToContain('and 1 spam')->assertSuccessful();

    expect(polLeft($gone)['lead'])->toBeFalse();
    foreach ([$markedToday, $young, $notSpam, $trashed] as $lead) {
        expect(polLeft($lead)['lead'])->toBeTrue();
    }
});

it('leaves leads in the Trash to the Trash, however old', function (): void {
    $trashed = polLead(1, 'Khách trong Thùng rác', '-30 months');
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $trashed->id)->update(['deleted_at' => now()->subDays(2)->utc()->toDateTimeString().'+00']);

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 0 lead(s)')->assertSuccessful();

    expect(polLeft($trashed))->toMatchArray(['lead' => true, 'notices' => 1]);
});

it('works through many leads with no transaction holding more than 200 rows', function (): void {
    $lead = polLead(1, 'Khách mẫu', '-1 month');
    // 450 leads sent 30 months ago and 250 old spam, written past RLS
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into form_submissions (site_id, ulid, form_id, name, status, created_at, updated_at)
        select 1, lpad('o' || g::text, 26, '0'), ?, 'Khách ' || g, case when g > 450 then 'spam' else 'read' end,
               case when g > 450 then now() - interval '90 days' else now() - interval '30 months' end - g * interval '1 second', now() - interval '90 days'
        from generate_series(1, 700) g
    SQL, [$lead->form_id]);
    $deletes = [];
    DB::listen(function (QueryExecuted $query) use (&$deletes): void {
        if (str_starts_with(strtolower($query->sql), 'delete from "form_submissions"')) {
            $deletes[] = count($query->bindings);
        }
    });

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 450 lead(s) past their website\'s retention period and 250 spam')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('site_id', 1)->count())->toBe(1)
        ->and($deletes)->toHaveCount(5)
        ->and(max($deletes))->toBeLessThanOrEqual(201) // 200 ids + the site_id of the scope
        ->and(array_map(fn (array $a): int => $a['meta']['count'], polAudits()))->toBe([450, 250]);
});

it('deletes nothing until a week after the rule reached the websites', function (): void {
    $this->travelTo(Carbon::parse(LeadRetention::SINCE, 'UTC')->addDays(LeadRetention::WAIT_DAYS)->subHour());
    $old = polLead(1, 'Khách 30 tháng', '-30 months');
    $spam = polLead(1, 'Rác 60 ngày', '-60 days', ['status' => 'spam']);

    $this->artisan('leads:purge-old')->expectsOutputToContain('Purged 0 lead(s)')->assertSuccessful();

    expect(polLeft($old)['lead'])->toBeTrue()->and(polLeft($spam)['lead'])->toBeTrue();
});

it('leaves a website alone for age when its setting cannot be read', function (): void {
    polSetting(1, ['months' => 6]);
    $old = polLead(1, 'Khách 30 tháng', '-30 months');
    $spam = polLead(1, 'Rác 60 ngày', '-60 days', ['status' => 'spam']);
    Log::spy();

    $this->artisan('leads:purge-old')->assertSuccessful();

    expect(polLeft($old)['lead'])->toBeTrue()->and(polLeft($spam)['lead'])->toBeFalse();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'retention setting') && $context === ['site_id' => 1])->once();
});

it('is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leads:purge-old'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('starts the wait again on a second shortening, keeping the period in force', function (): void {
    $now = CarbonImmutable::now();
    $first = LeadRetention::next(LeadRetention::shape(['months' => 36, 'was' => null, 'shortened_at' => null]), 24, $now);
    expect($first)->toBe(['months' => 24, 'was' => 36, 'shortened_at' => $now->utc()->toIso8601String()]);

    $later = $now->addDays(3);
    $second = LeadRetention::next(LeadRetention::shape($first), 12, $later);
    expect($second)->toBe(['months' => 12, 'was' => 36, 'shortened_at' => $later->utc()->toIso8601String()])
        ->and(LeadRetention::inForce(LeadRetention::shape($second), $later->addDays(6)))->toBe(36)
        ->and(LeadRetention::inForce(LeadRetention::shape($second), $later->addDays(7)))->toBe(12);

    // longer, or back to the period in force: at once, no wait
    expect(LeadRetention::next(LeadRetention::shape($second), 36, $later->addDay()))->toBe(['months' => 36, 'was' => null, 'shortened_at' => null])
        ->and(LeadRetention::next(LeadRetention::shape(null), null, $now))->toBe(['months' => null, 'was' => null, 'shortened_at' => null])
        ->and(LeadRetention::next(LeadRetention::shape(['months' => null, 'was' => null, 'shortened_at' => null]), 36, $now)['was'])->toBeNull()
        ->and(LeadRetention::next(LeadRetention::shape(['months' => null, 'was' => null, 'shortened_at' => null]), 36, $now)['shortened_at'])->not->toBeNull();
});
