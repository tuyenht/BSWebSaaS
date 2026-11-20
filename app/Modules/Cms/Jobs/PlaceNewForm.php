<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Support\EntryFormPlacements;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Jobs\WithinSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A form just made: the page languages of its site whose blocks already name its key get their
 * form_placements rows (EntryFormPlacements::formCreated) — a form deleted and made again under the
 * same key, or the contact form made after pages that show it. It reads the site's page bodies, so
 * it runs off the form's save: queued once that save commits, inside the site's tenant context
 * (WithinSite). A miss (a failed job, or a page saved at the same moment) is healed by the page's
 * next save or `forms:placements`.
 */
final class PlaceNewForm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $siteId, public int $formId)
    {
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithinSite($this->siteId)];
    }

    public function handle(): void
    {
        $form = Form::query()->whereKey($this->formId)->first(['id', 'site_id', 'key']);
        if ($form !== null) {
            EntryFormPlacements::formCreated($form);
        }
    }
}
