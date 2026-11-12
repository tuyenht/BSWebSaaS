<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Storefront\Support\PrivacyPolicyLink;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Storefront form block (Increment 4) — the 'form' Page-JSON block renders a lead-capture form
 * from form_fields (resolved by key in the site context, RLS), with a hidden honeypot and a
 * same-origin POST action, coexisting with the static NAP/contact block on /lien-he. The Turnstile
 * widget appears ONLY when a site_key is configured (prod); dev/test render no third-party JS.
 */

beforeEach(function (): void {
    seedTenancy();
    config(['services.turnstile.site_key' => '']); // dev → Turnstile widget omitted
});

/** @param  array<string, mixed>  $formProps */
function seedContactPageWithForm(int $site = 1, array $formProps = ['form_key' => 'contact']): void
{
    app(Tenancy::class)->enter($site, function () use ($formProps): void {
        Form::query()->create([
            'key' => 'contact',
            'name' => ['vi' => 'Gửi yêu cầu báo giá'],
            'status' => 'active',
        ])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'email', 'type' => 'email', 'label' => ['vi' => 'Email'], 'is_required' => false, 'sort_order' => 2],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Điện thoại'], 'is_required' => false, 'sort_order' => 3],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung'], 'is_required' => true, 'sort_order' => 4],
        ]);

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => 'vi',
            'title' => 'Liên hệ',
            'slug' => 'lien-he',
            'status' => 'published',
            'body' => ['blocks' => [
                ['type' => 'form', 'props' => $formProps],
                ['type' => 'contact', 'props' => ['heading' => 'Thông tin liên hệ', 'address' => 'Hà Nội']],
            ]],
        ]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $entry->id]);
    });
}

/**
 * The page after a send, as the visitor sees it: the form's POST gave them the session cookie, and
 * only a request that brings it back reads the outcome (a page without one has no session at all).
 */
function returningSender(): TestCase
{
    return test()->withCookie((string) config('session.cookie'), 'visitor');
}

it('renders the form block with fields + honeypot + same-origin action, beside the NAP block', function (): void {
    seedContactPageWithForm();

    $html = $this->get('http://a.test/lien-he')->assertOk()->getContent();

    expect($html)
        ->toContain('action="/forms/contact"')
        ->toContain('name="name"')
        ->toContain('name="email"')
        ->toContain('name="message"')
        ->toContain('name="company_website"') // honeypot
        ->toContain('Gửi yêu cầu báo giá')     // localized form heading
        ->toContain('Thông tin liên hệ');      // static NAP block coexists
});

it('renders the form chrome in the site locale even when app.locale differs', function (): void {
    config(['app.locale' => 'en']); // prod default — the vi site must still render its UI in vi
    seedContactPageWithForm();

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('Gửi liên hệ')        // vi submit button (forms.submit_button)
        ->not->toContain('Send message');  // not the English string
});

it('omits the Turnstile widget when no site_key is configured (dev)', function (): void {
    seedContactPageWithForm();

    expect($this->get('http://a.test/lien-he')->getContent())
        ->not->toContain('challenges.cloudflare.com');
});

it('includes the Turnstile widget when a site_key is configured', function (): void {
    config(['services.turnstile.site_key' => '0x4AAAAAADtFZhUYB224F9cf']);
    seedContactPageWithForm();
    // A Vite that prints the entry it was asked for, so the test sees the loader is wired in.
    Vite::clearResolvedInstance();
    $this->swap(FoundationVite::class, new class extends FoundationVite
    {
        public function __invoke($entrypoints, $buildDirectory = null)
        {
            return new HtmlString('<script data-vite-entry="'.implode(',', (array) $entrypoints).'"></script>');
        }
    });

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('data-vite-entry="resources/js/sf-turnstile.js"')
        ->toContain('data-sitekey="0x4AAAAAADtFZhUYB224F9cf"')
        // Cloudflare's script is fetched by sf-turnstile.js once the visitor starts on the form, not on arrival.
        ->not->toContain('challenges.cloudflare.com/turnstile/v0/api.js')
        // Shown only when the visitor has to act, so a passed check leaves no empty box above the button.
        ->toContain('data-appearance="interaction-only"');
});

it('heads the form with its own heading, else with the form name', function (): void {
    seedContactPageWithForm(1, ['form_key' => 'contact', 'heading' => 'Gửi Yêu Cầu Tư Vấn']);

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('<h2 class="lead-form__heading">Gửi Yêu Cầu Tư Vấn</h2>')
        ->not->toContain('Gửi yêu cầu báo giá');
});

it('falls back to the form name when the heading is empty or blank', function (string $heading): void {
    seedContactPageWithForm(1, ['form_key' => 'contact', 'heading' => $heading]);

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('<h2 class="lead-form__heading">Gửi yêu cầu báo giá</h2>');
})->with(['', '   ']);

it('escapes the heading and declares it as plain text', function (): void {
    seedContactPageWithForm(1, ['form_key' => 'contact', 'heading' => '<i>Báo giá</i>']);

    expect($this->get('http://a.test/lien-he')->getContent())->toContain('&lt;i&gt;Báo giá&lt;/i&gt;')->not->toContain('<i>Báo giá')
        ->and(app(ComponentRegistry::class)->schemaFor('form')['heading'])->toBe(['type' => 'text']);
});

it('shows a field\'s placeholder in the visitor\'s language, and none for a field without one', function (): void {
    seedContactPageWithForm();
    app(Tenancy::class)->enter(1, function (): void {
        $fields = Form::query()->where('key', 'contact')->firstOrFail()->fields()->get()->keyBy('key');
        $fields['phone']->update(['options' => ['placeholder' => ['vi' => '0912 345 678', 'en' => '0912 345 679']]]);
        $fields['message']->update(['options' => ['placeholder' => ['vi' => 'Ví dụ: <sản phẩm>']]]);
    });

    $html = (string) $this->get('http://a.test/lien-he')->getContent();

    expect($html)->toContain('placeholder="0912 345 678"')
        ->toContain('placeholder="Ví dụ: &lt;sản phẩm&gt;"')
        ->and(substr_count(Str::between($html, '<section class="lead-form', '</section>'), 'placeholder="'))->toBe(2);
});

it('renders nothing for the block when the form key does not resolve', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => 'vi',
            'title' => 'Liên hệ',
            'slug' => 'lien-he',
            'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'missing']]]],
        ]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $entry->id]);
    });

    expect($this->get('http://a.test/lien-he')->assertOk()->getContent())
        ->not->toContain('name="company_website"'); // the lead form's honeypot — unique to this block
});

it('renders a required consent checkbox tied to its label', function (): void {
    seedContactPageWithForm();
    app(Tenancy::class)->enter(1, fn () => Form::query()->firstWhere('key', 'contact')->fields()->create([
        'key' => 'consent', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 5,
        'label' => ['vi' => 'Tôi đồng ý để website lưu thông tin.'],
    ]));

    $html = $this->get('http://a.test/lien-he')->assertOk()->getContent();

    expect($html)
        ->toContain('<label class="lead-form__check" for="lf-contact__consent">')
        ->toContain('<input id="lf-contact__consent" type="checkbox" name="consent" value="1"')
        ->toContain('<span>Tôi đồng ý để website lưu thông tin.');
});

/** The contact form's consent box, and (optionally) a published privacy page under $slug. */
function seedConsentAndPolicy(?string $slug, string $status = 'published'): ?Entry
{
    seedContactPageWithForm();

    return app(Tenancy::class)->enter(1, function () use ($slug, $status): ?Entry {
        Form::query()->firstWhere('key', 'contact')->fields()->create([
            'key' => 'consent', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 5,
            'label' => ['vi' => 'Tôi đồng ý để website lưu thông tin.'],
        ]);
        if ($slug === null) {
            return null;
        }
        $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Chính sách bảo mật', 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/'.$slug, 'entry_id' => $entry->id]);

        return $entry;
    });
}

/** The lead form section of a page. */
function leadFormSection(string $html): string
{
    $start = strpos($html, '<section class="lead-form');
    expect($start)->not->toBeFalse();

    return substr($html, $start, strpos($html, '</section>', $start) - $start);
}

it('links the privacy-policy page inside the consent sentence by its public path, never an id', function (): void {
    $policy = seedConsentAndPolicy('chinh-sach-bao-mat');
    $form = app(Tenancy::class)->enter(1, fn () => Form::query()->firstWhere('key', 'contact'));

    $section = leadFormSection($this->get('http://a.test/lien-he')->assertOk()->getContent());
    preg_match_all('/(?:href|action)="([^"]*)"/', $section, $links);

    expect($section)->toContain('<span>Tôi đồng ý để website lưu thông tin. <a class="lead-form__policy" href="/chinh-sach-bao-mat" target="_blank" rel="noopener">Xem chính sách bảo mật')
        ->and($links[1])->toBe(['/forms/contact', '/chinh-sach-bao-mat']);
    foreach ($links[1] as $link) {
        expect(preg_split('~[/?=&#]~', $link))->not->toContain((string) $policy->id)->not->toContain((string) $form->id);
    }
});

it('shows no policy link while the site has no published privacy page', function (?string $slug, string $status): void {
    seedConsentAndPolicy($slug, $status);

    expect($this->get('http://a.test/lien-he')->assertOk()->getContent())
        ->toContain('Tôi đồng ý để website lưu thông tin.')
        ->not->toContain('lead-form__policy');
})->with([
    'no such page' => [null, 'published'],
    'a draft' => ['chinh-sach-bao-mat', 'draft'],
    'another slug' => ['gioi-thieu', 'published'],
]);

it('replaces the form with the thank-you card and a way back to an empty form after a send', function (): void {
    seedContactPageWithForm();

    $html = returningSender()->withSession(['form_success' => 'Đã nhận yêu cầu của bạn, xin cảm ơn ạ', 'form_success_key' => 'contact'])
        ->get('http://a.test/lien-he')->assertOk()->getContent();

    expect($html)
        ->toContain('<div class="lead-form__sent" role="status" tabindex="-1" autofocus>')
        ->toContain('<p class="lead-form__sent-title">Đã gửi thành công</p>')
        ->toContain('<p class="lead-form__sent-text">Đã nhận yêu cầu của bạn, xin cảm ơn ạ</p>')
        ->toContain('<a class="lead-form__again" href="/lien-he#lf-contact">Gửi liên hệ khác</a>')
        ->not->toContain('<form class="lead-form__form"');

    expect($this->flushSession()->get('http://a.test/lien-he')->getContent())
        ->toContain('<form class="lead-form__form"')
        ->not->toContain('lead-form__sent');
});

it('keeps what was typed after a failed send, says what is wrong and focuses the first bad field', function (): void {
    Cache::flush(); // the per-IP send limit
    seedConsentAndPolicy(null);

    $this->from('http://a.test/lien-he')->post('http://a.test/forms/contact', [
        'phone' => '0912 345 678',
        'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé',
        'locale' => 'vi',
    ])->assertRedirect('http://a.test/lien-he');

    $section = leadFormSection(returningSender()->get('http://a.test/lien-he')->assertOk()->getContent());

    expect($section)
        ->toContain('>Ống thép cần cắt gấp, giao ở Đà Nẵng nhé</textarea>')
        ->toContain('value="0912 345 678"')
        ->toMatch('/<input id="lf-contact__name"[^>]*aria-invalid="true"[^>]*aria-describedby="lf-contact__name--error"[^>]*autofocus\s*>/')
        ->toContain('<p class="lead-form__field-error" id="lf-contact__name--error">Vui lòng nhập họ tên.</p>')
        ->toContain('<p class="lead-form__field-error" id="lf-contact__consent--error">')
        ->toContain('<p class="lead-form__alert" id="lf-contact--alert" role="alert"><a href="#lf-contact__name">Vui lòng nhập họ tên.</a></p>')
        ->and(substr_count($section, 'role="alert"'))->toBe(1)
        ->and($section)
        ->toContain('Trường có dấu <span class="lead-form__required">*</span> là bắt buộc.')
        ->and(substr_count($section, 'autofocus'))->toBe(1);
});

it('tells the phone-or-email rule once, under the hint, and points both fields at it', function (): void {
    Cache::flush();
    seedContactPageWithForm();

    $this->from('http://a.test/lien-he')->post('http://a.test/forms/contact', [
        'name' => 'Nguyễn Văn Ánh', 'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé', 'locale' => 'vi',
    ])->assertRedirect();

    $section = leadFormSection(returningSender()->get('http://a.test/lien-he')->getContent());

    expect(substr_count($section, 'Vui lòng để lại email hoặc số điện thoại để chúng tôi liên hệ.'))->toBe(2) // under the field + the alert
        ->and($section)->toContain('<p class="lead-form__hint" id="lf-contact__phone--hint">Để lại điện thoại hoặc email – ít nhất một.</p>')
        ->toMatch('/<input id="lf-contact__email"[^>]*aria-describedby="lf-contact__phone--error"[^>]*autofocus\s*>/')
        ->toMatch('/<input id="lf-contact__phone"[^>]*aria-describedby="lf-contact__phone--hint lf-contact__phone--error"/')
        ->toContain('value="Nguyễn Văn Ánh"');
});

it('moves the focus to the button when the send fails as a whole', function (): void {
    Cache::flush();
    seedContactPageWithForm();
    $form = app(Tenancy::class)->enter(1, fn () => Form::query()->firstWhere('key', 'contact'));
    foreach (range(1, 5) as $attempt) {
        RateLimiter::hit('lead-submit:'.$form->id.'|127.0.0.1', 60);
    }

    $this->from('http://a.test/lien-he')->post('http://a.test/forms/contact', [
        'name' => 'Nguyễn Văn Ánh', 'phone' => '0912 345 678', 'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé', 'locale' => 'vi',
    ])->assertRedirect();

    $section = leadFormSection(returningSender()->get('http://a.test/lien-he')->getContent());

    expect($section)->toContain('<p class="lead-form__alert" id="lf-contact--alert" role="alert">Bạn đã gửi quá nhiều lần.')
        ->toMatch('/<button type="submit" class="lead-form__submit"\s+aria-describedby="lf-contact--alert"\s+autofocus\s*>/')
        ->toContain('value="Nguyễn Văn Ánh"')
        ->not->toContain('aria-invalid');
});

/** A second form ("quote") and a page that carries the given form blocks (default: contact, then quote). */
function seedTwoFormsPage(?array $blocks = null): void
{
    $blocks ??= [['type' => 'form', 'props' => ['form_key' => 'contact']], ['type' => 'form', 'props' => ['form_key' => 'quote']]];
    seedContactPageWithForm();
    app(Tenancy::class)->enter(1, function () use ($blocks): void {
        Form::query()->create(['key' => 'quote', 'name' => ['vi' => 'Đặt hàng nhanh'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Điện thoại'], 'is_required' => true, 'sort_order' => 2],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung'], 'is_required' => true, 'sort_order' => 3],
        ]);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Đặt hàng', 'slug' => 'dat-hang', 'status' => 'published', 'body' => ['blocks' => $blocks]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/dat-hang', 'entry_id' => $page->id]);
    });
}

/** @return list<string> the ids that occur more than once on the page */
function duplicateIds(string $html): array
{
    preg_match_all('/\sid="([^"]*)"/', $html, $found);

    return array_keys(array_filter(array_count_values($found[1]), fn (int $n): bool => $n > 1));
}

/** The lead form section with the given id. */
function leadFormById(string $html, string $id): string
{
    $start = strpos($html, 'id="'.$id.'"');
    expect($start)->not->toBeFalse();

    return substr($html, $start, strpos($html, '</section>', $start) - $start);
}

it('shows the thank-you card only on the form that was sent', function (): void {
    Cache::flush();
    seedTwoFormsPage();

    $this->from('http://a.test/dat-hang')->post('http://a.test/forms/quote', [
        'name' => 'Nguyễn Văn Ánh', 'phone' => '0912 345 678', 'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé', 'locale' => 'vi',
    ])->assertRedirect('http://a.test/dat-hang');

    $html = returningSender()->get('http://a.test/dat-hang')->assertOk()->getContent();

    expect(substr_count($html, 'class="lead-form__sent" '))->toBe(1)
        ->and(leadFormById($html, 'lf-quote'))->toContain('lead-form__sent')->not->toContain('<form')
        ->and(leadFormById($html, 'lf-contact'))->toContain('<form class="lead-form__form"')->not->toContain('lead-form__sent');
});

it('shows a failed send only on the form it came from, with one alert and one focus', function (): void {
    Cache::flush();
    seedTwoFormsPage();

    $this->from('http://a.test/dat-hang')->post('http://a.test/forms/quote', [
        'phone' => '0912 345 678', 'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé', 'locale' => 'vi',
    ])->assertRedirect();

    $html = returningSender()->get('http://a.test/dat-hang')->assertOk()->getContent();

    expect(substr_count($html, 'role="alert"'))->toBe(1)
        ->and(substr_count($html, 'autofocus'))->toBe(1)
        ->and(substr_count($html, 'aria-invalid'))->toBe(1)
        ->and(leadFormById($html, 'lf-quote'))->toContain('id="lf-quote__name--error"')->toContain('href="#lf-quote__name"')
        ->and(leadFormById($html, 'lf-contact'))->not->toContain('role="alert"')->not->toContain('value="0912 345 678"')
        ->and(duplicateIds($html))->toBe([]);
});

it('shows a failed send on the form that was posted, named by the server, keeping what was typed', function (): void {
    Cache::flush();
    seedTwoFormsPage();

    // No form name in the post (a page built before the field existed, or another client).
    $this->from('http://a.test/dat-hang')->post('http://a.test/forms/quote', [
        'phone' => '0912 345 678', 'message' => 'Ống thép cần cắt gấp, giao ở Đà Nẵng nhé', 'locale' => 'vi',
    ])->assertRedirect();

    $html = returningSender()->get('http://a.test/dat-hang')->assertOk()->getContent();

    expect(substr_count($html, 'role="alert"'))->toBe(1)
        ->and(leadFormById($html, 'lf-quote'))->toContain('role="alert"')->toContain('value="0912 345 678"')
        ->and(leadFormById($html, 'lf-contact'))->not->toContain('role="alert"')->not->toContain('value="0912 345 678"');
});

it('never repeats an id, also for keys that reduce to the same text or field keys ending like a suffix', function (): void {
    Cache::flush();
    seedTwoFormsPage([
        ['type' => 'form', 'props' => ['form_key' => 'contact']],
        ['type' => 'form', 'props' => ['form_key' => 'contact']],
        ['type' => 'form', 'props' => ['form_key' => 'quote']],
        ['type' => 'form', 'props' => ['form_key' => 'Quote']],
    ]);
    app(Tenancy::class)->enter(1, function (): void {
        Form::query()->create(['key' => 'Quote', 'name' => ['vi' => 'Đặt hàng nhanh'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'name-error', 'type' => 'text', 'label' => ['vi' => 'Ghi chú'], 'is_required' => false, 'sort_order' => 2],
            ['key' => 'Name', 'type' => 'text', 'label' => ['vi' => 'Tên khác'], 'is_required' => false, 'sort_order' => 3],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung'], 'is_required' => true, 'sort_order' => 4],
        ]);
    });

    $html = $this->get('http://a.test/dat-hang')->assertOk()->getContent();
    preg_match_all('/\bid="(lf-[^"]*)"/', $html, $m);

    expect($html)->toContain('id="lf-contact--2"')->toContain('id="lf-quote--2"')
        ->and(duplicateIds($html))->toBe([])
        ->and(array_filter($m[1], fn (string $id): bool => preg_match('/^lf-[a-z0-9_]+(--[0-9]+)?(__[a-z0-9_]+(--[0-9a-z]+)*)?(--[a-z]+)?$/', $id) !== 1))->toBe([]);
});

it('keeps every id on the page unique, also when the same form is placed twice', function (): void {
    seedTwoFormsPage([
        ['type' => 'form', 'props' => ['form_key' => 'contact']],
        ['type' => 'form', 'props' => ['form_key' => 'quote']],
        ['type' => 'form', 'props' => ['form_key' => 'contact']],
    ]);

    $html = $this->get('http://a.test/dat-hang')->assertOk()->getContent();

    expect($html)->toContain('id="lf-contact"')->toContain('id="lf-contact--2"')->toContain('id="lf-contact--2__name"')->toContain('id="lf-quote__name"')
        ->and(duplicateIds($html))->toBe([]);
});

it('shows a failed send on the first copy only when the same form is placed twice', function (): void {
    Cache::flush();
    seedTwoFormsPage([['type' => 'form', 'props' => ['form_key' => 'contact']], ['type' => 'form', 'props' => ['form_key' => 'contact']]]);

    $this->from('http://a.test/dat-hang')->post('http://a.test/forms/contact', ['locale' => 'vi'])->assertRedirect();
    $html = returningSender()->get('http://a.test/dat-hang')->assertOk()->getContent();

    expect(substr_count($html, 'role="alert"'))->toBe(1)
        ->and(substr_count($html, 'autofocus'))->toBe(1)
        ->and(duplicateIds($html))->toBe([]);
});

/** The privacy link for $locale, resolved fresh inside site $site. */
function policyUrl(string $locale, int $site = 1): ?string
{
    app()->forgetScopedInstances();

    return app(Tenancy::class)->enter($site, fn (): ?string => app(PrivacyPolicyLink::class)->url($locale));
}

/**
 * A published privacy page with one [locale, slug, route path or null] row per translation.
 *
 * @param  list<array{string, string, ?string}>  $translations
 */
function seedPolicyPage(array $translations, int $site = 1): void
{
    app(Tenancy::class)->enter($site, function () use ($translations): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        foreach ($translations as [$locale, $slug, $route]) {
            $entry->translations()->create(['locale' => $locale, 'title' => 'Chính sách bảo mật', 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
            if ($route !== null) {
                SiteRoute::query()->create(['locale' => $locale, 'path' => $route, 'entry_id' => $entry->id]);
            }
        }
    });
}

function addEnglish(): void
{
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]));
}

it('points a visitor in another language at that language\'s privacy page', function (): void {
    seedContactPageWithForm();
    addEnglish();
    seedPolicyPage([['vi', 'chinh-sach-bao-mat', '/chinh-sach-bao-mat'], ['en', 'privacy-policy', '/privacy-policy']]);

    expect(policyUrl('en'))->toBe('/en/privacy-policy')
        ->and(policyUrl('vi'))->toBe('/chinh-sach-bao-mat');
});

it('falls back to the default-language privacy page when the visitor\'s language has none', function (): void {
    seedContactPageWithForm();
    addEnglish();
    seedPolicyPage([['vi', 'chinh-sach-bao-mat', '/chinh-sach-bao-mat']]);

    expect(policyUrl('en'))->toBe('/chinh-sach-bao-mat');
});

it('finds the privacy page by the slug it has in the site\'s new default language', function (): void {
    seedContactPageWithForm();
    addEnglish();
    seedPolicyPage([['vi', 'rieng-tu', '/rieng-tu'], ['en', 'privacy-policy', '/privacy-policy']]);

    expect(policyUrl('vi'))->toBeNull(); // the default is vi and no vi slug is a policy slug

    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->where('site_id', 1)->update(['is_default' => false]);
    $admin->table('site_locales')->where('site_id', 1)->where('locale', 'en')->update(['is_default' => true]);

    expect(policyUrl('en'))->toBe('/privacy-policy');
});

it('never shows another site\'s privacy page', function (): void {
    seedContactPageWithForm();
    seedPolicyPage([['vi', 'chinh-sach-bao-mat', '/chinh-sach-bao-mat']], site: 2);

    expect(policyUrl('vi', 1))->toBeNull()
        ->and(policyUrl('vi', 2))->toBe('/chinh-sach-bao-mat');
});

it('composes the privacy page path when it has no stored route', function (): void {
    seedContactPageWithForm();
    seedPolicyPage([['vi', 'chinh-sach-bao-mat', null]]);

    expect(policyUrl('vi'))->toBe('/chinh-sach-bao-mat');
});

it('renders a page with a consent form within its query budget', function (): void {
    seedConsentAndPolicy('chinh-sach-bao-mat');
    $this->get('http://a.test/lien-he')->assertOk(); // warm the per-site reads that outlive a request
    app()->forgetScopedInstances();

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/lien-he')->assertOk()->getContent();
    });

    expect($html)->toContain('lead-form__policy')
        ->and($count)->toBe(13);
});

/**
 * The contact page with a form whose service field is a select.
 *
 * @param  array<string, mixed>  $service  overrides for the select field
 */
function seedSelectFormPage(array $service = []): void
{
    app(Tenancy::class)->enter(1, function () use ($service): void {
        Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Báo giá'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'service', 'type' => 'select', 'label' => ['vi' => 'Bạn cần tư vấn về', 'en' => 'You need help with'], 'is_required' => true, 'sort_order' => 2,
                'options' => ['choices' => [
                    ['value' => 'jig', 'label' => ['vi' => 'Thiết kế Jig & Đồ gá', 'en' => 'Jig & fixture design']],
                    ['value' => 'mold', 'label' => ['vi' => 'Khuôn mẫu']],
                ]], ...$service],
        ]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'contact']]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $entry->id]);
    });
}

it('draws a select field as a labelled list of its choices on a full row, starting with an empty pick', function (): void {
    seedSelectFormPage();

    $section = leadFormSection($this->get('http://a.test/lien-he')->assertOk()->getContent());

    expect($section)
        ->toMatch('/<div class="lead-form__field">\s*<label for="lf-contact__service">/')
        ->toMatch('/<select id="lf-contact__service" name="service"\s+required\s*>\s*<option value="">— Chọn —<\/option>\s*<option value="jig"\s*>Thiết kế Jig &amp; Đồ gá<\/option>\s*<option value="mold"\s*>Khuôn mẫu<\/option>\s*<\/select>/')
        ->not->toContain('name="service" type=');
});

it('keeps the picked choice after a failed send and points a missing choice at its message', function (): void {
    Cache::flush(); // the per-IP send limit
    seedSelectFormPage();

    $this->from('http://a.test/lien-he')->post('http://a.test/forms/contact', ['service' => 'mold', 'locale' => 'vi'])
        ->assertRedirect('http://a.test/lien-he');
    expect(leadFormSection(returningSender()->get('http://a.test/lien-he')->getContent()))
        ->toMatch('/<option value="mold"\s+selected\s*>Khuôn mẫu<\/option>/');

    $this->from('http://a.test/lien-he')->post('http://a.test/forms/contact', ['name' => 'A', 'service' => '', 'locale' => 'vi']);
    expect(leadFormSection(returningSender()->get('http://a.test/lien-he')->getContent()))
        ->toMatch('/<select id="lf-contact__service" name="service"\s+required\s+aria-invalid="true"\s+aria-describedby="lf-contact__service--error"\s+autofocus\s*>/')
        ->toContain('<p class="lead-form__field-error" id="lf-contact__service--error">Vui lòng chọn Bạn cần tư vấn về.</p>');
});

it('offers an optional select\'s empty pick as a real answer and draws no required mark', function (): void {
    seedSelectFormPage(['is_required' => false]);

    $section = leadFormSection($this->get('http://a.test/lien-he')->assertOk()->getContent());

    expect($section)->toMatch('/<select id="lf-contact__service" name="service"\s*>\s*<option value="">— Chọn —<\/option>/')
        ->and(substr_count($section, 'lead-form__required" aria-hidden="true"'))->toBe(1);
});

it('starts a select with its own example in the visitor\'s language, else the Vietnamese one, else the general one', function (): void {
    $choices = [['value' => 'jig', 'label' => ['vi' => 'Thiết kế Jig & Đồ gá']]];
    seedSelectFormPage(['options' => ['choices' => $choices, 'placeholder' => ['vi' => '— Chọn nhu cầu —', 'en' => '— Pick a need —']]]);
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $entry = Entry::query()->findOrFail(SiteRoute::query()->where('path', '/lien-he')->value('entry_id'));
        $entry->translations()->create(['locale' => 'en', 'title' => 'Contact', 'slug' => 'contact', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'contact']]]]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/contact', 'entry_id' => $entry->id]);
    });
    $empty = fn (string $url): string => Str::betweenFirst(leadFormSection($this->get($url)->assertOk()->getContent()), 'name="service"', '</option>');

    expect($empty('http://a.test/lien-he'))->toEndWith('<option value="">— Chọn nhu cầu —')
        ->and($empty('http://a.test/en/contact'))->toEndWith('<option value="">— Pick a need —');

    $field = fn () => Form::query()->firstWhere('key', 'contact')->fields()->where('key', 'service');
    app(Tenancy::class)->enter(1, fn () => $field()->update(['options' => json_encode(['choices' => $choices, 'placeholder' => ['vi' => '— Chọn nhu cầu —']])]));
    expect($empty('http://a.test/en/contact'))->toEndWith('<option value="">— Chọn nhu cầu —');

    app(Tenancy::class)->enter(1, fn () => $field()->update(['options' => json_encode(['choices' => $choices])]));
    expect($empty('http://a.test/lien-he'))->toEndWith('<option value="">— Chọn —');
});
