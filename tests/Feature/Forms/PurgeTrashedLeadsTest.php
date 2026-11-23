<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadTrash;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The daily emptying of each website's «Khách liên hệ» Trash: a lead that has sat there longer than
 * LeadTrash::DAYS is deleted for good, with its search words and the bell's «new lead» notices of
 * it (they carry the visitor's name). Kept leads, leads trashed more recently and other websites'
 * leads are untouched; one audit row per website, with the count and nothing of the visitors;
 * running it again deletes nothing more.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    // well past the day the Trash opened (LeadTrash::SINCE): the window counts from each lead's day
    $this->travelTo(Carbon::parse(LeadTrash::SINCE, 'UTC')->addDays(45)->setTime(1, 0));
});

/** A lead of $site, in the Trash for $days days (null: kept), with one «new lead» notice of it. */
function purgeLead(int $site, string $name, ?float $days): FormSubmission
{
    $lead = app(Tenancy::class)->enter($site, function () use ($name): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(['form_id' => $form->id, 'name' => $name, 'phone' => '0912 345 678', 'status' => 'read', 'data' => ['name' => $name]]);
    });
    $admin = DB::connection('pgsql_admin');
    if ($days !== null) {
        $admin->table('form_submissions')->where('id', $lead->id)->update(['deleted_at' => now()->subMinutes((int) round($days * 1440))->utc()->toDateTimeString().'+00']);
    }
    $user = User::factory()->create()->id;
    $admin->table('site_notifications')->insert([
        'ulid' => (string) Str::ulid(), 'site_id' => $site, 'user_id' => $user, 'kind' => 'lead.new',
        'data' => json_encode(['submission' => $lead->ulid, 'name' => $name]), 'search_text' => Str::ascii($name), 'created_at' => now(),
    ]);

    return $lead;
}

/** @return array{lead: bool, terms: int, notices: int} what is left of the lead, read past RLS */
function purgeLeft(FormSubmission $lead): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'lead' => $admin->table('form_submissions')->where('id', $lead->id)->exists(),
        'terms' => $admin->table('lead_search_terms')->where('lead_id', $lead->id)->count(),
        'notices' => $admin->table('site_notifications')->whereRaw("data->>'submission' = ?", [$lead->ulid])->count(),
    ];
}

it('deletes for good the leads trashed over 30 days ago, with their search words and notices, and nothing else', function (): void {
    $old = purgeLead(1, 'Khách quá hạn', 31);
    $edge = purgeLead(1, 'Khách vừa đủ', 29.9);
    $kept = purgeLead(1, 'Khách còn giữ', null);
    $foreign = purgeLead(2, 'Khách site B', 45);
    $foreignKept = purgeLead(2, 'Khách site B còn giữ', null);
    expect(purgeLeft($old)['terms'])->toBeGreaterThan(0);

    $this->artisan('leads:purge-trash')->expectsOutputToContain('Purged 2 lead(s)')->assertSuccessful();

    expect(purgeLeft($old))->toBe(['lead' => false, 'terms' => 0, 'notices' => 0])
        ->and(purgeLeft($foreign))->toBe(['lead' => false, 'terms' => 0, 'notices' => 0]);
    foreach ([$edge, $kept, $foreignKept] as $left) {
        expect(purgeLeft($left)['lead'])->toBeTrue()
            ->and(purgeLeft($left)['notices'])->toBe(1)
            ->and(purgeLeft($left)['terms'])->toBeGreaterThan(0);
    }

    $audits = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.leads.trash_purged')->orderBy('site_id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('site_id')->map(fn ($id) => (int) $id)->all())->toBe([1, 2])
        ->and(json_decode((string) $audits[0]->meta, true))->toBe(['count' => 1, 'scheduled' => true, 'trash_days' => 30])
        ->and($audits->toJson(JSON_UNESCAPED_UNICODE))->not->toContain('Khách', '0912', $old->ulid);

    // a second run finds nothing more to do
    $this->artisan('leads:purge-trash')->expectsOutputToContain('Purged 0 lead(s)')->assertSuccessful();
    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.leads.trash_purged')->count())->toBe(2)
        ->and(purgeLeft($edge)['lead'])->toBeTrue();
});

it('works through a large Trash in chunks', function (): void {
    $lead = purgeLead(1, 'Khách mẫu', null);
    // 450 more leads of the same form, trashed 40 days ago, written past RLS
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into form_submissions (site_id, ulid, form_id, name, status, created_at, updated_at, deleted_at)
        select 1, lpad('p' || g::text, 26, '0'), ?, 'Khách ' || g, 'read', now() - interval '60 days', now(), ?::timestamptz
        from generate_series(1, 450) g
    SQL, [$lead->form_id, now()->subDays(40)->utc()->toDateTimeString().'+00']);

    $this->artisan('leads:purge-trash')->expectsOutputToContain('Purged 450 lead(s)')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('site_id', 1)->count())->toBe(1)
        ->and(json_decode((string) DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.leads.trash_purged')->value('meta'), true)['count'])->toBe(450);
});

it('purges nothing until a whole window has passed since the Trash opened', function (): void {
    $this->travelTo(Carbon::parse(LeadTrash::SINCE, 'UTC')->addDays(LeadTrash::DAYS)->subHour());
    $lead = purgeLead(1, 'Khách quá hạn', 31);

    expect(LeadTrash::cutoff(now()))->toBeNull();
    $this->artisan('leads:purge-trash')->expectsOutputToContain('Purged 0 lead(s)')->assertSuccessful();
    expect(purgeLeft($lead)['lead'])->toBeTrue();
});

it('is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leads:purge-trash'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
