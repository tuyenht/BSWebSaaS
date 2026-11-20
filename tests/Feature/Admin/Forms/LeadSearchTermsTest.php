<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Forms\Actions\EraseLead;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadSearchTerms;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The search box of «Khách liên hệ» reads the word table lead_search_terms: each typed word is the
 * start of one of the lead's words (its name's and email's, folded: no accents, lower case) or a piece
 * of its phone's digits, in any order. The words are written with the lead and changed with its name,
 * email or phone, go with it when it is erased or deleted with its form, and stay on its own website.
 * `forms:search-terms` writes again only the leads whose words differ.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** @param  array<string, mixed>  $attrs */
function termsLead(array $attrs, int $site = 1): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($attrs): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge(['form_id' => $form->id, 'name' => 'Khách', 'status' => 'new', 'data' => []], $attrs));
    });
}

/** @return list<string> */
function termsOf(FormSubmission $lead): array
{
    return DB::connection('pgsql_admin')->table('lead_search_terms')->where('lead_id', $lead->id)
        ->orderByRaw('term collate "C"')->pluck('term')->map(fn ($t): string => (string) $t)->all();
}

function termsManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['forms.view', 'forms.manage']));

    return $user->fresh() ?? $user;
}

it('makes a lead\'s words from its name, its email and every ending of its phone\'s digits', function (): void {
    expect(LeadSearchTerms::of('Nguyễn Thị Hoa', 'Hoa.Nguyen@Example.test', '0912 345 678'))->toBe([
        '0912345678', '12345678', '2345678', '345678', '45678', '5678', '678',
        '912345678', 'example', 'hoa', 'nguyen', 'test', 'thi',
    ])
        ->and(LeadSearchTerms::of(null, null, '12'))->toBe([])
        ->and(LeadSearchTerms::of('Đỗ   Đức', '', null))->toBe(['do', 'duc'])
        ->and(LeadSearchTerms::needles('  Nguyễn ngu  HOA%  '))->toBe(['nguyen', 'hoa'])
        ->and(LeadSearchTerms::needles('% _ -'))->toBe([])
        // a short number is matched whole, so a longer typed word that starts with it does not stand in
        ->and(LeadSearchTerms::needles('45 456'))->toBe(['456', '45'])
        ->and(LeadSearchTerms::range('term', '45'))->toBe(['term = ?', ['45']])
        ->and(LeadSearchTerms::range('term', '456'))->toBe(['term >= ? and term < ?', ['456', '457']]);
});

it('writes the words with the lead, rewrites them when its name or phone changes, and leaves them when only its status does', function (): void {
    $lead = termsLead(['name' => 'Trần Bình', 'phone' => '0988 111']);
    expect(termsOf($lead))->toBe(['0988111', '111', '8111', '88111', '988111', 'binh', 'tran']);

    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        $sent[] = $query->sql;
    });
    app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->findOrFail($lead->id)->update(['status' => 'read']));
    expect(collect($sent)->filter(fn (string $sql): bool => str_contains($sql, 'lead_search_terms'))->all())->toBe([]);

    app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->findOrFail($lead->id)->update(['name' => 'Trần Văn Bình', 'phone' => null]));

    expect(termsOf($lead))->toBe(['binh', 'tran', 'van']);
});

it('takes a lead\'s words away when it is erased, or deleted with its form', function (): void {
    $erased = termsLead(['name' => 'Lý Hà', 'phone' => '0903 112 233']);
    $kept = termsLead(['name' => 'Mai Phương']);
    $this->actingAs(termsManager());

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(EraseLead::class)->handle($erased->ulid)))->toBeTrue()
        ->and(termsOf($erased))->toBe([])
        ->and(termsOf($kept))->toBe(['mai', 'phuong']);

    DB::connection('pgsql_admin')->table('forms')->where('id', $kept->form_id)->delete();
    expect(termsOf($kept))->toBe([]);
});

it('keeps each website\'s words to itself: never read, never written for another website', function (): void {
    $ours = termsLead(['name' => 'Hồ Gia']);
    $theirs = termsLead(['name' => 'Hồ Gia'], 2);

    app(Tenancy::class)->enter(1, function () use ($theirs): void {
        expect(DB::table('lead_search_terms')->distinct()->pluck('site_id')->all())->toBe([1])
            ->and(fn () => DB::transaction(fn () => DB::table('lead_search_terms')->insert(['site_id' => 2, 'lead_id' => $theirs->id, 'term' => 'x'])))
            ->toThrow(QueryException::class);
    });
    app(Tenancy::class)->enter(1, fn () => expect(DB::table('lead_search_terms')->where('lead_id', $theirs->id)->count())->toBe(0));

    expect(termsOf($ours))->toBe(['gia', 'ho'])->and(termsOf($theirs))->toBe(['gia', 'ho']);
});

it('finds a lead by the start of each word typed, in any order, without diacritics, and by a piece of its phone', function (): void {
    $manager = termsManager();
    termsLead(['name' => 'Nguyễn Thị Ánh', 'email' => 'anh.nguyen@example.test', 'phone' => '0912 345 678']);
    termsLead(['name' => 'Trần Văn Đức', 'phone' => '0988000111']);
    termsLead(['name' => 'Nguyễn Văn Đức'], 2);
    termsLead(['name' => 'Phòng 45']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class);
        foreach (['nguyen thi anh', 'ÁNH NGUYỄN', 'ngu an', 'anh.nguyen@', 'example', '345 678', '0912345', '912 345', '678'] as $q) {
            $page->set('search', $q)->assertSee('Nguyễn Thị Ánh')->assertDontSee('Trần Văn Đức');
        }
        $page->set('search', 'đức')->assertSee('Trần Văn Đức')->assertDontSee('Nguyễn Thị Ánh')
            // another website's lead of the same name is never found
            ->set('search', 'nguyen duc')->assertSee(__('leads.no_match'))
            // the start of a word, not its middle
            ->set('search', 'guyen')->assertSee(__('leads.no_match'))
            // one or two digits are a whole word, never the start or the end of a phone
            ->set('search', '78')->assertSee(__('leads.no_match'))
            ->set('search', '09')->assertSee(__('leads.no_match'))
            ->set('search', '45')->assertSee('Phòng 45')->assertDontSee('Nguyễn Thị Ánh')
            ->set('search', '%')->assertSee(__('leads.no_match'));
    });
});

it('gives the same leads, newest first, whether it gathers the rarest word\'s leads or walks the list', function (): void {
    foreach (range(1, 6) as $i) {
        termsLead(['name' => 'Khách hàng '.($i % 2 === 0 ? 'Lan' : 'Mai').' '.$i, 'created_at' => now()->subMinutes($i)]);
    }

    $names = fn (int $gather): array => app(Tenancy::class)->enter(1, function () use ($gather): array {
        $list = new LeadListQuery('khach lan', [], '', '', '', app(SiteTime::class), gather: $gather);

        return [$list->rows('all', null, 10, 10)->pluck('name')->all(), $list->tabCounts()['all']];
    });

    expect($names(LeadListQuery::GATHER))->toBe([['Khách hàng Lan 2', 'Khách hàng Lan 4', 'Khách hàng Lan 6'], 3])
        ->and($names(1))->toBe($names(LeadListQuery::GATHER));
});

it('searches on a flat query budget, however many leads match', function (): void {
    $manager = termsManager();
    termsLead(['name' => 'Đinh Tú']);
    foreach (range(1, 8) as $i) {
        termsLead(['name' => 'Đinh Khoa '.$i]);
    }

    $measure = fn (string $q): int => app(Tenancy::class)->enter(1, function () use ($manager, $q): int {
        $page = Livewire::actingAs($manager)->test(Inbox::class);

        return countQueries(fn () => $page->set('search', $q));
    });

    expect($measure('dinh tu'))->toBe($measure('dinh khoa'));
});

it('writes again only the leads whose stored words differ, site by site, and corrects nothing on a second run', function (): void {
    $right = termsLead(['name' => 'Bùi Khang']);
    $missing = termsLead(['name' => 'Tạ Minh', 'phone' => '0911 222']);
    $stale = termsLead(['name' => 'Kiều Oanh']);
    $other = termsLead(['name' => 'Võ Thanh'], 2);
    $admin = DB::connection('pgsql_admin');
    $admin->table('lead_search_terms')->whereIn('lead_id', [$missing->id, $other->id])->delete();
    $admin->table('lead_search_terms')->insert(['site_id' => 1, 'lead_id' => $stale->id, 'term' => 'cu']);
    // a lead with no time is walked too (after the timed ones)
    $admin->table('form_submissions')->where('id', $missing->id)->update(['created_at' => null]);

    $this->artisan('forms:search-terms', ['--site' => 1, '--chunk' => 2])
        ->expectsOutputToContain('Checked 3 lead(s) on 1 site(s); corrected 2.')->assertSuccessful();

    expect(termsOf($right))->toBe(['bui', 'khang'])
        ->and(termsOf($missing))->toBe(['0911222', '11222', '1222', '222', '911222', 'minh', 'ta'])
        ->and(termsOf($stale))->toBe(['kieu', 'oanh'])
        ->and(termsOf($other))->toBe([]); // --site kept to site 1

    $this->artisan('forms:search-terms')->expectsOutputToContain('corrected 1.')->assertSuccessful();
    $this->artisan('forms:search-terms')->expectsOutputToContain('corrected 0.')->assertSuccessful();
    expect(termsOf($other))->toBe(['thanh', 'vo']);
});

it('walks leads sent in the same second one by one, none skipped and none twice', function (): void {
    $leads = [termsLead(['name' => 'Lê An']), termsLead(['name' => 'Lê Bình']), termsLead(['name' => 'Lê Chi'])];
    $admin = DB::connection('pgsql_admin');
    $admin->table('form_submissions')->whereIn('id', array_map(fn (FormSubmission $l): int => (int) $l->id, $leads))
        ->update(['created_at' => '2026-11-20 08:00:00+00']);
    $admin->table('lead_search_terms')->delete();

    $this->artisan('forms:search-terms', ['--site' => 1, '--chunk' => 1])
        ->expectsOutputToContain('Checked 3 lead(s) on 1 site(s); corrected 3.')->assertSuccessful();

    expect(array_map(termsOf(...), $leads))->toBe([['an', 'le'], ['binh', 'le'], ['chi', 'le']]);
});
