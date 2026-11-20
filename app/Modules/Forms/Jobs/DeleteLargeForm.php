<?php

declare(strict_types=1);

namespace App\Modules\Forms\Jobs;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes a form that took more leads than one web request should delete (config
 * forms.delete_inline_max_leads). DeleteForm has switched it to `deleting` and audited the delete;
 * this job deletes its leads in bounded batches, then the form row (its fields go with it).
 * Deleting the row at once would make the foreign key's ON DELETE CASCADE remove every lead in one
 * statement, inside the request and under the form's lock.
 *
 *   - Every batch is its own short tenant transaction (Tenancy::enter: SET LOCAL
 *     app.current_site_id, RLS + the global scope), keyset by (created_at, id) over the
 *     (site_id, form_id, created_at) index. A batch first checks that the form is still this
 *     site's and still `deleting`: a job naming another site, or a form that is not being
 *     deleted, deletes nothing.
 *   - With its leads go the bell's «new lead» notices of them (they carry the visitor's name),
 *     looked up through the (site_id, created_at) index: a notice is written in the request that
 *     stored its lead, so it is looked for from a minute before its lead to an hour after.
 *   - The row is deleted only when at most one batch of leads is left (a lead sent by a visitor who
 *     had loaded the form just before it was switched off, below the cursor, is caught by the next
 *     sweep), under the form's row lock, which a new lead's foreign-key check waits for.
 *   - A run stops after a time budget and queues the next one, well inside the worker's timeout.
 *   - Idempotent: a retry, a second run or a form already gone only finds less (or nothing) to do.
 *     Out of tries, the form is switched back to off, with the leads that are left (audited as
 *     forms.form.delete_failed), so it shows on the list again and «Xóa» can be asked again. A job
 *     lost altogether is queued again by forms:resume-deletes: every run marks the form's updated_at.
 */
final class DeleteLargeForm implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds the unique lock outlives a dispatch whose push never reached the queue (the lock is
     * taken at dispatch, freed when a worker starts the job): below forms:resume-deletes' 30 minutes,
     * so its next dispatch is never dropped by a lock left behind.
     */
    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Seconds one run works before it hands over to a fresh run. */
    public const int RUN_SECONDS = 30;

    /** How long after its lead a «new lead» notice is looked for (it is written in the same request). */
    private const int NOTICE_WINDOW_MINUTES = 60;

    /**
     * @param  string|null  $afterAt  the created_at of the last lead done (as Postgres wrote it), null: from the start
     */
    public function __construct(public int $siteId, public int $formId, public ?string $afterAt = null, public int $afterId = 0)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->formId;
    }

    /** Out of tries: the form is switched back to off with what is left of it, so it can be deleted again. */
    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }
        try {
            app(Tenancy::class)->enter($this->siteId, function (): void {
                if (self::deleting()->whereKey($this->formId)->update(['status' => 'disabled']) === 1) {
                    // the trail says the delete did not finish, next to the row that asked for it
                    Audit::record('forms.form.delete_failed', ['auditable_type' => Form::class, 'auditable_id' => $this->formId]);
                }
            });
        } catch (Throwable $again) {
            report($again);
        }
    }

    /** True when the run finished the form (or found nothing to do); false when it handed over to a next run. */
    public function handle(Tenancy $tenancy): bool
    {
        $batch = max(1, Cast::int(config('forms.delete_batch_leads', 2000)));
        $stopAt = microtime(true) + self::RUN_SECONDS;
        $after = $this->afterAt === null ? null : [$this->afterAt, $this->afterId];
        // progress mark for forms:resume-deletes, which queues again a delete left without one
        $tenancy->enter($this->siteId, fn (): int => self::deleting()->whereKey($this->formId)->update(['updated_at' => now()]));

        while (true) {
            while (($last = $this->deleteBatch($tenancy, $after, $batch)) !== null) {
                $after = $last;
                if (microtime(true) >= $stopAt) {
                    self::dispatch($this->siteId, $this->formId, $after[0], $after[1]);

                    return false;
                }
            }
            if ($this->deleteRowIfSmall($tenancy, $batch)) {
                return true;
            }
            $after = null; // leads came in below the cursor meanwhile: one more sweep
        }
    }

    /**
     * The form, while it is being deleted, past the scope that hides it everywhere else.
     *
     * @return Builder<Form>
     */
    private static function deleting(): Builder
    {
        return Form::query()->withoutGlobalScope(Form::NOT_DELETING)->where('status', Form::DELETING);
    }

    /**
     * One batch of the form's leads after $after deleted, with their notices; the cursor of the last
     * one, or null when none is left (or the form is not this site's form being deleted).
     *
     * @param  array{0: string, 1: int}|null  $after
     * @return array{0: string, 1: int}|null
     */
    private function deleteBatch(Tenancy $tenancy, ?array $after, int $batch): ?array
    {
        return $tenancy->enter($this->siteId, function () use ($after, $batch): ?array {
            if (! self::deleting()->whereKey($this->formId)->exists()) {
                return null;
            }
            $query = FormSubmission::query()->where('form_id', $this->formId);
            if ($after !== null) {
                $query->where('created_at', '>=', $after[0])->whereRaw('(created_at, id) > (?::timestamptz, ?)', $after);
            }
            $rows = $query->orderBy('created_at')->orderBy('id')->limit($batch)
                ->toBase()->get(['id', 'ulid', 'created_at']);
            if ($rows->isEmpty()) {
                return null;
            }
            $first = $rows->first();
            $last = $rows->last();

            $this->deleteNotices(
                Cast::strings($rows->pluck('ulid')->all()),
                CarbonImmutable::parse(Cast::string($first->created_at))->subMinute(),
                CarbonImmutable::parse(Cast::string($last->created_at))->addMinutes(self::NOTICE_WINDOW_MINUTES),
            );
            FormSubmission::query()->where('form_id', $this->formId)
                ->whereIn('id', $rows->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all())
                ->toBase()->delete();

            return [Cast::string($last->created_at), Cast::int($last->id)];
        });
    }

    /** Deletes the form row once at most one batch of leads is left in it (or it is gone already). */
    private function deleteRowIfSmall(Tenancy $tenancy, int $batch): bool
    {
        return $tenancy->enter($this->siteId, function () use ($batch): bool {
            $row = self::deleting()->whereKey($this->formId)->lockForUpdate()->first(['id']);
            if ($row === null) {
                return true;
            }
            $left = FormSubmission::query()->where('form_id', $this->formId)->orderBy('created_at')
                ->limit($batch + 1)->toBase()->get(['ulid', 'created_at']);
            if ($left->count() > $batch) {
                return false;
            }
            if ($left->isNotEmpty()) {
                $this->deleteNotices(
                    Cast::strings($left->pluck('ulid')->all()),
                    CarbonImmutable::parse(Cast::string($left->first()->created_at))->subMinute(),
                    CarbonImmutable::parse(Cast::string($left->last()->created_at))->addMinutes(self::NOTICE_WINDOW_MINUTES),
                );
            }
            $row->delete(); // the last leads and the fields through ON DELETE CASCADE

            return true;
        });
    }

    /**
     * The bell's «new lead» notices of these leads.
     *
     * @param  list<string>  $ulids
     */
    private function deleteNotices(array $ulids, CarbonImmutable $from, CarbonImmutable $to): void
    {
        SiteNotification::query()->where('kind', 'lead.new')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn(DB::raw("data->>'submission'"), $ulids)
            ->delete();
    }
}
