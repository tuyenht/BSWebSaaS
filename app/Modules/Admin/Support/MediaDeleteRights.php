<?php

namespace App\Modules\Admin\Support;

use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Who may send which files to the Trash and take them back out (design board 12):
 *
 *   - media.delete.any: every file of the website, in or out of use, and back out of the Trash
 *     whoever put it there;
 *   - media.delete.own: only files this person uploaded (media.created_by) that are not used
 *     anywhere; the Trash lists only their own files, and they take back out only what they put
 *     in themselves (media.deleted_by, or no one recorded);
 *   - neither: no Trash at all.
 *
 * Every rule is a query constraint, so a list, a count and a write all use the same one: the
 * page never decides which rows a write touches. Deleting for good stays with media.purge.
 */
final readonly class MediaDeleteRights
{
    public function __construct(private MediaUsageLocator $usage) {}

    /** Holds either half: sees the Trash and its actions. */
    public static function some(): bool
    {
        return Gate::allows('media.delete.any') || Gate::allows('media.delete.own');
    }

    public static function any(): bool
    {
        return Gate::allows('media.delete.any');
    }

    /**
     * Narrows a query of live files to those this person may send to the Trash.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function trashable(Builder $query): Builder
    {
        if (self::any()) {
            return $query;
        }
        if (! Gate::allows('media.delete.own')) {
            return $query->whereRaw('false');
        }
        $query->where('created_by', Auth::id());
        $this->usage->constrain($query, false);

        return $query;
    }

    /**
     * Narrows a query of trashed files to those this person sees in the Trash.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public static function visibleTrash(Builder $query): Builder
    {
        if (self::any()) {
            return $query;
        }
        if (! Gate::allows('media.delete.own')) {
            return $query->whereRaw('false');
        }

        return $query->where('created_by', Auth::id());
    }

    /**
     * Narrows a query of trashed files to those this person may take back out.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public static function restorable(Builder $query): Builder
    {
        if (self::any()) {
            return $query;
        }

        return self::visibleTrash($query)
            ->where(fn (Builder $q) => $q->whereNull('deleted_by')->orWhere('deleted_by', Auth::id()));
    }
}
