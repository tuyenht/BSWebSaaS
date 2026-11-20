<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;

/**
 * The on/off switch of a form on the «Biểu mẫu» list (and its «Hoàn tác»): an active form takes
 * leads, a disabled one refuses them on the storefront (StoreLeadRequest and the page component only
 * ever read active forms), and the leads it already took stay. The caller has checked FormPolicy.
 * False when the form is gone meanwhile (deleted, or being deleted in batches): nothing is changed.
 */
final class SetFormStatus
{
    public function handle(Form $form, bool $active): bool
    {
        $status = $active ? 'active' : 'disabled';
        // through the scoped query, never by key alone: a form switched to `deleting` meanwhile (a
        // delete in batches committed while this waited for its lock) is left as it is
        if ($form->status === $status) {
            return true;
        }
        if (Form::query()->whereKey($form->id)->update(['status' => $status]) === 1) {
            $form->status = $status;
            $form->syncOriginalAttribute('status');

            Audit::record('forms.form.status', [
                'auditable_type' => Form::class,
                'auditable_id' => $form->id,
                'meta' => ['key' => $form->key, 'status' => $status],
            ]);

            return true;
        }

        return false;
    }
}
