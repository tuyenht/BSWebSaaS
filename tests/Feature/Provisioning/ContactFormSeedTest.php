<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * Increment 6 — every provisioned site gets a ready-to-use lead-capture form: the 'contact' form +
 * its fields are seeded (owner as the default notify recipient), and the storefront /lien-he page
 * embeds the form block (submits to /forms/contact). Idempotent — a re-run never duplicates.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, form_submissions, form_fields, forms,
         domains, sites, tenants, tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionForForm(string $host = 'anvinh.platform.test', string $email = 'owner@anvinh.test'): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: $host,
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

it('seeds a contact form with its fields and the owner as the notify recipient', function (): void {
    $site = provisionForForm();

    [$form, $fieldKeys] = app(Tenancy::class)->enter($site->id, function (): array {
        $form = Form::query()->where('key', 'contact')->with('fields')->firstOrFail();

        return [$form, $form->fields->pluck('key')->all()];
    });

    expect($form->status)->toBe('active')
        ->and($form->notify_emails)->toBe(['owner@anvinh.test'])
        ->and($fieldKeys)->toBe(['name', 'email', 'phone', 'message', 'consent']);
});

it('embeds the form block on the provisioned /lien-he page', function (): void {
    provisionForForm();

    $html = $this->get('http://anvinh.platform.test/lien-he')->assertOk()->getContent();

    expect($html)
        ->toContain('action="/forms/contact"')
        ->toContain('name="company_website"'); // honeypot present
});

it('is idempotent — a re-provision does not duplicate the form or its fields', function (): void {
    $site = provisionForForm();
    provisionForForm(); // re-run on the same host

    $counts = app(Tenancy::class)->enter($site->id, fn (): array => [
        'forms' => Form::query()->where('key', 'contact')->count(),
        'fields' => Form::query()->where('key', 'contact')->firstOrFail()->fields()->count(),
    ]);

    expect($counts['forms'])->toBe(1)->and($counts['fields'])->toBe(5);
});

it('asks the visitors of every new site for consent, in each interface language', function (): void {
    $site = provisionForForm();

    $consent = app(Tenancy::class)->enter($site->id, fn () => Form::query()->where('key', 'contact')->firstOrFail()
        ->fields()->where('key', 'consent')->firstOrFail());

    expect($consent->type)->toBe('checkbox')
        ->and($consent->is_required)->toBeTrue()
        ->and($consent->label)->toHaveKeys(['vi', 'en', 'ja', 'zh'])
        ->and($consent->label['vi'])->toBe(__('forms.consent_label', [], 'vi'));
});
