<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Tenancy\Jobs\WithinSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Counts these categories' public totals again and writes the true numbers (CategoryPublicCounts::
 * recount), off the request: for the changes whose share cannot be read per item cheaply — a
 * category moved under another parent (the ancestors its old and new chains do not share) and a
 * category translation added (its row starts at 0; the storefront shows no number until then). A
 * top category's total counts its whole branch, so this never runs inside the admin's save.
 *
 * Queued once the save commits, inside the site's tenant context (WithinSite). A miss (a failed job)
 * is healed by the nightly `cms:reconcile-category-public-counts`.
 */
final class RecountCategoryPublicCounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  list<int>  $taxonomyIds */
    public function __construct(public int $siteId, public array $taxonomyIds, public ?string $locale = null)
    {
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithinSite($this->siteId)];
    }

    public function handle(CategoryPublicCounts $counts): void
    {
        $counts->recount($this->taxonomyIds, $this->locale);
    }
}
