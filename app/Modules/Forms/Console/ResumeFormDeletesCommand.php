<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Jobs\DeleteLargeForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Queues DeleteLargeForm again for every form left in `deleting` with no run queued for --minutes:
 * a job lost from the queue, one waiting behind a long queue, or one whose failed() could not
 * switch the form back to off. Such a form is hidden on every screen, so nobody could ask for its
 * delete again; its leads are personal data and must not stay for good. Every run is queued with a
 * fresh claim on the form (its updated_at, renewed at each handover, about every
 * DeleteLargeForm::RUN_SECONDS), so a delete under way is left alone; a form taken over here is
 * held by the new run only, and the run it replaces does nothing more. Safe to re-run.
 */
final class ResumeFormDeletesCommand extends Command
{
    #[\Override]
    protected $signature = 'forms:resume-deletes
        {--minutes=30 : A form in deleting with no run queued for this long is queued again}';

    #[\Override]
    protected $description = 'Queue again the deletes of forms left half-deleted (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $minutes = max(1, Cast::int($this->option('minutes'), 30));
        $asked = 0;

        $tenancy->eachSite(function (int $siteId) use ($minutes, &$asked): void {
            // the database clock, as the claims are written with it
            $ids = Form::query()->withoutGlobalScope(Form::NOT_DELETING)->where('status', Form::DELETING)
                ->whereRaw('updated_at < clock_timestamp() - make_interval(mins => ?)', [$minutes])->pluck('id');
            foreach ($ids as $id) {
                // claimed again only if no run claimed it since the read above
                if (DeleteLargeForm::queueRun($siteId, Cast::int($id), staleMinutes: $minutes)) {
                    $asked++;
                }
            }
        });

        $this->info("Queued again {$asked} form delete(s).");

        return self::SUCCESS;
    }
}
