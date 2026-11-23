<?php

declare(strict_types=1);

namespace App\Modules\Forms\Policies;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;

/**
 * Who may erase a lead for good («Xóa theo yêu cầu của khách» in «Khách liên hệ»), move it to the
 * Trash («Chuyển vào Thùng rác») or bring it back («Khôi phục», «Hoàn tác»): a member who manages the
 * website's leads (`forms.manage`, team-scoped by the spatie guard = current site), the same people
 * who change a lead's status. A member who may only read leads (`forms.view`) sees the Trash and
 * changes nothing in it. super_admin passes through Gate::before.
 *
 * Who may download the files a visitor sent with a lead («Tải về» under «Tệp đính kèm»): a member
 * given `forms.files`, which a role that only reads leads does not hold by default. A lead in the
 * Trash is seen only by those who manage leads, so its files need forms.manage too.
 */
final class LeadPolicy
{
    public function delete(User $user, FormSubmission $lead): bool
    {
        return $user->can('forms.manage');
    }

    public function trash(User $user, FormSubmission $lead): bool
    {
        return $user->can('forms.manage');
    }

    public function restore(User $user, FormSubmission $lead): bool
    {
        return $user->can('forms.manage');
    }

    public function downloadFile(User $user, FormSubmission $lead): bool
    {
        return $user->can('forms.files') && ($lead->deleted_at === null || $user->can('forms.manage'));
    }
}
