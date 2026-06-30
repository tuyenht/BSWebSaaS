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

beforeEach(function () {
    seedTenancy();
    config(['services.turnstile.site_key' => '']); // dev → Turnstile widget omitted
});

function seedContactPageWithForm(int $site = 1): void
{
    app(Tenancy::class)->enter($site, function () {
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

it('renders the form block with fields + honeypot + same-origin action, beside the NAP block', function () {
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

it('omits the Turnstile widget when no site_key is configured (dev)', function () {
    seedContactPageWithForm();

    expect($this->get('http://a.test/lien-he')->getContent())
        ->not->toContain('challenges.cloudflare.com');
});

it('includes the Turnstile widget when a site_key is configured', function () {
    config(['services.turnstile.site_key' => '0x4AAAAAADtFZhUYB224F9cf']);
    seedContactPageWithForm();

    expect($this->get('http://a.test/lien-he')->getContent())
        ->toContain('challenges.cloudflare.com/turnstile/v0/api.js')
        ->toContain('data-sitekey="0x4AAAAAADtFZhUYB224F9cf"');
});

it('renders nothing for the block when the form key does not resolve', function () {
    app(Tenancy::class)->enter(1, function () {
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
