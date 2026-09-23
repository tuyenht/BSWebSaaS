<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PDO;

abstract class TestCase extends BaseTestCase
{
    /** Postgres advisory-lock key serializing Pest runs on the shared test database. */
    public const int SUITE_LOCK_KEY = 7_020_923;

    /** Inherited by child processes (mutation/parallel workers): the parent already holds the lock. */
    private const string LOCK_HELD_ENV = 'BSWEB_TEST_DB_LOCK_HELD';

    /** The connection holding the suite lock — kept for the life of the process, released on exit. */
    private static ?PDO $suiteLock = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::holdSharedDatabaseLock();

        // Admin views use @vite; under test there is no asset build (the pest CI job does
        // not run vite), so stub Vite to render nothing instead of throwing on a missing
        // manifest. Class/text assertions are unaffected (they read the Blade markup).
        $this->withoutVite();

        // The array cache store is a singleton that survives between tests, so RateLimiter
        // counters (login brute-force, OTP send caps, presign throttle) would accumulate across
        // the suite and trip on shared keys (e.g. per-IP). Flush per test to keep them isolated.
        Cache::flush();
    }

    /**
     * The suite runs on a shared, never-rolled-back database whose helpers TRUNCATE … CASCADE, so
     * two Pest runs at once (two sessions on one dev box) wipe each other's rows mid-test. Take a
     * session-level advisory lock before the first test and keep it until this process exits: a
     * second run waits here instead of corrupting the first. It uses its own PDO on the direct
     * pgsql_admin connection — a session lock through PgBouncer transaction mode would be lost
     * the moment the pooled server connection is handed to someone else — and Laravel's own
     * connections are rebuilt per test, which would release it.
     */
    private static function holdSharedDatabaseLock(): void
    {
        if (self::$suiteLock !== null || getenv(self::LOCK_HELD_ENV) === '1') {
            return;
        }

        /** @var array{host: string, port: string|int, database: string, username: string, password: string} $config */
        $config = config('database.connections.pgsql_admin');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $acquired = $pdo->query(sprintf('select pg_try_advisory_lock(%d)::int', self::SUITE_LOCK_KEY));
        if ($acquired === false || (int) $acquired->fetchColumn() !== 1) {
            fwrite(STDERR, PHP_EOL.'Another Pest run is using the shared test database — waiting for it to finish…'.PHP_EOL);
            $pdo->query(sprintf('select pg_advisory_lock(%d)', self::SUITE_LOCK_KEY));
        }

        self::$suiteLock = $pdo;
        putenv(self::LOCK_HELD_ENV.'=1');
    }
}
