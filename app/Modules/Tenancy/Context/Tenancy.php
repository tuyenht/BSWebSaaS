<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use Closure;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The single audited entry point for entering a tenant context. Used by HTTP
 * middleware, queue jobs and console commands alike — so every entry point gets
 * identical isolation (design.md §5, 4 defense layers).
 *
 * One DB transaction per call → SET LOCAL app.current_site_id (via set_config with
 * is_local=true) survives PgBouncer transaction pooling and is cleared when the
 * connection returns to the pool. CurrentSite is always cleared in finally.
 */
final readonly class Tenancy
{
    public function __construct(
        private DatabaseManager $db,
        private CurrentSite $current,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    public function enter(int $siteId, Closure $callback): mixed
    {
        return $this->db->connection()->transaction(function () use ($siteId, $callback) {
            $this->db->connection()->select('select set_config(?, ?, true)', ['app.current_site_id', (string) $siteId]);
            $this->current->setId($siteId);

            $this->assertContext($siteId);

            try {
                return $callback();
            } finally {
                $this->current->clear();
            }
        });
    }

    /**
     * Assert the DB actually sees the site we resolved (turns a silent cross-tenant
     * bug into a loud failure). strict = always; sample = 1/N; off = skip.
     */
    private function assertContext(int $siteId): void
    {
        $mode = config('tenancy.assert', 'strict');

        if ($mode === 'off') {
            return;
        }

        if ($mode === 'sample') {
            $rateConfig = config('tenancy.sample_rate', 100);
            $rate = is_numeric($rateConfig) ? (int) $rateConfig : 100;
            if ($rate > 1 && mt_rand(1, $rate) !== 1) {
                return;
            }
        }

        $guc = $this->db->connection()->scalar("select current_setting('app.current_site_id', true)");

        if ($guc !== (string) $siteId) {
            $shown = is_scalar($guc) ? (string) $guc : 'null';

            throw new RuntimeException("Tenant isolation assertion failed: DB site_id '{$shown}' != resolved '{$siteId}'.");
        }
    }
}
