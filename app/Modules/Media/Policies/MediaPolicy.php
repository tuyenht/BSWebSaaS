<?php

namespace App\Modules\Media\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;

/**
 * Defense layer #2 for the media library — every action routes through a permission
 * (media.view/upload/delete.own/delete.any/purge), team-scoped by the spatie guard (= current site). Shipped with the
 * resource at birth so a write surface can never appear ungated.
 */
final class MediaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('media.view');
    }

    public function view(User $user): bool
    {
        return $user->can('media.view');
    }

    public function create(User $user): bool
    {
        return $user->can('media.upload');
    }

    /**
     * Sending a file to the Trash: any file with media.delete.any; with media.delete.own only a file
     * this person uploaded. Without a file: may they send any at all.
     *
     * A coarse check: media.delete.own also needs the file unused anywhere, and usage is known only
     * to the admin (App\Modules\Admin\Support\MediaDeleteRights, which the screens use and which
     * applies both rules to the query). Never treat a true here as enough on its own for «own».
     */
    public function delete(User $user, ?Media $media = null): bool
    {
        if ($user->can('media.delete.any')) {
            return true;
        }

        return $user->can('media.delete.own') && ($media === null || $media->created_by === $user->id);
    }

    /**
     * Taking a file out of the Trash: any file with media.delete.any; with media.delete.own only a
     * file this person uploaded and put in the Trash themselves (or whose deleter is unknown).
     */
    public function restore(User $user, Media $media): bool
    {
        if ($user->can('media.delete.any')) {
            return true;
        }

        return $user->can('media.delete.own') && $media->created_by === $user->id
            && ($media->deleted_by === null || $media->deleted_by === $user->id);
    }

    /** Deleting a file in the Trash for good is the website admin's right, not the Trash's. */
    public function forceDelete(User $user): bool
    {
        return $user->can('media.purge');
    }
}
