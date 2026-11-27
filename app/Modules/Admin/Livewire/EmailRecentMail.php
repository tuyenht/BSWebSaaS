<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Models\MailDaily;
use App\Modules\Admin\Models\MailLog;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * «Thư gần đây» — the last card of the Email screen's «Gửi thư» tab: the website's newest mails and
 * whether each left, with the reason in plain words when it did not. Its own component, so a filter
 * click reads only this card again, never the whole settings form.
 *
 * Reads the mail log of the current website (RLS + the BelongsToSite scope) for the days it is kept,
 * newest first, at most ROWS rows; the counts on the filters come from the per-day rollup
 * (mail_daily), never a count of the log. Holds no body or subject: the log stores none. There is no
 * «Thư rác» filter and no «Gửi lại»: the app cannot know a mail landed in spam, and resending would
 * mean keeping each mail's body.
 */
final class EmailRecentMail extends Component
{
    /** The days the log is kept (mail:prune drops whole months older than this). */
    public const int KEEP_DAYS = 90;

    /** The rows the card shows for a filter. */
    public const int ROWS = 10;

    public const array FILTERS = ['all', 'sent', 'failed'];

    public string $filter = 'all';

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: the Email screen's own checks again.
        abort_unless(Gate::allows('settings.manage'), 403);
        app(SupportSession::class)->forbid();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public function render(): View
    {
        $now = app(SiteTime::class)->now();
        $zone = $now->getTimezone();
        $filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';

        $rows = MailLog::query()
            ->where('created_at', '>=', $now->subDays(self::KEEP_DAYS)->utc())
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ROWS)
            ->get(['id', 'kind', 'recipient_count', 'to_first', 'status', 'reason_code', 'created_at'])
            ->map(function (MailLog $log) use ($now, $zone): array {
                $at = CarbonImmutable::instance($log->created_at)->setTimezone($zone);

                return [
                    'key' => $log->id.'-'.$at->getTimestamp(),
                    'at' => self::shortWhen($at, $now),
                    'iso' => $at->toIso8601String(),
                    'full' => $at->format('d/m/Y · H:i'),
                    'kind' => __('admin.em_rm_kind_'.$log->kind),
                    'to' => $log->recipient_count > 1
                        ? __('admin.em_rm_to_more', ['first' => (string) $log->to_first, 'n' => $log->recipient_count - 1])
                        : (string) $log->to_first,
                    'sent' => $log->status === 'sent',
                    'reason' => $log->status === 'failed' ? __('admin.em_rm_why_'.($log->reason_code ?? 'other')) : null,
                ];
            })
            ->all();

        $totals = MailDaily::query()
            ->where('day', '>', $now->subDays(self::KEEP_DAYS)->toDateString())
            ->selectRaw('coalesce(sum(sent), 0) as sent, coalesce(sum(failed), 0) as failed')
            ->toBase()
            ->first();

        return view('admin::livewire.email-recent-mail', [
            'rows' => $rows,
            'filter' => $filter,
            'counts' => ['sent' => Cast::int($totals->sent ?? 0), 'failed' => Cast::int($totals->failed ?? 0)],
        ]);
    }

    /** "09:12" today, «Hôm qua» yesterday, then the day ("30/07", or "30/07/2025" for another year). */
    private static function shortWhen(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $days = (int) round($at->startOfDay()->diffInDays($now->startOfDay()));

        return match (true) {
            $days === 0 => $at->format('H:i'),
            $days === 1 => __('admin.em_rm_yesterday'),
            $at->year === $now->year && $days > 0 => $at->format('d/m'),
            default => $at->format('d/m/Y'),
        };
    }
}
