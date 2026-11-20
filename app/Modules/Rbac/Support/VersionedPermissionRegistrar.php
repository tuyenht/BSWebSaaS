<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 *    given to or taken from a role, `rbac:sync`, `permission:cache-reset`) writes a new token once
 *    the transaction ends, committed or rolled back (a rollback can leave a catalog built from rows
 *    that never existed);
 *  - until then, the worker that made the change keeps any catalog it loads to itself: one read
 *    inside the transaction sees rows nobody else may see yet, and must never reach the shared key
 *    (the process may also die before the transaction ends, and then nothing would replace it);
 *  - at the start of each request, task, tick or job a worker reads the token (one cache read) and
 *    drops its loaded catalog only when the token moved;
 *  - the catalog is stored under a key that carries the token, so a worker that built it from rows
 *    read before the change committed can only write that stale copy under the old key, which no
 *    one reads any more.
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
    /** The catalog key from config, without a token. */
    private string $baseKey = '';

    /** The store every worker shares (the package's $cache points elsewhere during a change). */
    private Repository $shared;

    /** The token this worker's catalog key was taken from. */
    private string $version = '';

    /** Has this worker read the token at all since it booted or its cache settings were reloaded? */
    private bool $knowsVersion = false;

    /** Is a new token already queued for the end of the open transaction? */
    private bool $publishQueued = false;

    #[\Override]
    public function initializeCache(): void
    {
        parent::initializeCache();

        $this->baseKey = $this->cacheKey;
        $this->shared = $this->cache;
        $this->knowsVersion = false;
        $this->publishQueued = false;
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
        if ($this->cache !== $this->shared && DB::transactionLevel() === 0) {
            // a change whose transaction ended without telling us (a dropped connection): start clean
            $this->cache = $this->shared;
            $this->publishQueued = false;
            $this->clearPermissionsCollection();
            $this->knowsVersion = false;
        }

        $current = $this->readVersion();

        if ($this->knowsVersion && $current === $this->version) {
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
        if (! $this->knowsVersion) {
            $this->adopt($this->readVersion());
        }

        return parent::getPermissions($params, $onlyOne);
    }

    #[\Override]
    public function forgetCachedPermissions(): bool
    {
        $forgotten = parent::forgetCachedPermissions();

        if (DB::transactionLevel() === 0) {
            $this->publishQueued = false;   // a transaction that queued one is over, whichever way
            $this->publishNewVersion();

            return $forgotten;
        }

        // Until the transaction ends, what this worker loads may hold uncommitted rows: keep it here.
        $this->cache = new CacheRepository(new ArrayStore);

        if (! $this->publishQueued) {
            $this->publishQueued = true;
            DB::afterCommit(fn () => $this->publishNewVersion());
            DB::afterRollBack(fn () => $this->publishNewVersion());
        }

        return $forgotten;
    }

    /** Write a new token, so every worker drops its catalog at its next unit of work. */
    private function publishNewVersion(): void
    {
        $this->cache = $this->shared;
        $this->publishQueued = false;

        $previous = $this->readVersion();
        $next = Str::random(16);

        $this->shared->forever($this->versionKey(), $next);

        // Nobody reads the previous catalogs any more: free them now rather than at their expiry.
        $this->shared->forget($this->keyFor($previous));
        if ($this->knowsVersion && $this->version !== $previous) {
            $this->shared->forget($this->keyFor($this->version));
        }

        $this->clearPermissionsCollection();
        $this->adopt($next);
    }

    /** The current token; written first when there is none (first boot, or the key was evicted). */
    private function readVersion(): string
    {
        $version = $this->shared->get($this->versionKey());

        if (! is_string($version)) {
            $this->shared->add($this->versionKey(), Str::random(16));
            $version = $this->shared->get($this->versionKey());
        }

        return is_string($version) ? $version : '';
    }

    private function adopt(string $version): void
    {
        $this->version = $version;
        $this->knowsVersion = true;
        $this->cacheKey = $this->keyFor($version);
    }

    private function keyFor(string $version): string
    {
        return $this->baseKey.'.'.$version;
    }
}
