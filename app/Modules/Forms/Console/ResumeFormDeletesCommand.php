<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Jobs\DeleteLargeForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Queues DeleteLargeForm again for every form left in `deleting` with no progress for --minutes:
 * a job lost from the queue, or one whose failed() could not switch the form back to off. Such a
 * form is hidden on every screen, so nobody could ask for its delete again; its leads are personal
 * data and must not stay for good. Every run of the job marks the form (updated_at), so a delete
 * still under way is left alone. Safe to re-run: the job is idempotent and unique per form.
 */
final class ResumeFormDeletesCommand extends Command
{
    #[\Override]
    protected $signature = 'forms:resume-deletes
        {--minutes=30 : A form in deleting this long without progress is queued again}';

    #[\Override]
    protected $description = 'Queue again the deletes of forms left half-deleted (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $before = now()->subMinutes(max(1, Cast::int($this->option('minutes'), 30)));
        $queued = 0;

        $tenancy->eachSite(function (int $siteId) use ($before, &$queued): void {
            $ids = Form::query()->withoutGlobalScope(Form::NOT_DELETING)
                ->where('status', Form::DELETING)->where('updated_at', '<', $before)->pluck('id');
            foreach ($ids as $id) {
                DeleteLargeForm::dispatch($siteId, Cast::int($id));
                $queued++;
            }
        });

        $this->info("Queued {$queued} form delete(s) again.");

        return self::SUCCESS;
    }
}
