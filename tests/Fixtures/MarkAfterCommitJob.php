<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A job queued to run only once its transaction commits (the sync queue holds it until then): it just
 * leaves a mark in the cache, so a test can tell whether after-commit work ran.
 */
final class MarkAfterCommitJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $mark)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        Cache::put('after-commit-job:'.$this->mark, true);
    }
}
