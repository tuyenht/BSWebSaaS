<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;

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
        ->toContain('<input id="lead-consent" type="checkbox" name="consent" value="1"')
        ->toContain('<label for="lead-consent">Tôi đồng ý để website lưu thông tin.');
});
