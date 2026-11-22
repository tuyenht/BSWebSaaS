<?php

use App\Modules\Admin\Http\Controllers\LeadExportController;
use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Admin\Support\Leads\LeadCsvExport;
use App\Modules\Admin\Support\Leads\LeadExportTicket;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * «Tải danh sách về máy» in «Khách liên hệ»: a member who manages leads types their password (or
 * their authenticator code) again, gets a one-time pass that lives 60 seconds and is bound to them,
 * the website and the filter shown, and the browser downloads the CSV of that filter. The file is
 * streamed chunk by chunk, each chunk in its own tenant transaction; one audit row (no personal
 * data) is written before the first byte, one more when the file is done. Another website's leads
 * never appear, support staff signed in for a member never take the file, and a member who only
 * reads leads never gets one.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A lead of $site's contact form. @param array<string, mixed> $attrs */
function exportLead(int $site, string $name, array $attrs = []): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($name, $attrs): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge([
            'form_id' => $form->id, 'name' => $name, 'status' => 'new',
            'data' => ['name' => $name, 'message' => 'Xin báo giá trục thép'],
        ], $attrs));
    });
}

/** A member of $site (or of several) holding exactly $permissions. @param list<string> $permissions */
function exportMember(array $permissions, array $sites = [1]): User
{
    $user = User::factory()->create();
    foreach ($sites as $site) {
        app(Tenancy::class)->enter($site, fn () => grantRole($user, 'export-'.md5(implode(',', $permissions)), $permissions));
        app(TeamContext::class)->forget();
    }

    return $user->fresh() ?? $user;
}

function exportManager(array $sites = [1]): User
{
    return exportMember(['forms.view', 'forms.manage'], $sites);
}

function exportTwoFactor(User $user): string
{
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    return $secret;
}

/** Confirm the export in the inbox as $user (optionally with the screen's filter set first); returns the download path. @param array<string, mixed> $set */
function exportPath(User $user, string $secret = 'password', array $set = []): string
{
    return app(Tenancy::class)->enter(1, function () use ($user, $secret, $set): string {
        $page = Livewire::actingAs($user)->test(Inbox::class);
        foreach ($set as $key => $value) {
            $page->set($key, $value);
        }
        $url = '';
        $page->call('openExport')->assertSet('exportOpen', true)
            ->call('confirmExport', $secret)
            ->assertSet('exportError', '')
            ->assertSet('exportOpen', false)
            ->assertDispatched('leads-export-ready', function (string $event, array $params) use (&$url): bool {
                $url = (string) ($params['url'] ?? '');

                return true;
            });
        expect($url)->toMatch('#^/admin/forms/export/[A-Za-z0-9]{40}$#');

        return $url;
    });
}

/** @return list<list<string>> the CSV's lines, BOM checked and dropped */
function exportRows(string $csv): array
{
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, substr($csv, 3));
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = array_map(strval(...), $row);
    }

    return $rows;
}

function exportAudits(string $action): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', $action)->orderBy('id')->get()->all();
}

it('downloads the CSV of this website\'s leads once, after the password, with the audit row written before the first byte', function (): void {
    $manager = exportManager();
    exportLead(1, 'Nguyễn Thị Hoa', ['phone' => '0912 345 678', 'email' => 'hoa@example.test', 'source_url' => 'https://a.test/lien-he']);
    exportLead(1, 'Trần Văn Bình');
    exportLead(1, 'Thư rác vay nhanh', ['status' => 'spam', 'is_spam' => true]);
    $foreign = exportLead(2, 'Khách site B', ['phone' => '0911 000 111']);

    $path = exportPath($manager);

    $response = $this->actingAs($manager)->get('http://a.test'.$path);
    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="lien-he-\d{2}-\d{2}-\d{4}\.csv"$/')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    // the request is done, the file not yet written: the audit row is already there
    expect(exportAudits('forms.leads.exported'))->toHaveCount(1)
        ->and(exportAudits('forms.leads.export_finished'))->toHaveCount(0);

    $csv = $response->streamedContent();
    $rows = exportRows($csv);
    expect($rows[0])->toBe([__('leads.csv_ref'), __('leads.csv_name'), __('leads.csv_email'), __('leads.csv_phone'), __('leads.csv_form'),
        __('leads.csv_page'), __('leads.csv_sent'), __('leads.csv_status'), __('leads.csv_answers')])
        ->and(array_column(array_slice($rows, 1), 1))->toEqualCanonicalizing(['Nguyễn Thị Hoa', 'Trần Văn Bình']);
    $hoa = collect($rows)->firstWhere(1, 'Nguyễn Thị Hoa');
    expect($hoa[2])->toBe('hoa@example.test')
        ->and($hoa[3])->toBe('0912 345 678')
        ->and($hoa[4])->toBe('Liên hệ')
        ->and($hoa[5])->toBe('https://a.test/lien-he')
        ->and($hoa[6])->toMatch('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$#')
        ->and($hoa[7])->toBe(__('leads.st_new'))
        ->and($hoa[8])->toContain('Xin báo giá trục thép')
        ->and($hoa[0])->toMatch('/^[0-9A-Z]{26}$/');
    // never another website's lead, never a database id
    expect($csv)->not->toContain('Khách site B', '0911 000 111', $foreign->ulid, 'Thư rác');

    $started = exportAudits('forms.leads.exported')[0];
    expect((int) $started->site_id)->toBe(1)
        ->and((int) $started->actor_id)->toBe($manager->id)
        ->and(json_decode((string) $started->meta, true))->toEqual(['tab' => 'all', 'forms' => [], 'when' => '', 'from' => '', 'to' => '', 'search' => false]);
    $done = exportAudits('forms.leads.export_finished');
    expect($done)->toHaveCount(1)
        ->and(json_decode((string) $done[0]->meta, true))->toBe(['rows' => 2, 'complete' => true])
        ->and(json_encode([$started, $done[0]], JSON_UNESCAPED_UNICODE))->not->toContain('Nguyễn', '0912', 'hoa@example.test');

    // the pass opens one download only
    $this->actingAs($manager)->get('http://a.test'.$path)->assertRedirect('/admin/forms')->assertSessionHas('leads.notice', __('leads.export_expired'));
    expect(exportAudits('forms.leads.exported'))->toHaveCount(1);
});

it('says on the screen that the link was used when the download comes back to it', function (): void {
    $manager = exportManager();
    exportLead(1, 'Lê Minh');
    $path = exportPath($manager);
    $this->actingAs($manager)->get('http://a.test'.$path)->assertOk()->streamedContent();

    // the second use lands on the list, which says why no file came
    $this->actingAs($manager)->followingRedirects()->get('http://a.test'.$path)
        ->assertOk()->assertSee('Liên kết tải đã hết hạn hoặc đã được dùng.')->assertSee('data-tone="error"', false);
});

it('exports what the list shows: its group, its search and its dates', function (): void {
    $manager = exportManager();
    exportLead(1, 'Đặng Quốc Việt', ['created_at' => now()->subDays(3)]);
    exportLead(1, 'Đặng Thu', ['status' => 'read', 'created_at' => now()->subDays(3)]);
    exportLead(1, 'Đặng Cũ', ['created_at' => now()->subDays(40)]);
    exportLead(1, 'Phạm Lan', ['created_at' => now()->subDays(3)]);

    $path = exportPath($manager, 'password', [
        'tab' => 'new', 'search' => 'dang', 'from' => now()->subDays(10)->format('Y-m-d'), 'to' => now()->format('Y-m-d'),
    ]);
    $rows = exportRows($this->actingAs($manager)->get('http://a.test'.$path)->assertOk()->streamedContent());

    expect(array_column(array_slice($rows, 1), 1))->toBe(['Đặng Quốc Việt']);
    $meta = json_decode((string) exportAudits('forms.leads.exported')[0]->meta, true);
    // the search is noted as used, never what was typed (it may be a name or a phone)
    expect($meta)->toMatchArray(['tab' => 'new', 'when' => 'range', 'search' => true])
        ->and(json_encode($meta))->not->toContain('dang');
});

it('writes leads without a time first, and keeps one window for a preset from first chunk to last', function (): void {
    $manager = exportManager();
    exportLead(1, 'Tuần này', ['created_at' => now()->subDays(2)]);
    exportLead(1, 'Tháng trước', ['created_at' => now()->subDays(20)]);
    $undated = exportLead(1, 'Không rõ lúc gửi');
    DB::connection('pgsql_admin')->table('form_submissions')->where('ulid', $undated->ulid)->update(['created_at' => null]);

    $all = exportRows($this->actingAs($manager)->get('http://a.test'.exportPath($manager))->assertOk()->streamedContent());
    expect(array_column(array_slice($all, 1), 1))->toBe(['Không rõ lúc gửi', 'Tuần này', 'Tháng trước'])
        ->and($all[1][6])->toBe('');

    $week = exportRows($this->actingAs($manager)->get('http://a.test'.exportPath($manager, 'password', ['when' => '7']))->assertOk()->streamedContent());
    expect(array_column(array_slice($week, 1), 1))->toBe(['Tuần này']);
});

it('gathers a search once and then reads its leads by id, whatever the number of chunks', function (): void {
    $manager = exportManager();
    exportLead(1, 'Đinh Bảo Ngọc');
    exportLead(1, 'Đinh Gia Huy');
    exportLead(1, 'Lý Thanh');

    $response = $this->actingAs($manager)->get('http://a.test'.exportPath($manager, 'password', ['search' => 'dinh']))->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = exportRows($response->streamedContent());
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect(array_column(array_slice($rows, 1), 1))->toEqualCanonicalizing(['Đinh Bảo Ngọc', 'Đinh Gia Huy']);
    $gathers = array_filter($log, fn (array $q): bool => str_contains($q['query'], 'any (array(select t.lead_id'));
    expect($gathers)->toHaveCount(1);
});

it('escapes every cell a spreadsheet would run as a formula', function (): void {
    $manager = exportManager();
    exportLead(1, '=HYPERLINK("http://evil.test","Bấm")', [
        'phone' => '+84 912 345 678', 'email' => '@SUM(1+1)',
        'data' => ['name' => 'x', 'message' => '-2+3', 'company' => "\tcmd", 'note' => "\rx"],
    ]);

    $rows = exportRows($this->actingAs($manager)->get('http://a.test'.exportPath($manager))->assertOk()->streamedContent());
    $line = $rows[1];

    expect($line[1])->toBe('\'=HYPERLINK("http://evil.test","Bấm")')
        ->and($line[2])->toBe("'@SUM(1+1)")
        ->and($line[3])->toBe("'+84 912 345 678")
        ->and(LeadCsvExport::cell('-2+3'))->toBe("'-2+3")
        ->and(LeadCsvExport::cell("\tcmd"))->toBe("'\tcmd")
        ->and(LeadCsvExport::cell("\rx"))->toBe("'\rx")
        ->and(LeadCsvExport::cell('Bình thường = 1'))->toBe('Bình thường = 1')
        ->and(LeadCsvExport::cell(''))->toBe('');
});

it('escapes a form name that starts like a formula, in the real file', function (): void {
    $manager = exportManager();
    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create(['key' => 'evil', 'name' => ['vi' => '@cmd|calc', 'en' => '@cmd|calc'], 'status' => 'active']);
        FormSubmission::query()->create(['form_id' => $form->id, 'name' => 'Vũ Hà', 'status' => 'new', 'data' => ['name' => 'Vũ Hà']]);
    });

    $rows = exportRows($this->actingAs($manager)->get('http://a.test'.exportPath($manager))->assertOk()->streamedContent());

    expect($rows[1][4])->toBe("'@cmd|calc");
});

it('never keeps the password in the page or sends it back', function (): void {
    $manager = exportManager();
    $secret = 'password';

    app(Tenancy::class)->enter(1, function () use ($manager, $secret): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)->call('openExport')->call('confirmExport', $secret)->assertDispatched('leads-export-ready');
        $snapshot = (fn () => $this->lastState->getSnapshot())->call($page);
        expect(json_encode($snapshot))->not->toContain('"'.$secret.'"')
            ->and($page->html())->not->toContain('value="'.$secret.'"');
    });
});

it('sends no file when its audit row cannot be written', function (): void {
    $manager = exportManager();
    exportLead(1, 'Mai Anh');
    $path = exportPath($manager);
    // the audit store refuses the row (the service is final: swap the facade's object)
    Audit::swap(new class
    {
        public function recordOrFail(): never
        {
            throw new RuntimeException('audit down');
        }

        /** @param  array<int, mixed>  $args */
        public function __call(string $method, array $args): mixed
        {
            return null;
        }
    });

    $response = $this->actingAs($manager)->get('http://a.test'.$path);

    expect($response->status())->toBe(500)
        ->and($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class);
});

it('streams a large list by keyset chunks of 1,000, each read in its own tenant transaction, never by OFFSET', function (): void {
    $manager = exportManager();
    $form = app(Tenancy::class)->enter(1, fn (): Form => Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']));
    $base = now()->subDays(5);
    $rows = [];
    foreach (range(1, 2500) as $i) {
        // pairs share a second, so the tie-break on the code is what keeps every lead once
        $rows[] = ['site_id' => 1, 'ulid' => (string) Str::ulid(), 'form_id' => $form->id, 'name' => 'Khách '.$i, 'status' => 'new', 'data' => '{}',
            'created_at' => $base->copy()->addSeconds(intdiv($i, 2)), 'updated_at' => $base];
    }
    foreach (array_chunk($rows, 500) as $part) {
        DB::connection('pgsql_admin')->table('form_submissions')->insert($part);
    }
    exportLead(2, 'Khách site B');

    $response = $this->actingAs($manager)->get('http://a.test'.exportPath($manager))->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $csv = $response->streamedContent();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $names = array_column(array_slice(exportRows($csv), 1), 1);
    expect($names)->toHaveCount(2500)
        ->and(array_unique($names))->toHaveCount(2500)
        ->and($names)->not->toContain('Khách site B');
    $reads = array_values(array_filter($log, fn (array $q): bool => str_contains($q['query'], 'from "form_submissions"')));
    // the undated pass (none), then 1,000 + 1,000 + 500
    expect($reads)->toHaveCount(4);
    foreach ($reads as $read) {
        expect(strtolower($read['query']))->not->toContain('offset')->toContain('limit 1000');
    }
    expect(collect($reads)->filter(fn (array $q): bool => str_contains($q['query'], '(created_at, ulid) < ('))->count())->toBe(2);
    // every read entered the site first: SET LOCAL and the assertion, once per chunk (plus the forms read)
    $entered = array_filter($log, fn (array $q): bool => str_contains($q['query'], 'set_config'));
    expect(count($entered))->toBeGreaterThanOrEqual(5);
});

it('refuses a pass that has expired, another member\'s pass, and a pass on another website', function (): void {
    $manager = exportManager([1, 2]);
    $colleague = exportManager();
    exportLead(1, 'Hồ Gia');

    $late = exportPath($manager);
    $this->travel(61)->seconds();
    $this->actingAs($manager)->get('http://a.test'.$late)->assertRedirect('/admin/forms');

    $this->travelBack();
    $theirs = exportPath($manager);
    $this->actingAs($colleague)->get('http://a.test'.$theirs)->assertRedirect('/admin/forms');
    // a pass is spent by its first use, whoever brought it
    $this->actingAs($manager)->get('http://a.test'.$theirs)->assertRedirect('/admin/forms');

    $elsewhere = exportPath($manager);
    $this->actingAs($manager)->get('http://b.test'.$elsewhere)->assertRedirect('/admin/forms');

    expect(exportAudits('forms.leads.exported'))->toHaveCount(0);
});

it('refuses a wrong password, and stops checking after five wrong tries', function (): void {
    $manager = exportManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)->call('openExport');
        $page->call('confirmExport', '')->assertSet('exportError', __('leads.export_pw_required'))->assertNotDispatched('leads-export-ready');
        foreach (range(1, 5) as $i) {
            $page->call('confirmExport', 'sai-'.$i)->assertSet('exportError', __('leads.export_pw_wrong'))->assertSet('exportOpen', true);
        }
        $page->call('confirmExport', 'password')->assertNotDispatched('leads-export-ready')->assertSet('exportOpen', true);
        expect($page->get('exportError'))->toStartWith(Str::before(__('leads.export_throttled'), ':seconds'));
    });
});

it('takes the authenticator code instead of the password, once', function (): void {
    $manager = exportManager();
    $secret = exportTwoFactor($manager);
    $manager = $manager->fresh();

    app(Tenancy::class)->enter(1, function () use ($manager, $secret): void {
        $code = (new Google2FA)->getCurrentOtp($secret);
        $page = Livewire::actingAs($manager)->test(Inbox::class)->assertSee(__('leads.export_use_code'))->call('openExport')->call('useExportCode', true)->assertSet('exportByCode', true);
        $page->call('confirmExport', '12')->assertSet('exportError', __('leads.export_code_format'));
        $page->call('confirmExport', substr($code, 0, 3).' '.substr($code, 3))->assertSet('exportError', '')->assertDispatched('leads-export-ready');

        // the same code again, while it still shows in the app: refused
        Livewire::actingAs($manager)->test(Inbox::class)->call('openExport')->call('useExportCode', true)
            ->call('confirmExport', $code)->assertSet('exportError', __('leads.export_code_wrong'))->assertNotDispatched('leads-export-ready');
    });
});

it('offers the code only to a member who has the app set up', function (): void {
    $manager = exportManager();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(Inbox::class)
        ->call('openExport')->assertDontSee(__('leads.export_use_code'))
        ->call('useExportCode', true)->assertSet('exportByCode', false));
});

it('gives each member at most ten files an hour', function (): void {
    $manager = exportManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        foreach (range(1, Inbox::EXPORTS_PER_HOUR) as $i) {
            Livewire::actingAs($manager)->test(Inbox::class)->call('openExport')->call('confirmExport', 'password')->assertDispatched('leads-export-ready');
        }
        Livewire::actingAs($manager)->test(Inbox::class)->call('openExport')->call('confirmExport', 'password')
            ->assertNotDispatched('leads-export-ready')
            ->assertSet('exportError', __('leads.export_limit', ['max' => Inbox::EXPORTS_PER_HOUR, 'minutes' => 60]));
    });
});

it('never lets support staff signed in for a member take the file', function (): void {
    $manager = exportManager();
    exportLead(1, 'Kiều Oanh');
    // a pass issued before the support session began (or replayed) opens nothing in it either
    $path = exportPath($manager);
    $token = Str::afterLast($path, '/');

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($manager);
    app(SupportSession::class)->start($manager, $manager, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $token): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->assertSee(__('leads.export'))
            ->assertSee(__('admin.support.locked'))
            ->assertDontSeeHtml('wire:click="openExport"')
            ->call('openExport')->assertForbidden();
        Livewire::actingAs($manager)->test(Inbox::class)->set('exportOpen', true)->call('confirmExport', 'password')->assertForbidden();

        expect(fn () => app()->call(LeadExportController::class, ['token' => $token]))->toThrow(AuthorizationException::class, __('admin.support.locked'));
    });

    expect(exportAudits('forms.leads.exported'))->toHaveCount(0);
});

it('refuses a member who only reads leads at every step', function (array $permissions): void {
    $member = exportMember($permissions);
    exportLead(1, 'Phạm Lan');

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member)->test(Inbox::class)->assertDontSee(__('leads.export'))->assertDontSee(__('leads.more'));
        foreach ([['openExport', []], ['useExportCode', [true]], ['cancelExport', []], ['confirmExport', ['password']]] as [$method, $args]) {
            Livewire::actingAs($member)->test(Inbox::class)->call($method, ...$args)->assertForbidden();
        }
    });

    // a pass in their name (they can never get one) still opens nothing
    $token = LeadExportTicket::issue($member, 1, ['tab' => 'all', 'search' => '', 'forms' => [], 'when' => '', 'from' => '', 'to' => '']);
    $this->actingAs($member)->get('http://a.test/admin/forms/export/'.$token)->assertForbidden();
    expect(exportAudits('forms.leads.exported'))->toHaveCount(0);
})->with([
    'reads leads only' => [['forms.view']],
    'no lead permission' => [['entries.view']],
]);

it('refuses the download when the plan has no «Khách liên hệ»', function (): void {
    $manager = exportManager();
    $path = exportPath($manager);
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['site_type_id' => null]);
    app()->forgetInstance(Entitlement::class);

    $this->actingAs($manager)->get('http://a.test'.$path)->assertForbidden();
    expect(exportAudits('forms.leads.exported'))->toHaveCount(0);
});

it('names the file after today in the website\'s own calendar', function (): void {
    $name = app(Tenancy::class)->enter(1, fn (): string => LeadCsvExport::fileName(app(SiteTime::class)));
    $today = app(Tenancy::class)->enter(1, fn (): string => app(SiteTime::class)->now()->format('d-m-Y'));

    expect($name)->toBe(__('leads.export_file', ['date' => $today]).'.csv')
        ->and(app(CurrentSite::class)->hasId())->toBeFalse();
});
