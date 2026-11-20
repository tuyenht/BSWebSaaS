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
 *   - One owner at a time: whoever queues a run (DeleteForm, a run handing over, forms:resume-deletes)
 *     first marks the form with the database clock (its updated_at, read back as Postgres wrote it)
 *     and gives the run that mark, its claim. Every batch, the row delete, the handover and failed()
 *     act only while the form still carries that claim. A run queued again by forms:resume-deletes
 *     takes the form over: the run it replaces, still queued or mid-batch, then does nothing more,
 *     and its failed() leaves the form to the new owner. A batch holds the form row FOR SHARE (which
 *     a new lead's FOR KEY SHARE check does not wait for), so a takeover or a switch back to off
 *     waits for it: no lead is deleted once the form has another owner or is off again.
 *     forms.updated_at keeps whole seconds, so two claims are told apart only a second or more
 *     apart: a handover comes RUN_SECONDS after its claim, a takeover --minutes after it, a new
 *     «Xóa» after a switch back at least the retries' backoff after it.
 *   - Idempotent: a retry (same claim) or a form already gone only finds less (or nothing) to do.
 *     Out of tries, the form is switched back to off, with the leads that are left (audited as
 *     forms.form.delete_failed), so it shows on the list again and «Xóa» can be asked again. A run
 *     lost altogether, or one waiting in the queue for longer than forms:resume-deletes allows, is
 *     queued again by that command, which only takes a form whose claim is that old.
 */
final class DeleteLargeForm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Seconds one run works before it hands over to a fresh run. */
    public const int RUN_SECONDS = 30;

    /** How long after its lead a «new lead» notice is looked for (it is written in the same request). */
    private const int NOTICE_WINDOW_MINUTES = 60;

    /**
     * @param  string  $claim  the form's updated_at as queueRun() wrote it (Postgres text): this run's hold on the delete
     * @param  string|null  $afterAt  the created_at of the last lead done (as Postgres wrote it), null: from the start
     */
    public function __construct(public int $siteId, public int $formId, public string $claim, public ?string $afterAt = null, public int $afterId = 0)
    {
        $this->afterCommit();
    }

    /**
     * Queues a run of the delete of the site's form being deleted: the form is marked with the
     * database clock, and the run, holding that mark, is pushed once the caller's transaction
     * commits. With $held, only while the form still carries that claim (a run handing over); with
     * $staleMinutes, only when it was last claimed longer ago than that, by the same database clock
     * (forms:resume-deletes). Called inside the site's tenant transaction. False when nothing was queued.
     */
    public static function queueRun(int $siteId, int $formId, ?string $held = null, ?int $staleMinutes = null, ?string $afterAt = null, int $afterId = 0): bool
    {
        // site_id kept explicit next to RLS, as the global scope would add it
        $sql = 'update forms set updated_at = clock_timestamp() where site_id = ? and id = ? and status = ?';
        $bindings = [$siteId, $formId, Form::DELETING];
        if ($held !== null) {
            $sql .= ' and updated_at = ?::timestamptz';
            $bindings[] = $held;
        }
        if ($staleMinutes !== null) {
            $sql .= ' and updated_at < clock_timestamp() - make_interval(mins => ?)';
            $bindings[] = $staleMinutes;
        }
        $claim = Cast::string(((array) DB::selectOne($sql.' returning updated_at', $bindings, false))['updated_at'] ?? '');
        if ($claim === '') {
            return false;
        }
        self::dispatch($siteId, $formId, $claim, $afterAt, $afterId);

        return true;
    }

    /**
     * Out of tries: the form is switched back to off with what is left of it, so it can be deleted
     * again — unless a newer run has taken the delete over since (the claim moved on): it is left to it.
     */
    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }
        try {
            app(Tenancy::class)->enter($this->siteId, function (): void {
                if ($this->held()->update(['status' => 'disabled']) === 1) {
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

        while (true) {
            while (($last = $this->deleteBatch($tenancy, $after, $batch)) !== null) {
                $after = $last;
                if (microtime(true) >= $stopAt) {
                    // the next run gets a fresh claim; none is queued when this one was taken over
                    $tenancy->enter($this->siteId, fn (): bool => self::queueRun($this->siteId, $this->formId, $this->claim, null, $last[0], $last[1]));

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
     * The form while it is being deleted and this run still holds it.
     *
     * @return Builder<Form>
     */
    private function held(): Builder
    {
        return self::deleting()->whereKey($this->formId)->whereRaw('updated_at = ?::timestamptz', [$this->claim]);
    }

    /**
     * One batch of the form's leads after $after deleted, with their notices; the cursor of the last
     * one, or null when none is left (or the form is not this site's form being deleted, or this run
     * no longer holds it).
     *
     * @param  array{0: string, 1: int}|null  $after
     * @return array{0: string, 1: int}|null
     */
    private function deleteBatch(Tenancy $tenancy, ?array $after, int $batch): ?array
    {
        return $tenancy->enter($this->siteId, function () use ($after, $batch): ?array {
            // held to the end of the batch: a takeover or failed() waits for it
            if (! $this->held()->sharedLock()->exists()) {
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

    /** Deletes the form row once at most one batch of leads is left in it (or it is gone already, or taken over). */
    private function deleteRowIfSmall(Tenancy $tenancy, int $batch): bool
    {
        return $tenancy->enter($this->siteId, function () use ($batch): bool {
            $row = $this->held()->lockForUpdate()->first(['id']);
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
