<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Jobs\SendErrorNotice;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Shared\Support\SiteSearchWords;
use App\Modules\Storefront\EdgeCache\EdgeCachePurges;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Arch', 'Concurrency');

/*
|--------------------------------------------------------------------------
| Query Budget helper (CLAUDE.md §6)
|--------------------------------------------------------------------------
| Counts the DB queries executed during a callback so Query Budget tests
| can assert a hard ceiling per hot route and catch N+1 regressions.
*/
function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

/*
|--------------------------------------------------------------------------
| Category totals helper
|--------------------------------------------------------------------------
| The categories of $siteId whose stored items_count differs from a fresh count of their posts
| and products: taxonomy id → [stored, counted]. Empty when every write path kept the totals.
| Call OUTSIDE any site context (it enters the site itself).
*/
function categoryCountDrift(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, function (): array {
        $stored = [];
        foreach (Taxonomy::query()->get(['id', 'items_count']) as $taxonomy) {
            $stored[$taxonomy->id] = $taxonomy->items_count;
        }
        $counted = app(CategoryItemCounts::class)->actual(array_keys($stored));

        $drift = [];
        foreach ($stored as $id => $count) {
            if (($counted[$id] ?? 0) !== $count) {
                $drift[$id] = [$count, $counted[$id] ?? 0];
            }
        }

        return $drift;
    });
}

/*
|--------------------------------------------------------------------------
| Tenancy seed helper (Gate #1)
|--------------------------------------------------------------------------
| Seeds one tenant + sites A(1)/B(2) + domains a.test/b.test via the PRIVILEGED
| admin connection (so RLS does not block setup). Assertions then run on the
| default app_user connection.
*/
function seedTenancy(): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_probes, domains, sites, tenants restart identity cascade');
    // A removed website's code stays reserved (never issued again); truncate removes none, but a test
    // that deleted site A or B would otherwise keep its code from the next seed.
    $admin->table('reserved_site_codes')->where('reason', 'deleted_site')->delete();
    // Old media folders owned by a website: truncate fires no delete trigger, and the ids restart.
    $admin->table('reserved_site_codes')->where('reason', 'legacy_key')->whereNotNull('site_id')->delete();

    $admin->table('tenants')->insert([
        'ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $admin->table('sites')->insert([
        ['ulid' => 'SITEA000000000000000000001', 'tenant_id' => 1, 'code' => 'aaaaaa', 'name' => 'A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => 'SITEB000000000000000000001', 'tenant_id' => 1, 'code' => 'bbbbbb', 'name' => 'B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $admin->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'a.test', 'kind' => 'subdomain', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'hostname' => 'b.test', 'kind' => 'subdomain', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    // Default-locale source of truth (site_locales.is_default): one vi default per site, so
    // SiteLocale::default() resolves without hitting the fallback path.
    $admin->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'vi', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'locale' => 'vi', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/** The permanent code of a site, the first folder of its media keys (read on the privileged connection). */
function siteCode(int $siteId): string
{
    $code = DB::connection('pgsql_admin')->table('sites')->where('id', $siteId)->value('code');

    return is_string($code) ? $code : '';
}

/**
 * Storage keys that are not clean relative paths ({own}/{other} = the codes of sites 1/2). Storage
 * rewrites several of them to a path under site 2's code, so the database and the application must
 * both refuse every one, whatever its first segment looks like.
 *
 * @return array<string, string>
 */
function uncleanMediaKeys(): array
{
    return [
        'dot segment before another code' => './{other}/anh-abcd.png',
        'backslash after another code' => '{other}\anh-abcd.png',
        'parent segment' => 'a/../{other}/anh-abcd.png',
        'parent segment after own code' => '{own}/../{other}/anh-abcd.png',
        'dot segment' => '{own}/./anh-abcd.png',
        'empty segment' => '{own}//anh-abcd.png',
        'leading slash' => '/{own}/anh-abcd.png',
        'trailing slash' => '{own}/anh-abcd.png/',
        'bare code' => '{own}/',
        'single segment' => 'anh-abcd.png',
        'staging' => '_incoming/a/01abc.png',
        'empty' => '',
        'leading space' => ' {own}/anh-abcd.png',
        'final newline' => "{own}/anh-abcd.png\n",
        'tab' => "{own}/anh\tabcd.png",
        'escape character' => "{own}/anh\x1babcd.png",
        'non-ascii letter' => '{own}/ảnh-abcd.png',
    ];
}

/** $key with {own}/{other} replaced by the codes of sites 1 and 2. */
function withSiteCodes(string $key): string
{
    return str_replace(['{own}', '{other}'], [siteCode(1), siteCode(2)], $key);
}

/**
 * Give a seeded site the catalog module (its plan has products): the products list and the product
 * editor show their plan card and refuse every write otherwise.
 */
function enableCatalog(int $siteId = 1): void
{
    DB::connection('pgsql_admin')->table('site_modules')->insertOrIgnore([
        ['site_id' => $siteId, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/*
|--------------------------------------------------------------------------
| RBAC reset helper (P0.3)
|--------------------------------------------------------------------------
| Clears the (global, non-RLS) authz tables + spatie cache + team-context
| override so each RBAC test starts from a deterministic, leak-free state.
*/
function truncateRbac(): void
{
    DB::connection('pgsql_admin')->statement(
        'truncate roles, permissions, model_has_roles, model_has_permissions, role_has_permissions restart identity cascade'
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(TeamContext::class)->forget();
}

/*
|--------------------------------------------------------------------------
| Tenant role grant helper (P0.3 / W3)
|--------------------------------------------------------------------------
| Create a tenant role carrying the given permissions in the CURRENT site context
| and assign it to the user (mirrors how ProvisionSite instantiates role templates).
| Call inside Tenancy::enter so the spatie team (= site_id) + RLS are the right site.
*/

/**
 * @param  list<string>  $permissions
 */
function grantRole(User $user, string $name, array $permissions): void
{
    $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
    $role->syncPermissions($permissions);
    $user->assignRole($role);
}

/**
 * A new user who may manage $site's menus. Call OUTSIDE any site context: it enters the site
 * itself (an inner Tenancy::enter would clear the outer one's site when it returns).
 */
function menuEditor(int $site): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('menus.manage', PermissionRegistry::GUARD);
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'menu-editor', ['menus.manage']));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/**
 * A new member of $site holding the corporate role template $template (or exactly $permissions
 * when given). Call OUTSIDE any site context.
 *
 * @param  list<string>|null  $permissions
 */
function blockLibraryUser(int $site, string $template, ?array $permissions = null): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$template]['permissions'] ?? [];
    $permissions ??= $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec;
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $template, $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/*
|--------------------------------------------------------------------------
| Storefront content seed helper (P1.3)
|--------------------------------------------------------------------------
| Seeds one published `page` (Page-JSON hero) + its home route inside the given
| site's context, so storefront render tests have real content to resolve.
*/
function seedHomepage(int $site, string $title = 'Home', string $path = '/'): void
{
    app(Tenancy::class)->enter($site, function () use ($title, $path): void {
        $entry = Entry::query()->create([
            'type' => 'page',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $entry->translations()->create([
            'locale' => 'vi',
            'title' => $title,
            'slug' => 'home',
            'status' => 'published',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $title]]]],
        ]);

        SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);
    });
}

/*
|--------------------------------------------------------------------------
| Structured data of a storefront page
|--------------------------------------------------------------------------
| A page prints exactly one `application/ld+json` script holding one `@graph`. These return its
| nodes in order (a lone object counts as one node); a page with a second script, or a script that
| is not valid JSON, fails the test.
*/

/** @return list<array<string, mixed>> */
function ldNodes(string $html): array
{
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $found);
    expect(count($found[1]))->toBeLessThanOrEqual(1, 'A page prints one JSON-LD script');
    if ($found[1] === []) {
        return [];
    }
    $data = json_decode($found[1][0], true, 512, JSON_THROW_ON_ERROR);

    return array_values(is_array($data['@graph'] ?? null) ? $data['@graph'] : [$data]);
}

/** The first node of $type in the page's structured data, or null. @return array<string, mixed>|null */
function ldNode(string $html, string $type): ?array
{
    foreach (ldNodes($html) as $node) {
        if (($node['@type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Breach-check (HIBP) test double
|--------------------------------------------------------------------------
| Password::defaults() carries ->uncompromised(), which calls the HaveIBeenPwned
| range API. Swap the verifier so password tests never touch the network: default
| treats the password as safe; pass true to simulate a breached password.
*/
function fakePwnedVerifier(bool $compromised = false): void
{
    app()->instance(UncompromisedVerifier::class, new class($compromised) implements UncompromisedVerifier
    {
        public function __construct(private bool $compromised) {}

        /**
         * @param  array<string, mixed>  $data
         */
        public function verify($data): bool
        {
            return ! $this->compromised;
        }
    });
}

/*
|--------------------------------------------------------------------------
| Error-notice helpers (Monitoring module)
|--------------------------------------------------------------------------
| Turn the notices on for one test: a recipient, a sender on the `monitoring` mailer with the
| array transport (nothing leaves the box), and a Valkey key prefix of its own so the counters of
| one test never meet another's. The notice jobs are held by a queue fake (only those: every other
| job runs as usual) and sendMonitoringNotices() plays the `monitoring` queue's worker. Returns the
| prefix; forgetMonitoringKeys() removes its keys.
*/
function enableMonitoring(): string
{
    $prefix = 'monitoring-test:'.Str::lower(Str::random(12)).':';

    config([
        'monitoring.to' => 'owner@example.test',
        'monitoring.key_prefix' => $prefix,
        'mail.mailers.monitoring.transport' => 'array',
        'mail.mailers.monitoring.host' => 'smtp.example.test',
        'mail.mailers.monitoring.from' => ['address' => 'alerts@platform.example.test', 'name' => 'Platform alerts'],
    ]);
    Mail::purge('monitoring');
    fakeMonitoringQueue();

    return $prefix;
}

/** Hold the notice jobs, each after the serialize round trip a real queue gives it. */
function fakeMonitoringQueue(): void
{
    Queue::fake([SendErrorNotice::class])->serializeAndRestore();
}

/** @return list<SendErrorNotice> the notice jobs queued since the last send */
function queuedMonitoringNotices(): array
{
    $jobs = [];

    foreach (Queue::pushed(SendErrorNotice::class)->all() as $job) {
        if ($job instanceof SendErrorNotice) {
            $jobs[] = $job;
        }
    }

    return $jobs;
}

/** What the `monitoring` queue's worker does: run each queued notice job once. */
function sendMonitoringNotices(): void
{
    $jobs = queuedMonitoringNotices();
    fakeMonitoringQueue(); // a fresh list: a notice is sent once

    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
    }
}

function forgetMonitoringKeys(string $prefix): void
{
    $connectionPrefix = (string) config('database.redis.options.prefix');

    try {
        $keys = Redis::keys($prefix.'*');
    } catch (Throwable) {
        return; // a test that broke the connection on purpose wrote nothing
    }

    foreach (is_array($keys) ? $keys : [] as $key) {
        Redis::del(Str::after((string) $key, $connectionPrefix));
    }
}

/**
 * The notices the `monitoring` mailer actually handed to its transport.
 *
 * @return list<Email>
 */
function monitoringMails(string $mailer = 'monitoring'): array
{
    $transport = Mail::mailer($mailer)->getSymfonyTransport();

    if (! $transport instanceof ArrayTransport) {
        return [];
    }

    $mails = [];

    foreach ($transport->messages() as $sent) {
        $message = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

        if ($message instanceof Email) {
            $mails[] = $message;
        }
    }

    return $mails;
}

/**
 * Every product of the site has search words, and they are exactly the words of its names and SKU now:
 * `catalog:search-words` finds nothing to correct (the same splitting the write path uses).
 */
function expectProductSearchWordsCurrent(int $siteId): void
{
    $words = (int) DB::connection('pgsql_admin')->table('product_search_words')->where('site_id', $siteId)->count();
    $products = (int) DB::connection('pgsql_admin')->table('products')->where('site_id', $siteId)->count();
    expect($products)->toBeGreaterThan(0)->and($words)->toBeGreaterThanOrEqual($products);

    test()->artisan('catalog:search-words', ['--site' => $siteId])->expectsOutputToContain('corrected 0.')->assertSuccessful();
}

/**
 * The storefront search words (SiteSearchWords) of the site are exactly the words its stored titles,
 * names, codes and short descriptions make now: `search:words` finds nothing to correct, and at least
 * one row exists in each of $tables.
 *
 * @param  list<string>  $tables
 */
function expectSiteSearchWordsCurrent(int $siteId, array $tables = ['entry_search_words', 'product_site_words', 'taxonomy_search_words']): void
{
    foreach ($tables as $table) {
        expect((int) DB::connection('pgsql_admin')->table($table)->where('site_id', $siteId)->count())->toBeGreaterThan(0, $table);
    }

    test()->artisan('search:words', ['--site' => $siteId])->expectsOutputToContain('corrected 0.')->assertSuccessful();
}

/**
 * The stored storefront words of one item: locale => word => weight (every weight 1 for categories).
 *
 * @return array<string, array<string, int>>
 */
function siteWordsOf(string $table, int $siteId, int $itemId): array
{
    return SiteSearchWords::stored($table, $siteId, [$itemId])[$itemId] ?? [];
}

/**
 * Empty the contact button tap buffer (ContactClickCounter) of the two test sites and of site 999
 * (a site that does not exist), and the set of sites with buffered taps. Valkey is shared by every
 * test and outlives a test's tables.
 */
function forgetContactClicks(): void
{
    $keys = ['contact_clicks:dirty'];
    foreach ([1, 2, 999] as $site) {
        array_push($keys, "contact_clicks:{$site}", "contact_clicks:flushing:{$site}", "contact_clicks:applying:{$site}");
    }
    Redis::del(...$keys);
}

/*
|--------------------------------------------------------------------------
| Edge cache recorder
|--------------------------------------------------------------------------
| Records every purge instead of calling an edge (Cloudflare); a.test / b.test sit in its zone.
*/
function edgeCacheRecorder(bool $hostsAccepted = true): object
{
    $fake = new class($hostsAccepted) implements EdgeCache
    {
        /** @var list<array{0: string, 1: list<string>}> */
        public array $calls = [];

        public function __construct(private bool $hostsAccepted) {}

        public function purgeHosts(array $hosts): bool
        {
            $this->calls[] = ['hosts', $hosts];

            return $this->hostsAccepted;
        }

        public function purgeUrls(array $urls): bool
        {
            $this->calls[] = ['urls', $urls];

            return true;
        }
    };
    app()->instance(EdgeCache::class, $fake);
    app()->forgetInstance(EdgeCachePurges::class); // built with the null driver during the seeding
    config(['tenancy.base_domains' => ['test']]);   // a.test / b.test sit in the purged zone

    return $fake;
}

/**
 * The authenticator code for the 30-second step `$steps` away from now (0 = the one the app shows).
 * An app code passes once, so a fixture that turns two-step sign-in on uses the previous step's code
 * (-1), and the current code is still free for the sign-in or check under test.
 */
function totpCode(string $secret, int $steps = 0): string
{
    $engine = new Google2FA;

    return $engine->oathTotp($secret, $engine->getTimestamp() + $steps);
}

/**
 * Whether $code fits one of the steps the app's window accepts now (±2 steps, as TwoFactorService
 * checks), remembering nothing: for fixtures that need a code the service would refuse. The service
 * itself only checks a code by taking it.
 */
function totpFits(string $secret, string $code): bool
{
    return (new Google2FA)->verifyKey($secret, $code, 2) !== false;
}
