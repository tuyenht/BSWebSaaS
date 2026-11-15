<?php

use App\Modules\Admin\Errors\ErrorReference;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;

/*
 * The admin's error pages (design board 25 "Trang lỗi quản trị"): every fault on the admin plane
 * shows one plain state in the member's admin language — inside the admin frame for a member, on a
 * page of its own otherwise — with no exception text, stack or raw id; the storefront keeps its own.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['app.debug' => false]);

    // Faults on demand, behind the tenant admin's own middleware (non-fallback routes, so they win
    // over the unknown-address catch-all).
    $stack = ['web', SetCurrentSite::class, 'site.active', 'auth', 'auth.session', 'tenant.member'];
    Route::middleware($stack)->prefix('admin/_fault')->group(function (): void {
        Route::get('/boom', fn () => throw new RuntimeException('secret detail: SQLSTATE users.id=4242 /app/Secret.php'));
        Route::get('/token', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
        Route::get('/busy', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 42]));
        Route::get('/down', fn () => abort(503));
        Route::get('/shop', fn () => 'never')->middleware('module:catalog');
    });
});

function errorMember(string $role = 'editor', array $permissions = ['entries.view']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** The headers a browser sends when the member opens an address (a top-level navigation). */
function navigation(): array
{
    return ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];
}

/** The short reference a 500 page shows. */
function shownReference(string $html): string
{
    $set = ErrorReference::ALPHABET;
    expect(preg_match('/<b aria-label="[^"]+">(['.$set.']{3}-['.$set.']{3})<\/b>/u', $html, $match))->toBe(1);

    return $match[1];
}

function siteStatus(string $status): void
{
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['status' => $status]);
}

/** Nothing of the fault's own text, the code path or a raw id reaches the page. */
function assertNoLeak(string $html): void
{
    foreach (['secret detail', 'SQLSTATE', '4242', 'Secret.php', 'RuntimeException', 'Stack trace', '#0 /'] as $internal) {
        expect($html)->not->toContain($internal);
    }
}

it('shows "no permission" inside the admin frame with the member\'s role and who may', function (): void {
    errorMember('editor', ['entries.view']);
    $member = errorMember('media-only', ['media.view']);

    $html = $this->actingAs($member)->get('http://a.test/admin/content')
        ->assertForbidden()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->getContent();

    expect($html)
        ->toContain('data-te-page="noperm"')
        ->toContain(e(__('errors.noperm.title', ['screen' => __('errors.screen.content')])))
        ->toContain(e(__('errors.noperm.line', ['role' => 'media-only'])))
        ->toContain('te-chip')
        ->toContain('editor')
        ->toContain(__('errors.back_dashboard'))
        ->toContain('href="'.route('admin.dashboard').'"')
        ->toContain('bsSession(')           // the admin frame is drawn around it
        ->not->toContain('te-out');
    assertNoLeak($html);
});

it('shows a stranger "no permission" on a page of its own, naming nobody', function (): void {
    errorMember('editor', ['entries.view']);
    $stranger = User::factory()->create();

    $html = $this->actingAs($stranger)->get('http://a.test/admin')->assertForbidden()->getContent();

    expect($html)
        ->toContain('te-out')
        ->toContain('data-te-page="noperm"')
        ->toContain(e(__('errors.noperm.line_plain')))
        ->not->toContain('te-chip')
        ->not->toContain('bsSession(');
});

it('keeps sending a guest to the sign-in', function (): void {
    $this->get('http://a.test/admin/content')->assertRedirect('http://a.test/admin/login');
});

it('speaks the member\'s admin language', function (): void {
    $member = errorMember('media-only', ['media.view']);

    $html = $this->actingAs($member)->withSession(['locale' => 'en'])->get('http://a.test/admin/content')
        ->assertForbidden()->getContent();

    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    expect($html)->toContain(e(str_replace(':screen', $en['errors.screen.content'], $en['errors.noperm.title'])));
});

it('shows "not found" with the trash and the list for a member who may delete', function (): void {
    $member = errorMember('editor', ['entries.view', 'entries.edit.any', 'entries.delete.any']);

    $html = $this->actingAs($member)->get('http://a.test/admin/content/01JZZZZZZZZZZZZZZZZZZZZZZZ/edit')
        ->assertNotFound()->getContent();

    expect($html)
        ->toContain('data-te-page="notfound"')
        ->toContain(e(__('errors.notfound.title')))
        ->toContain(__('errors.notfound.trash'))
        ->toContain('tab=trash')
        ->toContain(__('errors.notfound.list'))
        ->toContain('bsSession(')
        ->not->toContain('01JZZZZZZZZZZZZZZZZZZZZZZZ');
});

it('sends an unknown admin address to Tổng quan with a notice', function (): void {
    $member = errorMember();

    $this->actingAs($member)->withHeaders(navigation())->get('http://a.test/admin/khong-co-trang-nay')
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('admin.notice', __('errors.unknown_address'));
    $this->flushHeaders();

    $this->actingAs($member)->withSession(['admin.notice' => __('errors.unknown_address')])
        ->get('http://a.test/admin')->assertOk()
        // The notice rides in the guard's @js config (JSON.parse('{…}')): look for its "notice" pair.
        ->assertSee(substr(Js::from(['notice' => __('errors.unknown_address')])->toHtml(), strlen("JSON.parse('{"), -strlen("}')")), false);
});

it('answers an unknown admin address with "not found" for a guest and "no permission" for a stranger', function (): void {
    errorMember();

    $html = $this->withHeaders(navigation())->get('http://a.test/admin/khong-co-trang-nay')->assertNotFound()->getContent();
    expect($html)->toContain('data-te-page="notfound"')->toContain('href="'.route('admin.login').'"');

    $this->actingAs(User::factory()->create())->get('http://a.test/admin/khong-co-trang-nay')->assertForbidden();
});

it('treats a wire:navigate click to an unknown address as a page the member opened', function (): void {
    $member = errorMember();

    $this->actingAs($member)->withHeaders(['X-Livewire-Navigate' => '1', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])
        ->get('http://a.test/admin/khong-co-trang-nay')
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('admin.notice', __('errors.unknown_address'));
});

it('leaves no notice behind for an address the browser asks for on its own', function (array $headers): void {
    $member = errorMember();

    $this->actingAs($member)->withHeaders($headers)->get('http://a.test/admin/apple-touch-icon.png')
        ->assertNotFound()
        ->assertSessionMissing('admin.notice');
})->with([
    'no fetch headers' => [[]],
    'a fetch()' => [['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']],
    'an image' => [['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image']],
    'a frame' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe']],
]);

it('leaves existing routes to their own handlers ahead of the unknown-address pages', function (): void {
    $match = fn (string $url, string $method = 'GET') => app('router')->getRoutes()->match(Request::create($url, $method))->getName();
    $platform = 'http://'.config('tenancy.platform_host');

    expect($match('http://a.test/admin'))->toBe('admin.dashboard')
        ->and($match('http://a.test/admin/content'))->toBe('admin.entries.index')
        ->and($match('http://a.test/admin/nothing/here'))->toBe('admin.unknown')
        ->and($match($platform.'/tenants'))->toBe('platform.tenants.index')
        ->and($match($platform.'/nothing/here'))->toBe('platform.unknown')
        ->and($match('http://a.test/tin-tuc'))->not->toBe('admin.unknown');
});

it('shows a website that is not open as a page of its own, before sign-in too', function (string $status, string $key, string $title): void {
    $member = errorMember();
    siteStatus($status);

    // A guest is sent to the sign-in first, which is closed the same way.
    $guest = $this->get('http://a.test/admin/login')->assertForbidden()->getContent();
    $signedIn = $this->actingAs($member)->get('http://a.test/admin')->assertForbidden()->getContent();

    foreach ([$guest, $signedIn] as $html) {
        expect($html)
            ->toContain('te-out')
            ->toContain('data-te-page="'.$key.'"')
            ->toContain(e(__($title)));
        assertNoLeak($html);
    }
})->with([
    'suspended' => ['suspended', 'suspended', 'errors.suspended.title'],
    'archived' => ['archived', 'archived', 'errors.archived.title'],
    'provisioning' => ['provisioning', 'provisioning', 'errors.provisioning.title'],
]);

it('offers reload and support while a website is being set up', function (): void {
    siteStatus('provisioning');
    config(['support.phone' => '0900 000 111', 'support.zalo' => '0900000111', 'support.mailbox' => 'help@example.test']);

    $html = $this->get('http://a.test/admin/login')->assertForbidden()->getContent();

    expect($html)
        ->toContain('data-te-action="reload"')
        ->toContain('id="ho-tro"')
        ->toContain('href="tel:0900000111"')
        ->toContain('https://zalo.me/0900000111')
        ->toContain('mailto:help@example.test');
});

it('shows a module that is not on for this website', function (): void {
    $member = errorMember();

    $html = $this->actingAs($member)->get('http://a.test/admin/_fault/shop')->assertForbidden()->getContent();

    expect($html)
        ->toContain('data-te-page="lock"')
        ->toContain(e(__('errors.lock.title', ['module' => 'Sản phẩm'])))
        ->toContain('bsSession(');
});

it('shows "session expired" and comes back to the page after signing in', function (): void {
    $member = errorMember();

    $html = $this->actingAs($member)->withHeader('Referer', 'http://a.test/admin/content')
        ->get('http://a.test/admin/_fault/token')
        ->assertStatus(419)
        ->assertSessionHas('url.intended', 'http://a.test/admin/content')
        ->getContent();

    expect($html)
        ->toContain('te-out')
        ->toContain('data-te-page="expired"')
        ->toContain(e(__('errors.expired.title')))
        ->toContain('href="'.route('admin.login').'"')
        ->not->toContain('CSRF');
});

it('opens "session expired" for a Livewire 419 and keeps only this admin\'s own pages to return to', function (): void {
    $this->get('http://a.test/admin/session-expired?to=/admin/content')
        ->assertOk()
        ->assertSee(e(__('errors.expired.title')), false)
        ->assertSessionHas('url.intended', 'http://a.test/admin/content');

    foreach (['//evil.test/admin', 'https://evil.test/admin', '/tin-tuc', '/admin/login', '/admin/../x\\y',
        '/admin/content?x=\\evil.test', "/admin/content?x=\r\nLocation:%20//evil.test", '/admin/content?x=1#frag',
        '/admin/content?x=a b', '/admin/login?tab=trash', 'https://evil.test/admin/content?tab=trash'] as $to) {
        $this->flushSession();
        $this->get('http://a.test/admin/session-expired?to='.urlencode($to))->assertOk()->assertSessionMissing('url.intended');
    }
});

it('keeps the query of the page to come back to (a list\'s filter, the trash tab)', function (): void {
    $this->get('http://a.test/admin/session-expired?to='.urlencode('/admin/content?tab=trash&q=b%C3%A0n'))
        ->assertOk()
        ->assertSessionHas('url.intended', 'http://a.test/admin/content?tab=trash&q=b%C3%A0n');

    $this->flushSession();
    $member = errorMember();
    $this->actingAs($member)->withHeader('Referer', 'http://a.test/admin/content?tab=trash')
        ->get('http://a.test/admin/_fault/token')
        ->assertStatus(419)
        ->assertSessionHas('url.intended', 'http://a.test/admin/content?tab=trash');
});

it('counts down a 429 and keeps "Thử lại" off until then', function (): void {
    $member = errorMember();

    $html = $this->actingAs($member)->get('http://a.test/admin/_fault/busy')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '42')
        ->getContent();

    expect($html)
        ->toContain('data-te-page="rate"')
        ->toContain('data-te-retry="42"')
        ->toContain(e(__('errors.rate.wait', ['seconds' => 42])))
        ->toContain('data-te-action="retry"')
        ->toContain('aria-disabled="true"')
        ->toContain(e(__('errors.rate.why')));
});

it('shows a fault with a short reference and support, and nothing internal', function (): void {
    $member = errorMember();
    config(['support.mailbox' => 'help@example.test']);

    $html = $this->actingAs($member)->get('http://a.test/admin/_fault/boom')->assertStatus(500)->getContent();

    expect($html)
        ->toContain('data-te-page="e500"')
        ->toContain(e(__('errors.e500.title')))
        ->toContain('data-te-copy=')
        ->toContain('mailto:help@example.test')
        ->toContain('bsSession(')
        ->toMatch('/<b aria-label="[^"]+">['.ErrorReference::ALPHABET.']{3}-['.ErrorReference::ALPHABET.']{3}<\/b>/u');
    assertNoLeak($html);
});

it('writes the reference on the page to the log, and gives a later abort(500) its own', function (): void {
    $member = errorMember();
    $earlier = null;
    Route::middleware(['web', SetCurrentSite::class, 'site.active', 'auth', 'auth.session', 'tenant.member'])
        ->get('admin/_fault/after-handled', function () use (&$earlier): never {
            report(new RuntimeException('a handled fault'));
            $earlier = Context::get('error_ref');
            abort(500);
        });
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $line) use (&$lines): void {
        // What the log context holds when the line is written (the line carries it).
        $lines[] = ['message' => $line->message, 'context' => $line->context, 'ref' => Context::get('error_ref')];
    });

    // A fault that is logged: its own log line carries the code on the page.
    $shown = shownReference($this->actingAs($member)->get('http://a.test/admin/_fault/boom')->assertStatus(500)->getContent());
    $logged = collect($lines)->firstWhere('message', 'secret detail: SQLSTATE users.id=4242 /app/Secret.php');
    expect($logged)->not->toBeNull()->and($logged['ref'])->toBe($shown);

    // An abort(500) after a handled, logged fault: a code of its own, written to a line of its own.
    $lines = [];
    $shown = shownReference($this->actingAs($member)->get('http://a.test/admin/_fault/after-handled')->assertStatus(500)->getContent());
    $own = collect($lines)->firstWhere('message', 'Admin error page shown');
    expect($earlier)->toBeString()
        ->and($shown)->not->toBe($earlier)
        ->and($own)->not->toBeNull()
        ->and($own['context']['error_ref'])->toBe($shown)
        ->and($own['ref'])->toBe($shown);
});

it('keeps Laravel\'s own page for a fault while debugging', function (): void {
    config(['app.debug' => true]);
    $member = errorMember();

    $html = $this->actingAs($member)->get('http://a.test/admin/_fault/boom')->assertStatus(500)->getContent();

    expect($html)->not->toContain('class="te-v"');
});

it('shows maintenance as a page of its own with reload', function (): void {
    $member = errorMember();

    $html = $this->actingAs($member)->get('http://a.test/admin/_fault/down')->assertStatus(503)->getContent();

    expect($html)
        ->toContain('te-out')
        ->toContain('data-te-page="maint"')
        ->toContain(e(__('errors.maint.line_plain')))
        ->toContain('data-te-action="reload"');
});

it('answers a Livewire update with the page alone and leaves JSON callers their own answer', function (): void {
    $member = errorMember();

    $html = $this->actingAs($member)->withHeaders(['X-Livewire' => '1'])
        ->get('http://a.test/admin/_fault/boom')->assertStatus(500)->getContent();
    expect($html)->toContain('te-out')->toContain('data-te-page="e500"');
    assertNoLeak($html);

    $this->flushHeaders();
    $json = $this->actingAs($member)->getJson('http://a.test/admin/_fault/boom');
    // A fetch() caller keeps what it had (the app renders JSON only under api/*): not this page.
    $json->assertStatus(500);
    expect((string) $json->getContent())->not->toContain('data-te-page');
    assertNoLeak((string) $json->getContent());
});

it('leaves the storefront\'s own error pages alone', function (): void {
    $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();

    expect($html)->not->toContain('data-te-page');
});

it('puts the session guard and the expired address in the admin frame', function (): void {
    $member = errorMember();

    $this->actingAs($member)->get('http://a.test/admin')->assertOk()
        ->assertSee('bsSession(', false)
        ->assertSee('<meta name="bs-session-expired" content="'.route('admin.session.expired').'">', false)
        ->assertSee(e(__('errors.warn.title')), false);
});

it('leaves the session-ending dialog out while support is signed in as the member', function (): void {
    $member = errorMember();
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $support = ['support_session' => [
        'staff_id' => $staff->getKey(), 'staff_label' => 'Hỗ trợ', 'member_id' => $member->getKey(), 'reason' => 'Kiểm tra',
        'site_id' => 1, 'expires_at' => now()->addMinutes(30)->getTimestamp(), 'session_ulid' => null,
    ]];
    $warnPair = substr(Js::from(['warn' => false])->toHtml(), strlen("JSON.parse('{"), -strlen("}')"));

    $this->actingAs($member)->withSession($support)->get('http://a.test/admin')->assertOk()
        ->assertSee('bsSession(', false)
        ->assertSee($warnPair, false)
        ->assertDontSee(e(__('errors.warn.title')), false)
        ->assertDontSee('x-ref="logoutForm"', false);
});

it('keeps the session alive for a member and not for a guest', function (): void {
    $member = errorMember();

    $this->actingAs($member)->post('http://a.test/admin/session/ping')->assertNoContent();
    auth()->logout();
    $this->post('http://a.test/admin/session/ping')->assertRedirect('http://a.test/admin/login');
});

it('sends a console operator\'s unknown address to the customer list', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    $platform = 'http://'.config('tenancy.platform_host');

    $this->actingAs($operator)->withHeaders(navigation())->get($platform.'/khong-co')
        ->assertRedirect(route('platform.tenants.index'))
        ->assertSessionHas('admin.notice', __('errors.unknown_address'));
    $this->flushHeaders();

    // The browser's own requests get "not found" and leave no notice.
    $this->actingAs($operator)->get($platform.'/.well-known/appspecific/com.chrome.devtools.json')
        ->assertNotFound()
        ->assertSessionMissing('admin.notice');
});

it('answers an unknown console address with "not found" for a guest and "no permission" for a non-operator', function (): void {
    config(['admin.brand.name' => 'Nền Tảng Thử']);
    $platform = 'http://'.config('tenancy.platform_host');

    $html = $this->withHeaders(navigation())->get($platform.'/khong-co')->assertNotFound()->getContent();
    expect($html)->toContain('data-te-page="notfound"')->toContain('Nền Tảng Thử');
    $this->assertGuest();
    expect(session('url.intended'))->toBeNull();

    $this->actingAs(User::factory()->create())->get($platform.'/khong-co')->assertForbidden();
});
