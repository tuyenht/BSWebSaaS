<?php

declare(strict_types=1);

namespace App\Modules\Forms\Policies;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;

/**
 * Who may erase a lead for good («Xóa theo yêu cầu của khách» in «Khách liên hệ»): a member who
 * manages the website's leads (`forms.manage`, team-scoped by the spatie guard = current site),
 * the same people who change a lead's status. A member who may only read leads (`forms.view`)
 * never erases one. super_admin passes through Gate::before.
 */
final class LeadPolicy
{
    public function delete(User $user, FormSubmission $lead): bool
    {
        return $user->can('forms.manage');
    }
}
