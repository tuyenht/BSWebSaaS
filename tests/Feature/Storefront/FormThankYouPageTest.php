<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * The thank-you page a form opens after a send (`forms.settings.redirect_entry`, a page's ulid):
 * a 303 to the page's address in the visitor's language when it is a published page of this site,
 * otherwise the send stays on its page with the thank-you card. Never a redirect off the site, and
 * never while the send is refused for too many attempts.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    Notification::fake();
    Cache::flush(); // the per-IP send limit
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    config(['services.turnstile.secret_key' => '']);

    app(Tenancy::class)->enter(1, fn () => Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']));
});

/** A page of the site with its route per language; returns its ulid. @param array<string, string> $slugs locale → slug */
function tyPage(int $site, array $slugs, string $status = 'published', bool $trashed = false): string
{
    return app(Tenancy::class)->enter($site, function () use ($slugs, $status, $trashed): string {
        $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => now()]);
        foreach ($slugs as $locale => $slug) {
            $entry->translations()->create(['locale' => $locale, 'title' => 'Cảm ơn', 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => $locale, 'path' => '/'.$slug, 'entry_id' => $entry->id]);
        }
        if ($trashed) {
            $entry->delete();
        }

        return $entry->ulid;
    });
}

function tyRedirectTo(mixed $value): void
{
    app(Tenancy::class)->enter(1, fn () => Form::query()->where('key', 'contact')->update(['settings' => json_encode(['redirect_entry' => $value])]));
}

/** @param array<string, string> $extra */
function tySend(array $extra = []): TestResponse
{
    return test()->from('http://a.test/lien-he')
        ->post('http://a.test/forms/contact', ['name' => 'Khách', 'email' => 'a@example.test', 'message' => 'Xin chào', ...$extra]);
}

it('opens the thank-you page with a 303 after a send, and keeps the lead', function (): void {
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on']));

    tySend()->assertStatus(303)->assertRedirect('http://a.test/cam-on');

    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(1);
});

it('opens the thank-you page at its address in the visitor\'s language', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]));
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on', 'en' => 'thank-you']));

    tySend(['locale' => 'en'])->assertStatus(303)->assertRedirect('http://a.test/en/thank-you');
    tySend(['locale' => 'vi'])->assertStatus(303)->assertRedirect('http://a.test/cam-on');
});

it('stays on the page with the thank-you card when the page is not one a visitor can open here', function (Closure $page): void {
    tyRedirectTo($page());

    tySend()->assertRedirect('http://a.test/lien-he')->assertSessionHas('form_success');
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(1);
})->with([
    'a draft' => [fn (): string => tyPage(1, ['vi' => 'cam-on'], 'draft')],
    'a page in the trash' => [fn (): string => tyPage(1, ['vi' => 'cam-on'], trashed: true)],
    'another site\'s page' => [fn (): string => tyPage(2, ['vi' => 'cam-on'])],
    'a ulid that names nothing' => [fn (): string => '01JZ0000000000000000000000'],
]);

it('never sends the visitor off the site, whatever the setting holds', function (mixed $value): void {
    tyPage(1, ['vi' => 'cam-on']);
    tyRedirectTo($value);

    tySend()->assertRedirect('http://a.test/lien-he')->assertSessionHas('form_success');
})->with([
    'a full link' => ['https://evil.example/cam-on'],
    'protocol-relative' => ['//evil.example/cam-on'],
    'a path' => ['/cam-on'],
    'a script link' => ['javascript:alert(1)'],
    'a list' => [['https://evil.example']],
    'nothing' => [''],
]);

it('stays on the page with the thank-you card when the form names no thank-you page', function (): void {
    tySend()->assertRedirect('http://a.test/lien-he')->assertSessionHas('form_success');
});

it('does not open the thank-you page for a send refused for too many attempts', function (): void {
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on']));
    for ($i = 0; $i < 5; $i++) {
        tySend()->assertStatus(303);
    }

    tySend()->assertRedirect('http://a.test/lien-he')->assertSessionHasErrors('form')->assertSessionMissing('form_success');
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(5);
});

it('keeps a send that fails validation on its page with the errors, never opening the thank-you page', function (): void {
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on']));

    test()->from('http://a.test/lien-he')->post('http://a.test/forms/contact', ['email' => 'a@example.test'])
        ->assertStatus(302)->assertRedirect('http://a.test/lien-he')
        ->assertSessionHasErrors(['name', 'message'])->assertSessionMissing('form_success');
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(0);
});

it('answers a send it files as spam exactly like a real one, so a bot learns nothing', function (Closure $spam): void {
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on']));

    $extra = $spam();
    tySend($extra)->assertStatus(303)->assertRedirect('http://a.test/cam-on')->assertSessionHasNoErrors();

    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->sole()->is_spam))->toBeTrue();
    Notification::assertNothingSent();
})->with([
    'the hidden honeypot filled' => [fn (): array => ['company_website' => 'https://bot.example']],
    'Turnstile refusing the token' => [function (): array {
        config(['services.turnstile.secret_key' => 'test-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        return ['cf-turnstile-response' => 'bad-token'];
    }],
]);

it('sends to a form with a thank-you page within its query budget', function (): void {
    tyRedirectTo(tyPage(1, ['vi' => 'cam-on']));
    tySend()->assertStatus(303); // warm the per-site reads that outlive a request
    app()->forgetScopedInstances();

    $count = countQueries(fn () => tySend(['name' => 'Khách 2'])->assertStatus(303));

    expect($count)->toBe(9);
});
