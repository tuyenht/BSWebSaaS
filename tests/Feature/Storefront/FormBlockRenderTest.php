<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

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

function seedContactPageWithForm(int $site = 1): void
{
    app(Tenancy::class)->enter($site, function (): void {
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
                ['type' => 'form', 'props' => ['form_key' => 'contact']],
                ['type' => 'contact', 'props' => ['heading' => 'Thông tin liên hệ', 'address' => 'Hà Nội']],
            ]],
        ]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $entry->id]);
    });
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

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('challenges.cloudflare.com/turnstile/v0/api.js')
        ->toContain('data-sitekey="0x4AAAAAADtFZhUYB224F9cf"');
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
        ->toContain('<label class="lead-form__check" for="lead-consent">')
        ->toContain('<input id="lead-consent" type="checkbox" name="consent" value="1"')
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

    $html = $this->withSession(['form_success' => 'Đã nhận yêu cầu của bạn, xin cảm ơn ạ'])
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

    $section = leadFormSection($this->get('http://a.test/lien-he')->assertOk()->getContent());

    expect($section)
        ->toContain('>Ống thép cần cắt gấp, giao ở Đà Nẵng nhé</textarea>')
        ->toContain('value="0912 345 678"')
        ->toMatch('/<input id="lead-name"[^>]*aria-invalid="true"[^>]*aria-describedby="lead-name-error"[^>]*autofocus\s*>/')
        ->toContain('<p class="lead-form__field-error" id="lead-name-error">Vui lòng nhập họ tên.</p>')
        ->toContain('<p class="lead-form__field-error" id="lead-consent-error">')
        ->toContain('<p class="lead-form__alert" role="alert"><a href="#lead-name">Vui lòng nhập họ tên.</a></p>')
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

    $section = leadFormSection($this->get('http://a.test/lien-he')->getContent());

    expect(substr_count($section, 'Vui lòng để lại email hoặc số điện thoại để chúng tôi liên hệ.'))->toBe(2) // under the field + the alert
        ->and($section)->toContain('<p class="lead-form__hint" id="lead-phone-hint">Để lại điện thoại hoặc email – ít nhất một.</p>')
        ->toMatch('/<input id="lead-email"[^>]*aria-describedby="lead-phone-error"[^>]*autofocus\s*>/')
        ->toMatch('/<input id="lead-phone"[^>]*aria-describedby="lead-phone-hint lead-phone-error"/')
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

    $section = leadFormSection($this->get('http://a.test/lien-he')->getContent());

    expect($section)->toContain('<p class="lead-form__alert" role="alert">Bạn đã gửi quá nhiều lần.')
        ->toMatch('/<button type="submit" class="lead-form__submit"\s+autofocus\s*>/')
        ->toContain('value="Nguyễn Văn Ánh"')
        ->not->toContain('aria-invalid');
});
