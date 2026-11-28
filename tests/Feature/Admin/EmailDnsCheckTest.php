<?php

use App\Modules\Admin\Jobs\CheckEmailDns;
use App\Modules\Admin\Livewire\EmailDnsStatus;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\Mail\MailDnsCheck;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DnsLookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Fixtures\FakeDns;

/*
 * «Nâng cao — các dòng xác thực tên miền» of the Email screen: «Kiểm tra & lưu kết nối» starts a
 * queued SPF / DKIM / DMARC check of the website's sending domain (a press limit per website); the
 * result is kept in the website's own `email` settings under `dns`; while a record is missing it
 * re-checks itself every five minutes for at most a day. Public DNS is faked: no lookup leaves.
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    foreach ([1, 2] as $site) {
        RateLimiter::clear('mail-dns-check:'.$site);
        RateLimiter::clear('mail-test-send:'.$site.':1');
    }
    $this->dns = FakeDns::install();
    // a queue that holds a delayed job back (the tests' sync one runs it at once); each test fakes it
    config(['queue.default' => 'redis']);
});

afterEach(function (): void {
    Livewire::flushState();
});

function edcAdmin(int $site = 1, array $permissions = ['settings.manage']): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($admin, 'site-admin', $permissions));

    return $admin->fresh();
}

/** A website sending through $relay from $domain (SendGrid unless said otherwise). */
function edcStore(int $site, string $domain, string $relay = 'sendgrid', array $extra = []): void
{
    $cfg = ['provider' => 'other', 'method' => 'smtp_password', 'relay' => $relay, 'send_domain' => $domain,
        'smtp' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => SecretCipher::encrypt('SG.cu')], ...$extra];
    app(Tenancy::class)->enter($site, fn () => app(SettingsRepository::class)->put('email', 'config', $cfg));
}

/** @return array<string, mixed>|null */
function edcDns(int $site = 1): ?array
{
    return app(Tenancy::class)->enter($site, fn () => app(SettingsRepository::class)->get('email', 'dns'));
}

/** Starts a check for $site as «Kiểm tra & lưu kết nối» does; returns its token. */
function edcStart(int $site = 1): string
{
    app(Tenancy::class)->enter($site, fn () => expect(app(MailDnsCheck::class)->start($site))->toBeNull());

    return (string) edcDns($site)['token'];
}

function edcRun(int $site, string $token): void
{
    (new CheckEmailDns($site, $token))->handle(app(MailDnsCheck::class));
}

/** All three records of SendGrid on congty.vn, or all but $skip. */
function edcPublish(FakeDns $dns, string $domain = 'congty.vn', ?string $skip = null): void
{
    $records = [
        'spf' => [$domain, 'v=spf1 include:sendgrid.net ~all'],
        'dkim' => ['s1._domainkey.'.$domain, 'v=DKIM1; k=rsa; p=MIIBIjANBg'],
        'dmarc' => ['_dmarc.'.$domain, 'v=DMARC1; p=none'],
    ];
    foreach ($records as $name => [$host, $value]) {
        if ($name !== $skip) {
            $dns->txt[$host] = [$value];
        }
    }
}

/** The DNS box as $admin sees it; $then runs in the same tenant context (later requests need it too). */
function edcBox(User $admin, string $domain = 'congty.vn', string $provider = 'sendgrid', int $site = 1, ?Closure $then = null)
{
    return app(Tenancy::class)->enter($site, function () use ($admin, $domain, $provider, $then) {
        $box = Livewire::actingAs($admin)->test(EmailDnsStatus::class, ['domain' => $domain, 'provider' => $provider]);

        return $then === null ? $box : $then($box);
    });
}

it('starts a queued check from «Kiểm tra & lưu kết nối» and keeps it in the website\'s email settings', function (): void {
    Mail::fake();
    Queue::fake();
    $admin = edcAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->set('smtpPassword', 'SG.moi')->set('sendDomain', 'CongTy.vn')
            ->call('checkRelay')
            ->assertHasNoErrors()
            ->assertDispatched('em-dns-started')
            ->assertSeeHtml('wire:name="admin.email-dns-status"');
    });

    $dns = edcDns();
    expect($dns)->toMatchArray(['domain' => 'congty.vn', 'provider' => 'sendgrid', 'running' => true, 'next_at' => null, 'rows' => []])
        ->and(CarbonImmutable::parse($dns['started_at'])->addDay()->equalTo(CarbonImmutable::parse($dns['until'])))->toBeTrue();
    Queue::assertPushed(CheckEmailDns::class, 1);
    Queue::assertPushed(CheckEmailDns::class, fn (CheckEmailDns $job): bool => $job->siteId === 1 && $job->token === $dns['token'] && $job->delay === null);
    // the outbox setup itself is not touched by the check
    expect(app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->get('email')))->not->toHaveKey('dns');
});

it('shows all three records verified once they are published, and stops there', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcPublish($this->dns);
    $admin = edcAdmin();

    $token = edcStart();
    edcBox($admin)->assertSee('Đang kiểm tra…')->assertSeeHtml('wire:poll.3s');
    edcRun(1, $token);

    expect(edcDns()['rows'])->toBe([
        'spf' => ['code' => 'ok', 'host' => 'congty.vn'],
        'dkim' => ['code' => 'ok', 'host' => 's1._domainkey.congty.vn'],
        'dmarc' => ['code' => 'ok', 'host' => '_dmarc.congty.vn'],
    ])->and(edcDns()['next_at'])->toBeNull();
    Queue::assertNotPushed(CheckEmailDns::class, fn (CheckEmailDns $job): bool => $job->delay !== null);

    $box = edcBox($admin)->assertSee('Kiểm tra lúc')->assertDontSee('Đang chờ tên miền nhận')->assertDontSeeHtml('wire:poll');
    expect(substr_count($box->html(), 'Đã xác thực'))->toBe(3)
        ->and(substr_count($box->html(), 'data-dns-tone="ok"'))->toBe(3);
});

it('says how to add a missing SPF, DKIM or DMARC record while it waits for the domain', function (string $skip, string $fix): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcPublish($this->dns, skip: $skip);
    if ($skip === 'spf') {
        // SendGrid proves SPF itself; only a service that asks for the record can miss it
        edcStore(1, 'congty.vn', 'mailgun', ['relay_region' => 'us']);
        $this->dns->txt['congty.vn'] = [];
    }
    $admin = edcAdmin();
    $provider = $skip === 'spf' ? 'mailgun' : 'sendgrid';

    edcRun(1, edcStart());

    edcBox($admin, provider: $provider)
        ->assertSee('Tự kiểm tra lại mỗi 5 phút')
        ->assertSee('Đang chờ tên miền nhận')
        ->assertSee($fix)
        ->assertSeeHtml('data-dns-row="'.$skip.'" data-dns-tone="pending"')
        ->assertSeeHtml('wire:poll.60s');
})->with([
    'SPF' => ['spf', 'Thêm một dòng TXT tại congty.vn với nội dung: v=spf1 include:mailgun.org ~all'],
    'DKIM' => ['dkim', 'Chưa thấy khoá ký tại s1._domainkey.congty.vn. Thêm các dòng DKIM mà SendGrid đưa cho bạn'],
    'DMARC' => ['dmarc', 'Thêm một dòng TXT tại _dmarc.congty.vn với nội dung: v=DMARC1; p=none'],
]);

it('says an SPF record that does not name the service must include it', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn', 'mailgun', ['relay_region' => 'us']);
    $this->dns->txt['congty.vn'] = ['v=spf1 include:_spf.google.com ~all'];
    $admin = edcAdmin();

    edcRun(1, edcStart());

    expect(edcDns()['rows']['spf'])->toBe(['code' => 'not_allowed', 'host' => 'congty.vn']);
    edcBox($admin, provider: 'mailgun')
        ->assertSee('Dòng SPF ở congty.vn chưa cho phép Mailgun gửi. Thêm include:mailgun.org vào dòng đó');
});

it('says DKIM could not be checked, and why, when the service names its key per domain', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn', 'mailgun', ['relay_region' => 'us']);
    $this->dns->txt['congty.vn'] = ['v=spf1 include:mailgun.org ~all'];
    $this->dns->txt['_dmarc.congty.vn'] = ['v=DMARC1; p=none'];
    $admin = edcAdmin();

    edcRun(1, edcStart());

    expect(edcDns()['rows']['dkim'])->toEqual(['code' => 'unchecked', 'why' => 'unknown_selector'])
        // nothing is missing: no re-check is queued
        ->and(edcDns()['next_at'])->toBeNull();
    edcBox($admin, provider: 'mailgun')
        ->assertSeeHtml('data-dns-row="dkim" data-dns-tone="unchecked"')
        ->assertSee('Chưa kiểm tra được')
        ->assertSee('Mailgun đặt tên khoá ký riêng cho từng tên miền, nên hệ thống không biết chỗ để tra.');
});

it('re-checks every five minutes while a record is missing, and stops at the 24-hour deadline', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcPublish($this->dns, skip: 'dmarc');
    $start = CarbonImmutable::parse('2026-11-28 08:00:00', 'UTC');
    $this->travelTo($start);
    $token = edcStart();

    $runs = 0;
    $next = app(MailDnsCheck::class)->run(1, $token);
    while ($next !== null) {
        $runs++;
        expect($next->equalTo(CarbonImmutable::now('UTC')->addMinutes(5)))->toBeTrue()
            ->and($next->lessThanOrEqualTo($start->addDay()))->toBeTrue();
        $this->travelTo($next);
        $next = app(MailDnsCheck::class)->run(1, $token);
        expect($runs)->toBeLessThanOrEqual(24 * 12);
    }

    // 24 hours of five-minute re-checks after the first run, then none
    expect($runs)->toBe(24 * 12)
        ->and(CarbonImmutable::now('UTC')->equalTo($start->addDay()))->toBeTrue()
        ->and(edcDns()['next_at'])->toBeNull();
    Queue::assertPushed(CheckEmailDns::class, fn (CheckEmailDns $job): bool => $job->delay !== null && $job->token === $token);

    // a late job after the deadline does nothing
    $this->travelTo($start->addDay()->addMinutes(5));
    expect(app(MailDnsCheck::class)->run(1, $token))->toBeNull();

    edcBox(edcAdmin())
        ->assertSee('Đã ngừng tự kiểm tra sau 24 giờ')
        ->assertSee('Cần sửa')
        ->assertSeeHtml('data-dns-row="dmarc" data-dns-tone="failed"')
        ->assertDontSeeHtml('wire:poll');
});

it('stops re-checking once the record appears', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcPublish($this->dns, skip: 'dmarc');
    $token = edcStart();

    expect(app(MailDnsCheck::class)->run(1, $token))->not->toBeNull();
    edcPublish($this->dns);
    expect(app(MailDnsCheck::class)->run(1, $token))->toBeNull()
        ->and(edcDns()['rows']['dmarc']['code'])->toBe('ok');
});

it('ends an older chain when the check is pressed again, or when the setup changed', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcPublish($this->dns, skip: 'dmarc');
    $old = edcStart();
    $new = edcStart();

    $lookups = $this->dns->lookups;
    expect(app(MailDnsCheck::class)->run(1, $old))->toBeNull()
        ->and($this->dns->lookups)->toBe($lookups)
        ->and(edcDns()['token'])->toBe($new)
        ->and(edcDns()['rows'])->toBe([]);

    // saved with another domain: the chain of the old one stops, and its rows are not shown for the new one
    expect(app(MailDnsCheck::class)->run(1, $new))->not->toBeNull();
    edcStore(1, 'khac.vn');
    expect(app(MailDnsCheck::class)->run(1, $new))->toBeNull();
    edcBox(edcAdmin(), 'khac.vn')->assertSee('Chạy sau khi bạn bấm Kiểm tra &amp; lưu', false)->assertDontSee('Đang chờ tên miền nhận');
});

it('refuses a fourth press within ten minutes, in plain words, and starts nothing', function (): void {
    Mail::fake();
    Queue::fake();
    edcStore(1, 'congty.vn');
    $admin = edcAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class);
        foreach (range(1, MailDnsCheck::PRESSES) as $press) {
            $page->call('checkRelay')->assertDispatched('em-dns-started');
        }
    });
    $token = edcDns()['token'];

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('checkRelay')
            ->assertDispatched('em-dns-refused')
            ->assertNotDispatched('em-dns-started');
    });
    expect(edcDns()['token'])->toBe($token);
    Queue::assertPushed(CheckEmailDns::class, MailDnsCheck::PRESSES);

    edcBox($admin, then: fn ($box) => $box->assertDontSeeHtml('data-dns-wait')
        ->dispatch('em-dns-refused')
        ->assertSee('Bạn vừa kiểm tra tên miền nhiều lần; bạn có thể bấm lại sau 10 phút.')
        ->assertDontSee('Hệ thống vẫn tự kiểm tra lại'));

    // it says the check goes on by itself only while it does (a record still missing)
    edcRun(1, $token);
    edcBox($admin, then: fn ($box) => $box->dispatch('em-dns-refused')
        ->assertSee('Bạn vừa kiểm tra tên miền nhiều lần. Hệ thống vẫn tự kiểm tra lại mỗi 5 phút; bạn có thể bấm lại sau 10 phút.'));

    // the limit is the website's: another website may still check
    edcStore(2, 'web2.vn');
    app(Tenancy::class)->enter(2, fn () => expect(app(MailDnsCheck::class)->start(2))->toBeNull());
});

it('refuses the box to a member without the settings permission', function (): void {
    $member = edcAdmin(1, ['entries.view']);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($member)
        ->test(EmailDnsStatus::class, ['domain' => 'congty.vn', 'provider' => 'sendgrid'])
        ->assertForbidden());
});

it('keeps each website\'s result to itself, and runs the check in that website\'s own context', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    edcStore(2, 'web2.vn', 'resend');
    edcPublish($this->dns);
    $tokenTwo = edcStart(2);
    $siteTwoBefore = edcDns(2);

    $seen = new class($this->dns) implements DnsLookup
    {
        /** @var list<string> */
        public array $hosts = [];

        /** @var list<int|null> */
        public array $sites = [];

        public function __construct(private readonly FakeDns $dns) {}

        public function addresses(string $host): array
        {
            return $this->dns->addresses($host);
        }

        public function txt(string $host): array
        {
            $this->hosts[] = $host;
            // no tenant context is held open around a lookup
            $this->sites[] = app(CurrentSite::class)->hasId() ? app(CurrentSite::class)->id() : null;

            return $this->dns->txt($host);
        }
    };
    app()->instance(DnsLookup::class, $seen);

    $tokenOne = edcStart(1);
    edcRun(1, $tokenOne);

    expect($seen->hosts)->not->toBeEmpty()
        ->and(array_filter($seen->hosts, fn (string $h): bool => ! str_ends_with($h, 'congty.vn')))->toBe([])
        ->and(array_unique($seen->sites))->toBe([null])
        ->and(edcDns(1)['rows']['spf']['code'])->toBe('ok')
        // website 2 keeps its own, untouched
        ->and(edcDns(2))->toBe($siteTwoBefore)
        ->and(DB::connection('pgsql_admin')->table('site_settings')->where('group_name', 'email')->where('key', 'dns')->orderBy('site_id')->pluck('site_id')->all())->toBe([1, 2]);

    // a job of website 2 checks website 2's domain, through its own service
    $seen->hosts = [];
    edcRun(2, $tokenTwo);
    expect($seen->hosts)->toContain('send.web2.vn', 'resend._domainkey.web2.vn', '_dmarc.web2.vn')
        ->and(array_filter($seen->hosts, fn (string $h): bool => ! str_ends_with($h, 'web2.vn')))->toBe([])
        ->and(edcDns(2)['rows']['spf'])->toBe(['code' => 'missing', 'host' => 'send.web2.vn'])
        ->and(edcDns(1)['token'])->toBe($tokenOne);

    // website 1's box never shows website 2's result
    edcBox(edcAdmin(), 'web2.vn', 'resend')->assertSee('Chạy sau khi bạn bấm Kiểm tra &amp; lưu', false);
});

it('shows no box and starts no check for the shared outbox or a signed-in mailbox', function (string $provider): void {
    Queue::fake();
    $admin = edcAdmin();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', ['provider' => $provider, 'method' => $provider === 'platform' ? 'smtp_password' : 'oauth2']));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertDontSeeHtml('wire:name="admin.email-dns-status"')
            ->call('checkRelay')
            ->assertNotDispatched('em-dns-started');
        expect(app(MailDnsCheck::class)->start(1))->toBeNull();
    });
    expect(edcDns())->toBeNull();
    Queue::assertNothingPushed();
})->with(['platform', 'google']);

it('ends the chain when a run fails, and shows the box as not checked yet when no result ever came', function (): void {
    Queue::fake();
    edcStore(1, 'congty.vn');
    $admin = edcAdmin();
    $token = edcStart();

    (new CheckEmailDns(1, $token))->failed(new RuntimeException('resolver down'));
    expect(edcDns())->toMatchArray(['token' => $token, 'running' => false, 'next_at' => null, 'checked_at' => null]);
    Queue::assertPushed(CheckEmailDns::class, 1); // nothing queued after the failure
    edcBox($admin)->assertSee('Chạy sau khi bạn bấm Kiểm tra &amp; lưu', false)->assertDontSeeHtml('wire:poll');

    // a lost first run (never answered) no longer counts as under way after ten minutes
    $lost = edcStart();
    edcBox($admin)->assertSee('Đang kiểm tra…')->assertSeeHtml('wire:poll.3s');
    $this->travel(MailDnsCheck::RUNNING_SECONDS + 1)->seconds();
    edcBox($admin)->assertSee('Chạy sau khi bạn bấm Kiểm tra &amp; lưu', false)
        ->assertDontSee('Kiểm tra lúc')
        ->assertDontSeeHtml('wire:poll');
    expect(edcDns()['token'])->toBe($lost);
});

it('keeps the job well under the queue\'s retry window and queues it after the request commits', function (): void {
    $job = new CheckEmailDns(1, 'T');

    expect($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($job->tries)->toBe(1)
        ->and($job->afterCommit)->toBeTrue();
});

it('queues no re-check on a queue that runs a delayed job at once', function (string $connection): void {
    Queue::fake();
    config(['queue.default' => $connection]);
    edcStore(1, 'congty.vn');

    edcRun(1, edcStart());

    expect(edcDns())->toMatchArray(['running' => false, 'next_at' => null])
        ->and(edcDns()['rows']['dmarc']['code'])->toBe('missing');
    Queue::assertPushed(CheckEmailDns::class, 1);
})->with(['sync', 'deferred', 'background']);
