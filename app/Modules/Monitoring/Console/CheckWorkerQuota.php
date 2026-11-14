<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Console;

use App\Modules\Monitoring\Support\NoticeMailer;
use App\Modules\Monitoring\Support\WorkerQuotaReader;
use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hourly: mails the platform owner when the Cloudflare Workers free plan (one daily allowance for
 * the whole account, counted per UTC day) is being used up. One mail per threshold per UTC day —
 * a jump past several thresholds sends only the highest. When the number cannot be read the hour
 * is skipped quietly; after monitoring.worker_quota.failure_hours failed hours in a row one mail
 * per day says so. Never fails: a broken check must not break the scheduler.
 */
final class CheckWorkerQuota extends Command
{
    private const TTL_SECONDS = 172_800;

    #[\Override]
    protected $signature = 'monitoring:worker-quota';

    #[\Override]
    protected $description = 'Mail the operator when the Cloudflare Workers daily free allowance is getting used up';

    public function handle(WorkerQuotaReader $reader, NoticeMailer $mailer): int
    {
        if (! NoticeMailer::enabled() || ! WorkerQuotaReader::configured()) {
            if (Cache::add($this->key('off-logged'), 1, 86_400)) {
                Log::info('monitoring: worker quota check is off (notice mailer or Cloudflare token / account id missing)');
            }

            return self::SUCCESS;
        }

        $date = now('UTC')->format('Y-m-d');
        $used = $reader->requestsOn($date);

        if ($used === null) {
            $this->failed($date, $mailer);

            return self::SUCCESS;
        }

        Cache::forget($this->key('failures'));

        $limit = $this->limit();
        $percent = intdiv($used * 100, $limit);
        $crossed = array_values(array_filter($this->thresholds(), fn (int $t): bool => $percent >= $t));

        if ($crossed === []) {
            return self::SUCCESS;
        }

        $highest = max($crossed);
        if (Cache::has($this->key("sent:{$date}:{$highest}"))) {
            return self::SUCCESS;
        }

        // Mark the lower ones too: a later run must not send them after the higher one.
        foreach ($crossed as $threshold) {
            Cache::put($this->key("sent:{$date}:{$threshold}"), 1, self::TTL_SECONDS);
        }

        $reached = $percent >= 100;
        $mailer->sendPlain(
            $mailer->t($reached ? 'monitoring.quota.subject.reached' : 'monitoring.quota.subject.warn', ['percent' => (string) $percent]),
            $mailer->t($reached ? 'monitoring.quota.body.reached' : 'monitoring.quota.body.warn', [
                'used' => $mailer->number($used),
                'limit' => $mailer->number($limit),
                'percent' => (string) $percent,
            ]),
        );

        return self::SUCCESS;
    }

    private function failed(string $date, NoticeMailer $mailer): void
    {
        Log::warning('monitoring: could not read the Workers request count from Cloudflare');

        $failures = Cast::int(Cache::get($this->key('failures'))) + 1;
        Cache::put($this->key('failures'), $failures, self::TTL_SECONDS);

        $needed = Cast::int(config('monitoring.worker_quota.failure_hours'), 6);

        if ($failures >= max(1, $needed) && Cache::add($this->key("failure-mail:{$date}"), 1, self::TTL_SECONDS)) {
            $mailer->sendPlain(
                $mailer->t('monitoring.quota.subject.unreadable'),
                $mailer->t('monitoring.quota.body.unreadable', ['hours' => (string) $failures]),
            );
        }
    }

    private function limit(): int
    {
        return max(1, Cast::int(config('monitoring.worker_quota.daily_limit'), 100_000));
    }

    /** @return list<int> */
    private function thresholds(): array
    {
        $configured = config('monitoring.worker_quota.thresholds');
        $list = is_string($configured) ? explode(',', $configured) : (is_array($configured) ? $configured : []);
        $values = array_values(array_unique(array_filter(
            array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $list),
            fn (int $v): bool => $v > 0,
        )));

        return $values !== [] ? $values : [50, 80, 100];
    }

    private function key(string $suffix): string
    {
        return Cast::string(config('monitoring.key_prefix'), 'monitoring:').'worker-quota:'.$suffix;
    }
}
