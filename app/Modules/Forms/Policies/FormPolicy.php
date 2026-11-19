<?php

declare(strict_types=1);

namespace App\Modules\Forms\Policies;

use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for the site's forms (the «Biểu mẫu» screen): one coarse permission,
 * `forms.manage`, team-scoped by the spatie guard (= current site), like the leads inbox's writes.
 * Shipped with the screen so no form write is ever ungated; super_admin passes through Gate::before.
 *
 * The system form (key `contact`, the one every site is given) can be changed and switched off but
 * never deleted: pages and the site's own sync command count on it.
 */
final class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('forms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('forms.manage');
    }

    public function update(User $user, ?Form $form = null): bool
    {
        return $user->can('forms.manage');
    }

    public function delete(User $user, ?Form $form = null): bool
    {
        return $user->can('forms.manage') && ($form === null || ! $form->isSystem());
    }
}
