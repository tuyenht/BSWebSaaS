<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Jobs\SendErrorNotice;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
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

    $admin->table('tenants')->insert([
        'ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $admin->table('sites')->insert([
        ['ulid' => 'SITEA000000000000000000001', 'tenant_id' => 1, 'name' => 'A', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => 'SITEB000000000000000000001', 'tenant_id' => 1, 'name' => 'B', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
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

/** The permanent media prefix code the database issued to a site (read on the privileged connection). */
function siteCode(int $siteId): string
{
    $code = DB::connection('pgsql_admin')->table('sites')->where('id', $siteId)->value('code');

    return is_string($code) ? $code : '';
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
