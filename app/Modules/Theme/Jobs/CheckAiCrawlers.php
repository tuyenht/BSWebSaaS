<?php

declare(strict_types=1);

namespace App\Modules\Theme\Jobs;

use App\Modules\Theme\Theming\AiCrawlerCheck;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Runs the AI crawler check for one site off the request (the screen never waits), on the default
 * queue. The platform-wide limit (LIMITER, registered by ThemeServiceProvider) comes first: a check
 * over it goes back to the queue. The site is read in a short tenant step of its own
 * (AiCrawlerCheck::run enters and commits it), never around the outbound requests — so this job
 * takes no WithinSite middleware, which would hold one transaction across them. One real failure
 * ends it; the owner can check again from the screen.
 */
final class CheckAiCrawlers implements ShouldQueue
{
    use Queueable;

    public const string LIMITER = 'seo-ai-check';

    public int $maxExceptions = 1;

    /** Six requests of at most five seconds each, with room to spare. */
    public int $timeout = 60;

    /** A check that runs out of time has failed — it is not put back to run again. */
    public bool $failOnTimeout = true;

    /**
     * Queued once the request's transaction commits: never inside another site's (or this one's)
     * open tenant transaction, whatever queue connection runs it.
     */
    public function __construct(public int $siteId)
    {
        $this->afterCommit();
    }

    /** Waiting behind the platform-wide limit is not failing: retry until then. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited(self::LIMITER)];
    }

    public function handle(AiCrawlerCheck $check): void
    {
        $check->run($this->siteId);
    }

    public function failed(?Throwable $e): void
    {
        app(AiCrawlerCheck::class)->clearRunning($this->siteId);
    }
}
