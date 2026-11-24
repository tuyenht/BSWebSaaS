<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * «Tải về» of one file a visitor sent with a lead («Tệp đính kèm» in «Khách liên hệ»).
 *
 * In this order, each step refusing on its own:
 *  1. the plan has «Khách liên hệ», and the member holds `forms.files` (asked before the file is
 *     looked up, so a member without it learns nothing of which addresses exist);
 *  2. not a support session: staff signed in for a member never take a visitor's file;
 *  3. the file is looked up by its ulid in the current website (RLS + the model's scope: another
 *     website's ulid is not found), kept (`stored`), and its lead still there and of a form not
 *     being deleted; a file whose lead is gone waits for the sweeper and is never handed out;
 *  4. LeadPolicy::downloadFile on the lead (a lead in the Trash needs forms.manage too);
 *  5. the audit row `forms.lead.file_downloaded` (the file's ulid and the form's key, never the
 *     file's name or the visitor's data), in the request's own transaction: when it cannot be
 *     written, no link is made;
 *  6. a 302 to a link signed for LeadFileStorage::LINK_SECONDS that the browser saves as a download
 *     (`application/octet-stream`, `Content-Disposition: attachment`), never shown inline.
 */
final class LeadFileDownloadController
{
    public function __invoke(string $ulid, Entitlement $entitlement, SupportSession $support): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        abort_unless(in_array('contacts', $entitlement->entitledModuleKeys(), true), 403);
        abort_unless(Gate::allows('forms.files'), 403);
        $support->forbid();

        $file = FormSubmissionFile::query()
            ->where('ulid', strtoupper($ulid))
            ->where('status', FormSubmissionFile::STORED)
            ->whereNotNull('form_submission_id')
            ->first(['id', 'site_id', 'ulid', 'form_submission_id', 'original_name', 'storage_key', 'status']);
        abort_if($file === null || $file->storage_key === null, 404);

        $lead = FormSubmission::query()->whereKey($file->form_submission_id)->first(['id', 'site_id', 'form_id', 'deleted_at']);
        abort_if($lead === null, 404);
        $formKey = Form::query()->whereKey($lead->form_id)->value('key');
        abort_if($formKey === null, 404);

        Gate::authorize('downloadFile', $lead);

        Audit::recordOrFail('forms.lead.file_downloaded', [
            'auditable_type' => FormSubmission::class,
            'auditable_id' => $lead->id,
            'meta' => ['file' => $file->ulid, 'form' => Cast::string($formKey)],
        ]);

        return redirect()->away(LeadFileStorage::downloadUrl($file))->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
