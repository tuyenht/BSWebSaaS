<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every tenant Eloquent model uses this trait (CLAUDE.md §2). It adds a global scope
 * that filters by the current site and stamps site_id on create.
 *
 * The scope mirrors RLS and is fail-closed: a tenant query with no resolved site
 * returns nothing (RLS is the DB-level net; this is the app-level one).
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', static function (Builder $builder): void {
            $current = app(CurrentSite::class);
            $table = $builder->getModel()->getTable();

            if ($current->hasId()) {
                $builder->where($table.'.site_id', $current->id());

                return;
            }

            $builder->whereRaw('1 = 0');
        });

        static::creating(function (Model $model): void {
            $current = app(CurrentSite::class);

            if ($model->getAttribute('site_id') === null && $current->hasId()) {
                $model->setAttribute('site_id', $current->id());
            }
        });
    }
}
