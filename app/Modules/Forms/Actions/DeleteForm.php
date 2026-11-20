<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Jobs\DeleteLargeForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * «Xóa» on the «Biểu mẫu» list: the form goes for good, with its fields and every lead it took
 * (ON DELETE CASCADE, served by the form_id index), and the bell's «new lead» notices of those
 * leads, which carry the visitor's name or phone. Forms have no Trash, and leads are personal data,
 * so nothing of them is kept here (mails already sent stay in the inboxes they went to); the dialog
 * says so with the count first and offers «Tắt biểu mẫu» instead. The caller has asked FormPolicy;
 * the system contact form is refused here too, since a platform operator passes every policy, and
 * so is a support session: staff signed in for a member never delete visitors' leads on their behalf.
 *
 * One transaction: the form row is locked first, so a lead sent at the same moment either lands
 * before (and is counted and deleted) or fails on the missing form. When more leads are there than
 * the member was shown ($atMost), nothing is deleted and the count is returned, so the question is
 * asked again with it. The audit row is written in the same transaction, so a delete never happens
 * unaudited; it names the form's key and how many leads went, never what a lead said.
 *
 * A form with more leads than one request should delete (config forms.delete_inline_max_leads,
 * spam included) is not deleted here: in the same transaction it is switched to `deleting` — it
 * takes no lead from then on and reads as gone on every screen — the delete is audited, and
 * DeleteLargeForm, queued once this commits, deletes its leads in batches and then the row. Such a
 * form is never counted inside the request: the count shown is what the member agreed to.
 */
final class DeleteForm
{
    /**
     * @param  int|null  $atMost  the leads the member was shown; null: any number
     * @return array{deleted: bool, leads: int, later: bool}|null null: the form is gone; leads: spam left out (what the list
     *                                                            shows; for a delete in batches, the count the member was shown);
     *                                                            later: its leads are being deleted in batches off the request
     *
     * @throws AuthorizationException for the system contact form, or during a support session
     */
    public function handle(Form $form, ?int $atMost = null): ?array
    {
        app(SupportSession::class)->forbid();

        return DB::transaction(function () use ($form, $atMost): ?array {
            $locked = Form::query()->whereKey($form->getKey())->lockForUpdate()->first(['id', 'site_id', 'key']);
            if ($locked === null) {
                return null;
            }
            if ($locked->key === Form::SYSTEM_KEY) {
                throw new AuthorizationException('The system contact form is never deleted.');
            }

            if (self::tooManyInline($locked->id)) {
                return $this->deleteLater($locked, $atMost);
            }

            $counts = (array) FormSubmission::query()->where('form_id', $locked->id)
                ->toBase()
                ->selectRaw("count(*) filter (where status <> 'spam') as leads, count(*) filter (where status = 'spam') as spam")
                ->first();
            $leads = Cast::int($counts['leads'] ?? 0);
            $spam = Cast::int($counts['spam'] ?? 0);
            if ($atMost !== null && $leads > $atMost) {
                return ['deleted' => false, 'leads' => $leads, 'later' => false];
            }

            // no index leads with kind: this reads every notice of the site's last 90 days (they are
            // pruned after that, notifications:prune) — acceptable for a rare action, not measured
            SiteNotification::query()->where('kind', 'lead.new')
                ->whereIn(DB::raw("data->>'submission'"), FormSubmission::query()->where('form_id', $locked->id)->select('ulid'))
                ->delete();

            $locked->delete();

            Audit::recordOrFail('forms.form.deleted', [
                'auditable_type' => Form::class,
                'auditable_id' => $locked->id,
                'meta' => ['key' => $locked->key, 'leads' => $leads, 'spam' => $spam],
            ]);

            return ['deleted' => true, 'leads' => $leads, 'later' => false];
        });
    }

    /** More leads (spam included) than one request deletes. */
    private static function tooManyInline(int $formId): bool
    {
        // select 1: an index-only walk of (site_id, form_id, created_at), no lead row read
        return FormSubmission::query()->where('form_id', $formId)->toBase()->selectRaw('1')
            ->offset(max(0, Cast::int(config('forms.delete_inline_max_leads', 5000))))->exists();
    }

    /**
     * The form switched to `deleting`, audited, and its batches queued (once the caller's transaction
     * commits) — unless the member was shown a count and more leads came in since: then nothing is
     * done and the count, read up to one past what was shown, is returned so the question is asked again.
     *
     * @return array{deleted: bool, leads: int, later: bool}
     */
    private function deleteLater(Form $locked, ?int $atMost): array
    {
        if ($atMost !== null) {
            $leads = Cast::int(DB::scalar(
                "select count(*) from (select 1 from form_submissions where site_id = ? and form_id = ? and status <> 'spam' limit ?) x",
                [$locked->site_id, $locked->id, $atMost + 1],
            ));
            if ($leads > $atMost) {
                return ['deleted' => false, 'leads' => $leads, 'later' => false];
            }
        }

        Form::query()->whereKey($locked->id)->toBase()->update(['status' => Form::DELETING]);

        Audit::recordOrFail('forms.form.deleted', [
            'auditable_type' => Form::class,
            'auditable_id' => $locked->id,
            // the count the member agreed to: exact, or past the list's cap («hơn 10.000») when null
            'meta' => ['key' => $locked->key, 'leads' => $atMost, 'leads_over_cap' => $atMost === null, 'in_batches' => true],
        ]);

        // the form's own site, as locked here; queueRun() also gives the run its claim on the delete
        DeleteLargeForm::queueRun($locked->site_id, $locked->id);

        return ['deleted' => true, 'leads' => $atMost ?? 0, 'later' => true];
    }
}
