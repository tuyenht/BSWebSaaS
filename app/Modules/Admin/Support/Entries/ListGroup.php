<?php

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One run of rows in a list order. A plain order (last edited, publish date, views, position) is one
 * run; the status order is one run per status in rank order, the author order one per author by
 * name, the name order the titled rows and then the rows with no title in the default language. Each
 * run is read in its own order, which an index serves, and the runs follow each other.
 */
final readonly class ListGroup
{
    /**
     * @param  string  $key  names the run in a page cursor (a status, an author ulid, …)
     * @param  (Closure(Builder<Entry>): mixed)|null  $where  the rows of this run, null for all
     * @param  literal-string|null  $order  the sort expression (with its collation), null when the tie alone orders the run
     * @param  literal-string|null  $value  the sort value as the cursor keeps it, null when $order is null
     * @param  literal-string  $cast  the SQL type the kept value is read back as
     * @param  bool  $nullable  the sort value may be NULL (NULLs come last newest-first, first oldest-first)
     * @param  'asc'|'desc'  $dir  the direction of the run
     * @param  literal-string  $tie  the unique column that orders equal values, in the same direction
     * @param  bool  $rare  a run that is usually empty and costs a walk of the whole list to find so
     *                      (no title in the default language, a page whose parent is in the Trash): it
     *                      always comes last and is read on its own, only once the runs before it end
     */
    public function __construct(
        public string $key,
        public ?Closure $where,
        public ?string $order,
        public ?string $value,
        public string $cast,
        public bool $nullable,
        public string $dir,
        public bool $joinTitle = false,
        public string $tie = 'entries.id',
        public bool $rare = false,
    ) {}
}
