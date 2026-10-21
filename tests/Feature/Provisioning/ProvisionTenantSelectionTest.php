<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameOwnedByAnotherTenantException;
use App\Modules\Provisioning\Exceptions\TenantSelectionException;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Which customer a website goes to. `tenants.name` is not unique, so site:provision never guesses:
 * a NEW website names an existing customer (ulid or exact name) or asks for a new one explicitly;
 * anything else is refused in plain words before a row is written — a typo must not mint a
 * customer with a fresh `sites.max` quota. An existing website always keeps its own customer.
 */

const TSEL_PROMPT = 'Owner password (leave blank to auto-generate)';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, site_features, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    DB::connection('pgsql_admin')->statement('delete from plans');
    truncateRbac();
});

/** Provision through the DTO (find-or-create by name), as seeders and tests do. */
function tselSeed(string $tenant, string $host): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenant,
        siteName: $host,
        hostname: $host,
        ownerEmail: 'owner@'.$host,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** A DTO re-run of a1.test that chooses its customer by id or asks for a new one, as site:provision does. */
function tselRerun(?int $tenantId = null, ?string $newTenantName = null): ProvisionSiteData
{
    return new ProvisionSiteData(
        tenantName: '',
        siteName: 'A1',
        hostname: 'a1.test',
        ownerEmail: 'owner@a1.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
        tenantId: $tenantId,
        newTenantName: $newTenantName,
    );
}

/** Let $tenant have up to $limit websites. */
function tselGrant(Tenant $tenant, int $limit): void
{
    $plan = Plan::query()->create(['key' => 'p'.$limit.strtolower(Str::random(6)), 'name' => 'Plan '.$limit, 'is_active' => true, 'sort_order' => 1]);
    PlanFeature::query()->create(['plan_id' => $plan->id, 'feature_key' => 'sites.max', 'value' => $limit]);
    $tenant->forceFill(['plan_id' => $plan->id])->save();
}

/**
 * @param  array<string, string|bool>  $options
 * @return array<string, string|bool>
 */
function tselArgs(string $host, array $options = []): array
{
    return ['--name' => $host, '--host' => $host, '--owner-email' => 'owner@'.$host] + $options;
}

/** @return list<int> */
function tselMemberships(string $email): array
{
    return DB::table('tenant_users')
        ->join('users', 'users.id', '=', 'tenant_users.user_id')
        ->where('users.email', $email)
        ->orderBy('tenant_id')
        ->pluck('tenant_id')
        ->map(fn (mixed $id): int => Cast::int($id))
        ->values()
        ->all();
}

it('refuses a new website that names no customer, and creates nothing', function (): void {
    $this->artisan('site:provision', tselArgs('new.test'))
        ->expectsOutputToContain(__('provisioning.tenant_required'))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('refuses --tenant and --new-tenant together', function (): void {
    $this->artisan('site:provision', tselArgs('new.test', ['--tenant' => 'Alpha', '--new-tenant' => 'Alpha']))
        ->expectsOutputToContain(__('provisioning.tenant_both'))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

it('creates a customer only with --new-tenant, and prints its ulid', function (): void {
    $this->artisan('site:provision', tselArgs('new.test', ['--new-tenant' => '  Công ty Mới  ']))
        ->expectsQuestion(TSEL_PROMPT, '')
        ->expectsOutputToContain('Created customer ')
        ->assertSuccessful();

    $tenant = Tenant::query()->sole();
    $site = Site::query()->sole();

    expect($tenant->name)->toBe('Công ty Mới')
        ->and($site->tenant_id)->toBe($tenant->id)
        ->and(tselMemberships('owner@new.test'))->toBe([$tenant->id]);

    $this->artisan('site:provision', tselArgs('new.test', ['--tenant' => $tenant->ulid]))
        ->expectsQuestion(TSEL_PROMPT, '')
        ->expectsOutputToContain("for customer {$tenant->ulid}")
        ->assertSuccessful();
});

it('refuses a new customer name longer than the column holds', function (): void {
    $this->artisan('site:provision', tselArgs('new.test', ['--new-tenant' => str_repeat('a', 256)]))
        ->expectsOutputToContain(__('provisioning.tenant_name_too_long', ['max' => 255]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses --new-tenant for a name a customer already has, in any letter case', function (string $typed): void {
    $existing = tselSeed('An Vinh', 'anvinh.test');
    $tenant = Tenant::query()->findOrFail($existing->tenant_id);
    tselGrant($tenant, 1);

    // The copy-pasted --new-tenant of a second website: no second "An Vinh" with a fresh quota.
    $this->artisan('site:provision', tselArgs('b1.test', ['--new-tenant' => $typed]))
        ->expectsOutputToContain(__('provisioning.tenant_name_exists', [
            'name' => $typed,
            'candidates' => "{$tenant->ulid} \"An Vinh\"",
        ]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1);
})->with([
    'same name' => ['An Vinh'],
    'other letter case' => ['an vinh'],
]);

it('creates a second customer of the same name only with --allow-same-name', function (): void {
    $existing = Tenant::query()->create(['name' => 'Twin Co']);

    $this->artisan('site:provision', tselArgs('new.test', ['--new-tenant' => 'Twin Co', '--allow-same-name' => true]))
        ->expectsQuestion(TSEL_PROMPT, '')
        ->assertSuccessful();

    expect(Tenant::query()->where('name', 'Twin Co')->count())->toBe(2)
        ->and(Site::query()->sole()->tenant_id)->not->toBe($existing->id);
});

it('adds a website to an existing customer found by :dataset', function (Closure $reference): void {
    $first = tselSeed('Alpha', 'a1.test');
    $tenant = Tenant::query()->findOrFail($first->tenant_id);
    tselGrant($tenant, 2);

    $this->artisan('site:provision', tselArgs('a2.test', ['--tenant' => $reference($tenant)]))
        ->expectsQuestion(TSEL_PROMPT, '')
        ->expectsOutputToContain("for customer {$tenant->ulid}")
        ->assertSuccessful();

    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
})->with([
    'ulid' => [fn (Tenant $t): string => $t->ulid],
    'lowercase ulid' => [fn (Tenant $t): string => strtolower($t->ulid)],
    'exact name' => [fn (Tenant $t): string => $t->name],
]);

it('refuses an unknown customer name or ulid without creating one', function (string $reference): void {
    tselSeed('Alpha', 'a1.test');

    $this->artisan('site:provision', tselArgs('b1.test', ['--tenant' => $reference]))
        ->expectsOutputToContain(__('provisioning.tenant_not_found', ['reference' => $reference]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1);
})->with([
    'name' => ['Alhpa'],
    'ulid' => [(string) Str::ulid()],
]);

it('suggests, but never picks, a customer whose name differs only in letter case', function (): void {
    $site = tselSeed('An Vinh', 'anvinh.test');
    $tenant = Tenant::query()->findOrFail($site->tenant_id);

    $this->artisan('site:provision', tselArgs('b1.test', ['--tenant' => 'an vinh']))
        ->expectsOutputToContain(__('provisioning.tenant_did_you_mean', [
            'reference' => 'an vinh',
            'candidates' => "{$tenant->ulid} \"An Vinh\"",
        ]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1);
});

it('refuses a name two customers share, listing both ulids', function (): void {
    $one = Tenant::query()->create(['name' => 'Twin']);
    $two = Tenant::query()->create(['name' => 'Twin']);

    $this->artisan('site:provision', tselArgs('b1.test', ['--tenant' => 'Twin']))
        ->expectsOutputToContain(__('provisioning.tenant_ambiguous', [
            'reference' => 'Twin',
            'candidates' => "{$one->ulid} \"Twin\", {$two->ulid} \"Twin\"",
        ]))
        ->assertFailed();

    expect(Site::query()->count())->toBe(0);
});

it('does not find a soft-deleted customer', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Gone']);
    $tenant->delete();

    $this->artisan('site:provision', tselArgs('b1.test', ['--tenant' => $tenant->ulid]))
        ->expectsOutputToContain(__('provisioning.tenant_not_found', ['reference' => $tenant->ulid]))
        ->assertFailed();
});

it('re-runs an existing website for its own customer named by ulid', function (): void {
    $site = tselSeed('Alpha', 'a1.test');
    $owner = Tenant::query()->findOrFail($site->tenant_id);

    $this->artisan('site:provision', tselArgs('a1.test', ['--tenant' => $owner->ulid]))
        ->expectsQuestion(TSEL_PROMPT, '')
        ->assertSuccessful();

    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1)
        ->and(tselMemberships('owner@a1.test'))->toBe([$site->tenant_id]);
});

it('refuses a re-run that names no customer, before asking for a password, naming the owner', function (): void {
    $site = tselSeed('Beta', 'beta.test');
    $owner = Tenant::query()->findOrFail($site->tenant_id);
    $roles = DB::table('model_has_roles')->count();

    // An operator setting up Alpha mistypes --host as Beta's website: never default to Beta.
    $this->artisan('site:provision', ['--name' => 'Alpha', '--host' => 'beta.test', '--owner-email' => 'owner@alpha.test'])
        ->expectsOutputToContain(__('provisioning.tenant_rerun_needs_tenant', ['host' => 'beta.test', 'tenant' => $owner->ulid]))
        ->assertFailed();

    expect(tselMemberships('owner@alpha.test'))->toBe([])
        ->and(DB::table('model_has_roles')->count())->toBe($roles)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1);
});

it('refuses a re-run that names another customer before asking for a password', function (): void {
    $site = tselSeed('Alpha', 'a1.test');
    $owner = Tenant::query()->findOrFail($site->tenant_id);
    $other = Tenant::query()->create(['name' => 'Beta']);

    // No expectsQuestion: the refusal comes before the password prompt.
    $this->artisan('site:provision', tselArgs('a1.test', ['--tenant' => 'Beta']))
        ->expectsOutputToContain(__('provisioning.tenant_mismatch', ['host' => 'a1.test', 'tenant' => $owner->ulid]))
        ->assertFailed();

    expect(Site::query()->sole()->tenant_id)->toBe($owner->id)
        ->and(tselMemberships('owner@a1.test'))->toBe([$owner->id])
        ->and(Site::query()->where('tenant_id', $other->id)->count())->toBe(0);
});

it('refuses --new-tenant for a hostname that already has a website, creating no customer', function (): void {
    $site = tselSeed('Alpha', 'a1.test');
    $owner = Tenant::query()->findOrFail($site->tenant_id);

    $this->artisan('site:provision', tselArgs('a1.test', ['--new-tenant' => 'Alpha 2']))
        ->expectsOutputToContain(__('provisioning.tenant_host_taken', ['host' => 'a1.test', 'tenant' => $owner->ulid]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(1)
        ->and(tselMemberships('owner@a1.test'))->toBe([$owner->id]);
});

it('re-runs a website named in another spelling of its hostname, without a second website', function (): void {
    $site = tselSeed('Alpha', 'a1.test');
    $owner = Tenant::query()->findOrFail($site->tenant_id);

    $this->artisan('site:provision', ['--name' => 'A1', '--host' => ' A1.Test. ', '--owner-email' => 'owner@a1.test', '--tenant' => $owner->ulid])
        ->expectsQuestion(TSEL_PROMPT, '')
        ->expectsOutputToContain("Provisioned site #{$site->id} (a1.test)")
        ->assertSuccessful();

    expect(Site::query()->count())->toBe(1)
        ->and(Domain::query()->pluck('hostname')->all())->toBe(['a1.test']);
});

it('refuses a hostname a website cannot use, and creates nothing', function (string $host): void {
    $this->artisan('site:provision', tselArgs($host, ['--new-tenant' => 'Alpha']))
        ->expectsOutputToContain(__('provisioning.host_invalid', ['host' => $host]))
        ->assertFailed();

    expect(Tenant::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
})->with([
    'single label' => ['localhost'],
    'ip address' => ['127.0.0.1'],
    'underscore' => ['my_shop.test'],
]);

it('keeps ProvisionSite itself refusing another customer for an existing website', function (Closure $choice, Closure $refusal): void {
    $site = tselSeed('Alpha', 'a1.test');
    $beta = Tenant::query()->create(['name' => 'Beta']);

    expect(fn () => app(ProvisionSite::class)->handle($choice($beta)))->toThrow($refusal);

    expect(Tenant::query()->count())->toBe(2)
        ->and(Site::query()->sole()->tenant_id)->toBe($site->tenant_id)
        ->and(tselMemberships('owner@a1.test'))->toBe([$site->tenant_id]);
})->with([
    'another customer by id' => [
        fn (Tenant $beta): ProvisionSiteData => tselRerun(tenantId: $beta->id),
        fn (HostnameOwnedByAnotherTenantException $e) => expect($e->hostname)->toBe('a1.test'),
    ],
    'a new customer' => [
        fn (Tenant $beta): ProvisionSiteData => tselRerun(newTenantName: 'Gamma'),
        fn (TenantSelectionException $e) => expect($e->reason)->toBe('host_taken'),
    ],
]);

it('refuses a DTO re-run under another customer name instead of minting that customer', function (): void {
    tselSeed('Alpha', 'a1.test');

    expect(fn () => tselSeed('Alpha Typo', 'a1.test'))->toThrow(HostnameOwnedByAnotherTenantException::class);

    expect(Tenant::query()->pluck('name')->all())->toBe(['Alpha'])
        ->and(tselMemberships('owner@a1.test'))->toHaveCount(1);
});

it('keeps ProvisionSite itself refusing a new customer under a name one already has', function (): void {
    $existing = Tenant::query()->create(['name' => 'An Vinh']);
    $data = fn (bool $allow): ProvisionSiteData => new ProvisionSiteData(
        tenantName: 'an vinh',
        newTenantName: 'an vinh',
        allowSameName: $allow,
        siteName: 'Second',
        hostname: 'second.test',
        ownerEmail: 'owner@second.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    );

    expect(fn () => app(ProvisionSite::class)->handle($data(false)))->toThrow(
        fn (TenantSelectionException $e) => expect($e->reason)->toBe('name_exists')
            ->and($e->replace['candidates'])->toContain($existing->ulid),
    );
    expect(Tenant::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(0);

    $site = app(ProvisionSite::class)->handle($data(true));

    expect(Tenant::query()->count())->toBe(2)
        ->and($site->tenant_id)->not->toBe($existing->id);
});

it('leaves no new customer behind when its first website fails to be created', function (): void {
    Event::listen('eloquent.creating: '.Domain::class, function (): never {
        throw new RuntimeException('domain insert failed');
    });

    expect(fn () => app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Fresh',
        newTenantName: 'Fresh',
        siteName: 'Fresh',
        hostname: 'fresh.test',
        ownerEmail: 'owner@fresh.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    )))->toThrow(RuntimeException::class, 'domain insert failed');

    expect(Tenant::query()->count())->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('explains every refusal in each interface language', function (string $locale): void {
    // Read the file itself: __() falls back to English, so a key missing from vi.json would still translate.
    $lines = json_decode(Cast::string(file_get_contents(lang_path($locale.'.json'))), true);
    $keys = is_array($lines) ? array_keys($lines) : [];
    $reasons = ['required', 'both', 'not_found', 'ambiguous', 'did_you_mean', 'name_too_long', 'name_exists', 'mismatch', 'host_taken', 'rerun_needs_tenant'];

    expect($keys)->toContain('provisioning.host_invalid', ...array_map(fn (string $r): string => 'provisioning.tenant_'.$r, $reasons));
})->with(['en', 'vi', 'ja', 'zh']);
