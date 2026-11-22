<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Octane\Events\WorkerStopping as OctaneWorkerStopping;
use Spatie\Permission\PermissionRegistrar;

/**
 * spatie's registrar, made coherent across long-lived workers (Octane, queue workers).
 *
 * The package keeps the loaded permission catalog (which role grants which permission) in the
 * worker's memory for the worker's whole life, and its flush only reaches the worker that made the
 * change. So a permission taken away from a role kept working on every other worker until that
 * worker recycled. Here a small version token in the shared cache names the current catalog:
 *
 *  - every change spatie already flushes for (a role or permission saved or deleted, permissions
 *    given to or taken from a role, `rbac:sync`, `permission:cache-reset`, a migration that calls
 *    the flush) writes a new token once its transaction commits. Until then the shared catalog
 *    stays where it is, so the other workers keep reading it instead of all rebuilding a catalog
 *    that is about to be replaced. A rollback writes no token: nothing it touched reached the
 *    shared cache (a commit that was sent but whose answer was lost still writes one);
 *  - until the transaction ends, the worker that made the change keeps any catalog it loads to
 *    itself: one read inside the transaction sees rows nobody else may see yet, and must never
 *    reach the shared key (the process may also die before the transaction ends, and then nothing
 *    would replace it);
 *  - at the start of each request, task, tick or job a worker reads the token (one cache read) and
 *    drops its loaded catalog only when the token moved, or when the token cannot be read;
 *  - the catalog is stored under a key that carries the token, so a worker that built it from rows
 *    read before the change committed can only write that stale copy under the old key, which no
 *    one reads any more;
 *  - when the token moves, one worker rebuilds the shared catalog while the others wait for it
 *    (a lock, and a small marker key the waiters poll instead of fetching the catalog), instead of
 *    every worker querying the whole catalog at once;
 *  - a new token that cannot be written (the cache store fails) is tried again once after a short
 *    pause, then logged, and the call returns false (`rbac:sync` then fails). A worker that serves
 *    requests or jobs then loads the catalog from the database for itself and tries again at each
 *    next unit of work and when it stops; a one-shot process (artisan, migrate) cannot, so its
 *    caller must. Until the token is written, every other worker keeps the catalog from before the
 *    change; if it never is, that lasts until the next change or the shared catalog's expiry.
 *
 * A member's own roles are not in the catalog: they are read from the database on each request, so
 * giving or taking a role needs no new token.
 *
 * The token, the key it names and the private store are worker-wide cache state with the same life
 * as the catalog the package keeps here, never request state; the next unit of work starts from
 * the shared store again.
 */
class VersionedPermissionRegistrar extends PermissionRegistrar
{
    /**
     * How long the worker rebuilding the shared catalog may hold the lock before it expires. The
     * whole catalog (every site's roles) took about 1.5 s to build for 200 sites, growing with the
     * number of sites; this leaves room for a few thousand.
     */
    private const int REBUILD_LOCK_SECONDS = 30;

    /** Between two looks at whether another worker finished the rebuild. */
    private const int REBUILD_POLL_MILLISECONDS = 50;

    /**
     * Looks before a waiting worker builds its own copy: 200 × 50 ms = 10 s, a few times a measured
     * build and well inside a request's time limit (Octane's max_execution_time, 30 s), because the
     * waiter sits inside its request transaction meanwhile. A builder that died frees the lock when
     * it expires (REBUILD_LOCK_SECONDS); a waiter that is still polling then takes it over.
     */
    private const int REBUILD_MAX_POLLS = 200;

    /** Between the two attempts to write a new token: a store that blinked for a moment is back. */
    private const int VERSION_RETRY_MILLISECONDS = 150;

    /** At most one warning per worker this often while the token cannot be read. */
    private const int UNKNOWN_VERSION_WARN_SECONDS = 60;

    /** The catalog key from config, without a token. */
    private string $baseKey = '';

    /** The store every worker shares (the package's $cache points elsewhere during a change). */
    private Repository $shared;

    /** The token this worker's catalog key was taken from. */
    private string $version = '';

    /** Has this worker read the token since it booted or last dropped its catalog? */
    private bool $knowsVersion = false;

    /** Is a new token already queued for the end of the open transaction? */
    private bool $publishQueued = false;

    /** Did a change commit that the other workers have not been told about (the token write failed)? */
    private bool $publishOwed = false;

    /** The connection whose open transaction holds the change a new token is queued for. */
    private ?string $changeConnection = null;

    /** Was that transaction's commit sent to the database (its answer may have been lost)? */
    private bool $commitAttempted = false;

    /** Is this registrar listening for commits being sent? */
    private bool $watchesCommits = false;

    /** Is this registrar listening for its worker stopping (to write a token it still owes)? */
    private bool $watchesStop = false;

    /** Does this process take units of work (an Octane or queue worker), unlike a one-shot command? */
    private bool $servesUnitsOfWork = false;

    /** When this worker last warned that the token could not be read (Unix seconds; 0 = never). */
    private int $unknownVersionWarnedAt = 0;

    #[\Override]
    public function initializeCache(): void
    {
        parent::initializeCache();

        $this->baseKey = $this->cacheKey;
        $this->shared = $this->cache;
        $this->knowsVersion = false;
        $this->publishQueued = false;
        $this->publishOwed = false;
    }

    /** The cache key holding the current token. */
    public function versionKey(): string
    {
        return $this->baseKey.'.version';
    }

    /**
     * Call when the worker takes its next unit of work: one cache read, and the loaded catalog is
     * dropped only when another worker or process changed roles or permissions since.
     */
    public function syncWithSharedCache(): void
    {
        $this->servesUnitsOfWork = true;

        if (($this->publishQueued || $this->publishOwed) && DB::transactionLevel() === 0) {
            // A change whose transaction ended without telling us (the connection's count was reset
            // and no callback ran: the commit may have reached the database), or whose new token
            // could not be written: name a new catalog now, so no worker keeps one lacking it.
            $this->publishNewVersion();
        }

        $current = $this->readVersion();

        if ($current !== '' && $this->knowsVersion && $current === $this->version) {
            return;
        }

        $this->clearPermissionsCollection();
        $this->adopt($current);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<array-key, Model>
     */
    #[\Override]
    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        // A process that never went through syncWithSharedCache (an artisan command, a test) reads
        // the token once before it first loads the catalog.
        if (! $this->knowsVersion && $this->permissions === null) {
            $this->adopt($this->readVersion());
        }

        if ($this->permissions === null && $this->cache === $this->shared) {
            return $this->loadSharedCatalog(fn (): Collection => parent::getPermissions($params, $onlyOne));
        }

        return parent::getPermissions($params, $onlyOne);
    }

    #[\Override]
    public function forgetCachedPermissions(): bool
    {
        // What the package's flush does in memory. The shared catalog is left in place: the new
        // token replaces it once the change commits, and until then it is still the right one.
        $this->clearPermissionsCollection();

        if (DB::transactionLevel() === 0) {
            $this->publishQueued = false;   // a transaction that queued one is over, whichever way

            return $this->publishNewVersion();
        }

        // Until the transaction ends, what this worker loads may hold uncommitted rows: keep it here.
        $this->cache = new CacheRepository(new ArrayStore);

        if (! $this->publishQueued) {
            $this->publishQueued = true;
            // afterCommit/afterRollBack attach to the newest open transaction of ANY connection,
            // while the level check above and this name use the default connection. Only the
            // Tenancy request transaction on the default connection is expected to hold an RBAC
            // change; a change inside a transaction on a second connection would publish before the
            // outer commit, and a catalog rebuilt from pre-commit rows would then sit under the new
            // token.
            $this->changeConnection = DB::connection()->getName();
            $this->commitAttempted = false;
            $this->watchCommits();
            DB::afterCommit(fn (): bool => $this->publishNewVersion());
            DB::afterRollBack(fn () => $this->discardPrivateCatalog());
        }

        return true;
    }

    /**
     * Load the catalog from the shared store, rebuilding it there when it is missing. One worker
     * rebuilds (under a lock named by the token) while the others wait and then read its copy; a
     * worker that waited too long, or a store without locks, builds its own.
     *
     * @param  Closure(): Collection<array-key, Model>  $load
     * @return Collection<array-key, Model>
     */
    private function loadSharedCatalog(Closure $load): Collection
    {
        $store = $this->shared->getStore();

        if (! $store instanceof LockProvider || $this->sharedCatalogBuilt()) {
            return $load();
        }

        $lock = $store->lock($this->cacheKey.'.rebuild', self::REBUILD_LOCK_SECONDS);

        for ($polls = 0; ! $lock->get(); $polls++) {
            if ($polls >= self::REBUILD_MAX_POLLS) {
                return $this->markBuilt($load());
            }

            Sleep::usleep(self::REBUILD_POLL_MILLISECONDS * 1000);

            if ($this->sharedCatalogBuilt()) {
                return $load();
            }
        }

        try {
            // the package's remember() reads first: a rebuild may have just ended
            return $this->markBuilt($load());
        } finally {
            $lock->release();
        }
    }

    /**
     * Is the catalog for this worker's token in the shared store? Read from a small marker key
     * written next to it, so asking never fetches the catalog itself. Another worker may write it
     * between two calls. (A catalog evicted before its marker is rebuilt without the lock.)
     *
     * @phpstan-impure
     */
    private function sharedCatalogBuilt(): bool
    {
        return $this->shared->has($this->builtKey($this->cacheKey));
    }

    /**
     * @param  Collection<array-key, Model>  $loaded
     * @return Collection<array-key, Model>
     */
    private function markBuilt(Collection $loaded): Collection
    {
        $this->shared->put($this->builtKey($this->cacheKey), true, $this->cacheExpirationTime);

        return $loaded;
    }

    private function builtKey(string $catalogKey): string
    {
        return $catalogKey.'.built';
    }

    /**
     * The transaction that queued a new token ended as a rollback: nothing of it reached the shared
     * cache, so no token is written. Laravel also reports a failed COMMIT this way (at once inside
     * DB::transaction(), or at the next disconnect/reconnect after a manual commit()); a commit
     * that was sent may have landed when only its answer was lost, so then a token is written.
     */
    private function discardPrivateCatalog(): void
    {
        if ($this->commitAttempted || $this->publishOwed) {
            $this->publishNewVersion();

            return;
        }

        $this->publishQueued = false;

        $this->cache = $this->shared;
        $this->clearPermissionsCollection();
    }

    /**
     * Write a new token, so every worker drops its catalog at its next unit of work. Returns false
     * when the cache store would not take it (tried twice; logged; tried again later).
     */
    private function publishNewVersion(): bool
    {
        $this->publishQueued = false;
        $this->commitAttempted = false;
        $this->publishOwed = true;
        $this->clearPermissionsCollection();

        $next = Str::random(16);
        $previous = null;
        $failure = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($attempt > 1) {
                Sleep::usleep(self::VERSION_RETRY_MILLISECONDS * 1000);
            }

            try {
                $previous = $this->shared->get($this->versionKey());

                if ($this->shared->forever($this->versionKey(), $next)) {
                    $failure = null;

                    break;
                }

                $failure = 'the cache store refused the write';
            } catch (\Exception $e) {
                $failure = $e->getMessage();
            }
        }

        if ($failure !== null) {
            Log::error('Permission change saved, but the new permission-cache version could not be written: every worker, a restarted or new one too, keeps the permissions from before the change until this worker writes it (at its next request or job, or when it stops); if it never does, until the next role or permission change or until the shared permission catalog expires.', [
                'key' => $this->versionKey(),
                'error' => $failure,
            ]);

            // This worker loads from the database for itself until the token is written; a worker
            // that has nothing loaded yet should not find the old catalog either.
            $this->forgetSharedCatalogs(is_string($previous) ? $previous : null);
            $this->cache = new CacheRepository(new ArrayStore);
            $this->knowsVersion = false;
            if ($this->servesUnitsOfWork) {
                $this->retryWhenStopping();
            }

            return false;
        }

        $this->publishOwed = false;
        $this->cache = $this->shared;

        // Nobody reads the previous catalogs any more: free them now rather than at their expiry.
        $this->forgetSharedCatalogs(is_string($previous) ? $previous : null);
        $this->adopt($next);

        return true;
    }

    /** Note when the transaction holding the queued change sends its commit (once per registrar). */
    private function watchCommits(): void
    {
        if ($this->watchesCommits) {
            return;
        }

        $this->watchesCommits = true;
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            if ($this->publishQueued && $event->connectionName === $this->changeConnection) {
                $this->commitAttempted = true;
            }
        });
    }

    /** A worker about to stop writes the token it still owes (once per registrar). */
    private function retryWhenStopping(): void
    {
        if ($this->watchesStop) {
            return;
        }

        $this->watchesStop = true;
        Event::listen([WorkerStopping::class, OctaneWorkerStopping::class], function (): void {
            if ($this->publishOwed) {
                $this->publishNewVersion();
            }
        });
    }

    /** Best effort: a catalog left behind only costs memory until it expires. */
    private function forgetSharedCatalogs(?string $previous): void
    {
        try {
            $versions = array_unique(array_filter([$previous, $this->knowsVersion ? $this->version : null]));
            foreach ($versions as $version) {
                $this->shared->forget($this->keyFor($version));
                $this->shared->forget($this->builtKey($this->keyFor($version)));
            }
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * The current token; written first when there is none (first boot, or the key was evicted).
     * '' when the store gives none back even then: unknown, never a catalog to share.
     */
    private function readVersion(): string
    {
        $version = $this->shared->get($this->versionKey());

        if (! is_string($version) || $version === '') {
            $this->shared->add($this->versionKey(), Str::random(16));
            $version = $this->shared->get($this->versionKey());
        }

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $this->warnUnknownVersion();

        return '';
    }

    /**
     * The store answers but keeps no token: every unit of work that checks a permission now loads
     * the whole catalog from the database (fail closed). Said once a minute per worker, not per load.
     */
    private function warnUnknownVersion(): void
    {
        $now = now()->getTimestamp();

        if ($this->unknownVersionWarnedAt !== 0 && $now - $this->unknownVersionWarnedAt < self::UNKNOWN_VERSION_WARN_SECONDS) {
            return;
        }

        $this->unknownVersionWarnedAt = $now;
        Log::warning('The permission-cache version cannot be read from the cache store: this worker loads the permission catalog from the database for every request or job until it can.', [
            'key' => $this->versionKey(),
        ]);
    }

    /**
     * Take $version as the name of this worker's catalog. An unknown one ('') keeps whatever this
     * worker loads to itself, and the token is read again before the next load.
     */
    private function adopt(string $version): void
    {
        $this->version = $version;
        $this->knowsVersion = $version !== '';
        $this->cacheKey = $this->keyFor($version);

        if ($this->publishQueued || $this->publishOwed) {
            return;   // the private store stays until the new token is written
        }

        // unknown: a store of its own each time, so nothing loaded under an earlier unknown is reused
        $this->cache = $this->knowsVersion ? $this->shared : new CacheRepository(new ArrayStore);
    }

    private function keyFor(string $version): string
    {
        return $this->baseKey.'.'.$version;
    }
}
