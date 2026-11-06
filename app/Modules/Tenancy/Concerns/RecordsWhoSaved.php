<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Stamps `updated_by` with the signed-in account on every save that writes the row, in the same
 * statement as `updated_at`, so a settings section can say who saved it last. A save made with no
 * one signed in (a console command, a provisioning run) clears it: the stamp then shows the time
 * without a name, never the name of someone who did not make that change. A save that changes
 * nothing writes nothing, so the stamp stays as it was.
 *
 * Writes that bypass the model (a query-builder update) skip this on purpose; a one-off fill
 * command uses that to leave both the stamp time and the name alone.
 */
trait RecordsWhoSaved
{
    public static function bootRecordsWhoSaved(): void
    {
        static::saving(static function (Model $model): void {
            if ($model->exists && ! $model->isDirty()) {
                return;
            }

            $id = Auth::id();
            $model->setAttribute('updated_by', is_numeric($id) ? (int) $id : null);
        });
    }
}
