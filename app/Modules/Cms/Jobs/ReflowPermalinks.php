<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Actions\Permalinks\ChangeSitePermalinks;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The background half of a change of address format («Đường dẫn»): moves the routes of every item
 * in the changed sections to the address the staged preset gives (ReflowSitePermalinks::chunk —
 * PathBuilder composes, RouteSync moves and leaves a 301 from the old address, a clash takes «-2»).
 *
 * Phases:
 *   1. move   — chunk after chunk (500 items), each in its own Tenancy::enter (an explicit
 *               transaction with SET LOCAL app.current_site_id) under the site's permalink lock,
 *               composing the staged sections from their pending preset. When every kind is done,
 *               the pending presets become the live ones (PermalinkConfig::promote) in one more
 *               transaction.
 *   2. settle — the same sections walked once more on the live preset: an item saved during the
 *               move composed its address from the old preset and is put right here.
 * A run works RUN_SECONDS and hands over to a fresh one carrying its cursor, so no job holds a
 * worker long. Every step skips what is already where it belongs, so a failed or repeated run picks
 * up where it stopped (the retry runs from its cursor; ChangeSitePermalinks::resumeIfStale() starts
 * one again when a run was lost). A move phase that finds nothing staged has nothing to do.
 */
final class ReflowPermalinks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string MOVE = 'move';

    public const string SETTLE = 'settle';

    /** Seconds one run works before it hands over to a fresh run. */
    public const int RUN_SECONDS = 30;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  list<string>  $sections  the sections of the settle phase (the move phase reads the staged ones)
     * @param  int  $kind  index into ReflowSitePermalinks::kinds()
     * @param  int  $after  the last item id done in that kind
     */
    public function __construct(
        public int $siteId,
        public string $phase = self::MOVE,
        public array $sections = [],
        public int $kind = 0,
        public int $after = 0,
        public int $size = ReflowSitePermalinks::CHUNK,
        public int $seconds = self::RUN_SECONDS,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'permalinks:'.$this->siteId;
    }

    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }
    }

    /** True when the run finished; false when it handed over to a next run. */
    public function handle(Tenancy $tenancy, ReflowSitePermalinks $engine, PermalinkConfig $permalinks): bool
    {
        $stopAt = microtime(true) + $this->seconds;
        $kinds = $engine->kinds();
        [$phase, $sections, $kind, $after] = [$this->phase, $this->sections, $this->kind, $this->after];

        while (true) {
            /** @var array{done: bool, sections: list<string>, last: int|null, promoted: bool} $step */
            $step = $tenancy->enter($this->siteId, function () use ($engine, $permalinks, $kinds, $phase, $sections, $kind, $after): array {
                ChangeSitePermalinks::lock($this->siteId);
                $targets = [];
                if ($phase === self::MOVE) {
                    $targets = $permalinks->pending();
                    if ($targets === []) {
                        return ['done' => true, 'sections' => [], 'last' => null, 'promoted' => false];
                    }
                    $sections = array_keys($targets);
                }
                if ($kind >= count($kinds)) {
                    if ($phase === self::MOVE) {
                        $permalinks->promote();

                        return ['done' => false, 'sections' => $sections, 'last' => null, 'promoted' => true];
                    }

                    return ['done' => true, 'sections' => $sections, 'last' => null, 'promoted' => false];
                }
                $chunk = $engine->chunk($kinds[$kind], $sections, $targets, $after, $this->size, suffix: true);

                return ['done' => false, 'sections' => $sections, 'last' => $chunk['last'], 'promoted' => false];
            });

            if ($step['done']) {
                return true;
            }
            $sections = $step['sections'];
            if ($step['promoted']) {
                [$phase, $kind, $after] = [self::SETTLE, 0, 0];
            } elseif ($step['last'] === null) {
                [$kind, $after] = [$kind + 1, 0];
            } else {
                $after = $step['last'];
            }

            if (microtime(true) >= $stopAt) {
                self::dispatch($this->siteId, $phase, $sections, $kind, $after, $this->size, $this->seconds);

                return false;
            }
        }
    }
}
