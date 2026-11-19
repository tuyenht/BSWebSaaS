<?php

declare(strict_types=1);

namespace App\Modules\Forms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
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
 * the system contact form is refused here too, since a platform operator passes every policy.
 *
 * One transaction: the form row is locked first, so a lead sent at the same moment either lands
 * before (and is counted and deleted) or fails on the missing form. When more leads are there than
 * the member was shown ($atMost), nothing is deleted and the count is returned, so the question is
 * asked again with it. The audit row is written in the same transaction, so a delete never happens
 * unaudited; it names the form's key and how many leads went, never what a lead said.
 */
final class DeleteForm
{
    /**
     * @param  int|null  $atMost  the leads the member was shown; null: any number
     * @return array{deleted: bool, leads: int}|null null: the form is gone; leads: spam left out (what the list shows)
     *
     * @throws AuthorizationException for the system contact form
     */
    public function handle(Form $form, ?int $atMost = null): ?array
    {
        return DB::transaction(function () use ($form, $atMost): ?array {
            $locked = Form::query()->whereKey($form->getKey())->lockForUpdate()->first(['id', 'key']);
            if ($locked === null) {
                return null;
            }
            if ($locked->key === Form::SYSTEM_KEY) {
                throw new AuthorizationException('The system contact form is never deleted.');
            }

            $counts = (array) FormSubmission::query()->where('form_id', $locked->id)
                ->toBase()
                ->selectRaw("count(*) filter (where status <> 'spam') as leads, count(*) filter (where status = 'spam') as spam")
                ->first();
            $leads = Cast::int($counts['leads'] ?? 0);
            $spam = Cast::int($counts['spam'] ?? 0);
            if ($atMost !== null && $leads > $atMost) {
                return ['deleted' => false, 'leads' => $leads];
            }

            // the site's notices are kept 90 days (notifications:prune), so this reads a bounded set
            SiteNotification::query()->where('kind', 'lead.new')
                ->whereIn(DB::raw("data->>'submission'"), FormSubmission::query()->where('form_id', $locked->id)->select('ulid'))
                ->delete();

            $locked->delete();

            Audit::recordOrFail('forms.form.deleted', [
                'auditable_type' => Form::class,
                'auditable_id' => $locked->id,
                'meta' => ['key' => $locked->key, 'leads' => $leads, 'spam' => $spam],
            ]);

            return ['deleted' => true, 'leads' => $leads];
        });
    }
}
