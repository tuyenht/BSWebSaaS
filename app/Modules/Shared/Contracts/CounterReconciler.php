<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Recounts some of a website's running totals (site_counters) from the module's own rows, for the
 * nightly `counters:reconcile`. Lives in Shared\Contracts so Tenancy, which owns the counters and
 * the command, recounts every module's totals without depending on any module: each module
 * implements it for the metrics it writes and tags the class with TAG in its service provider.
 */
interface CounterReconciler
{
    /** The container tag every implementation is registered under. */
    public const string TAG = 'site-counter-reconcilers';

    /**
     * The metrics this reconciler recounts (`media.bytes`…), for subject 0 (the whole website).
     *
     * @return list<string>
     */
    public function metrics(): array;

    /**
     * The true value of each of metrics() for the current website, counted from the module's own
     * rows $chunk at a time (keyset), inside the caller's tenant context and transaction.
     *
     * @return array<string, int>
     */
    public function count(int $chunk): array;
}
