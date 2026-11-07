<?php

declare(strict_types=1);

namespace App\Modules\Cms\Observers;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;

/**
 * Drops a deleted category's block slot rows from the library's "in use" index. `mediables` is a
 * polymorphic soft attachment (no foreign key back to taxonomies, D3), so a category deleted
 * through its model would otherwise leave the pictures and files its slots named reading as used by
 * a gone category. The category screen deletes in one statement (DeleteTaxonomy), which drops the
 * rows itself; this covers every other road through the model.
 */
final readonly class TaxonomyObserver
{
    public function __construct(private BlockMediaUsage $usage) {}

    public function deleted(Taxonomy $taxonomy): void
    {
        $this->usage->forget('taxonomy', [$taxonomy->id]);
    }
}
