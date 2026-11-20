<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * «Xóa theo yêu cầu của khách» in «Khách liên hệ»: one lead goes for good at the visitor's request
 * — no Trash, no undo. With it go the bell's «new lead» notices of it, which carry the visitor's
 * name or phone in their data and in their search text (site_notifications.search_text is a column
 * of the notice row, so deleting the notice deletes it). Mails already sent stay in the inboxes
 * they went to. The caller has asked LeadPolicy::delete; a support session is refused here too
 * (staff signed in for a member never erase a visitor's data on their behalf).
 *
 * One transaction: the lead row is locked first, so two clicks (or two colleagues) erase it once
 * and write one audit row; the second finds nothing and is told the lead is gone. A lead of a form
 * being deleted is out of reach, as on every screen. The audit row is written in the same
 * transaction, so an erase never happens unaudited; it names the form's key only — never the
 * visitor's name, phone, email, address or answers.
 *
 * The form row is share-locked before the lead, in the order DeleteForm takes them, so an erase and
 * a form delete at the same time queue instead of deadlocking; a form found being deleted then is
 * out of reach. The notices are looked up through the (site_id, created_at) index: a notice is
 * written in the request that stored its lead, so it is looked for from a minute before the lead
 * to an hour after (as DeleteLargeForm does); a lead without a time is looked up without one.
 */
final class EraseLead
{
    private const int NOTICE_WINDOW_MINUTES = 60;

    /** @return bool false: the lead is not (or no longer) there */
    public function handle(string $ulid): bool
    {
        app(SupportSession::class)->forbid();

        return DB::transaction(function () use ($ulid): bool {
            $formId = FormSubmission::query()->where('ulid', $ulid)->value('form_id');
            if ($formId === null) {
                return false;
            }
            // the form first, as DeleteForm locks it before its leads: a form delete running at the
            // same time waits for this erase (or this erase for it) instead of deadlocking with it
            $form = Form::query()->withoutGlobalScope(Form::NOT_DELETING)->whereKey($formId)
                ->sharedLock()->first(['id', 'key', 'status']);
            if ($form === null || $form->status === Form::DELETING) {
                return false;
            }
            $lead = FormSubmission::query()->where('ulid', $ulid)->where('form_id', $form->id)
                ->select(['id', 'ulid', 'created_at'])->lockForUpdate()->first();
            if ($lead === null) {
                return false;
            }

            $notices = SiteNotification::query()->where('kind', 'lead.new')->where(DB::raw("data->>'submission'"), $lead->ulid);
            if ($lead->created_at !== null) {
                $sent = $lead->created_at->toImmutable();
                $notices->whereBetween('created_at', [$sent->subMinute(), $sent->addMinutes(self::NOTICE_WINDOW_MINUTES)]);
            }
            $notices->delete();

            FormSubmission::query()->whereKey($lead->id)->delete();

            Audit::recordOrFail('forms.lead.erased', [
                'auditable_type' => FormSubmission::class,
                'auditable_id' => $lead->id,
                'meta' => ['form' => Cast::string($form->key)],
            ]);

            return true;
        });
    }
}
