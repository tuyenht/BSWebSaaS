<?php

declare(strict_types=1);

use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use Illuminate\Support\Facades\Log;

/*
 * The superuser connection exists for migrations. In production its password belongs only to the
 * one-off migrate container, so code running in the app, the queue or the scheduler cannot rewrite
 * the audit log or switch off row-level security. A process that still loads it gets a critical log
 * line on boot; it keeps serving.
 */

function adminCredentials(string $env, ?string $password, ?string $url = null, bool $privilegedOps = false): TenancyServiceProvider
{
    app()['env'] = $env;
    config([
        'database.connections.pgsql_admin.password' => $password,
        'database.connections.pgsql_admin.url' => $url,
        'tenancy.privileged_ops' => $privilegedOps,
    ]);
    Log::spy();

    $provider = app()->getProvider(TenancyServiceProvider::class);
    if (! $provider instanceof TenancyServiceProvider) {
        throw new RuntimeException('TenancyServiceProvider is not registered.');
    }

    return $provider;
}

function checkAdminCredentials(string $env, ?string $password, ?string $url = null, bool $privilegedOps = false): void
{
    adminCredentials($env, $password, $url, $privilegedOps)->warnWhenAdminCredentialsLoaded();
}

it('runs the check every time the app boots', function (): void {
    adminCredentials('production', 'secret')->boot();

    Log::shouldHaveReceived('critical')->once();
});

it('warns when a production app process holds the superuser password', function (): void {
    checkAdminCredentials('production', 'secret');

    Log::shouldHaveReceived('critical')->once();
});

it('warns when the superuser password arrives inside the connection url', function (): void {
    checkAdminCredentials('production', '', 'pgsql://admin:secret@postgres:5432/app');

    Log::shouldHaveReceived('critical')->once();
});

it('stays quiet in production once the password is gone', function (?string $url): void {
    checkAdminCredentials('production', '', $url);

    Log::shouldNotHaveReceived('critical');
})->with([
    'no url' => [null],
    'url without a password' => ['pgsql://admin@postgres:5432/app'],
]);

it('stays quiet in the migrate container, which needs the password', function (): void {
    checkAdminCredentials('production', 'secret', privilegedOps: true);

    Log::shouldNotHaveReceived('critical');
});

it('stays quiet outside production, where tests and the hammer need the password', function (): void {
    checkAdminCredentials('local', 'secret');

    Log::shouldNotHaveReceived('critical');
});
