<?php

namespace App\Modules\Media\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;

/**
 * Defense layer #2 for the media library — every action routes through a permission
 * (media.view/upload/delete), team-scoped by the spatie guard (= current site). Shipped with the
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

    public function delete(User $user): bool
    {
        return $user->can('media.delete');
    }
}
