<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Forms\Support\FormFieldTypes;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Shared\Contracts\MailFailureRecorder;
use App\Modules\Shared\Contracts\TenantMailConfigurator;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\TurnstileVerifier;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Lead-capture pipeline (design.md §16/§23/§27). Anti-spam runs BEFORE the lead is trusted:
 * a filled honeypot OR a failed Turnstile check marks the submission as spam — it is still
 * stored (status='spam', is_spam=true) so the operator can review false positives, but the
 * notify_emails recipients are NOT emailed. A clean lead is stored 'new', its typed columns
 * (name/email/phone) are graduated for the inbox, and recipients are notified best-effort
 * (a mail failure must never lose the captured lead). site_id is stamped by BelongsToSite
 * from the current tenant context (RLS).
 *
 * Files sent with a clean lead («Tệp đính kèm») are kept once the lead row exists, field by field:
 * up to the field's «Số tệp tối đa» each goes through StoreLeadFile (kept, or refused with a row),
 * the rest are recorded as refused unread. Each file runs in its own savepoint, so one that cannot
 * be written (the disk is down) is reported and left out while the lead and the other files stay:
 * a file never costs the visitor their lead. A spam lead keeps no file at all. The lead comes back
 * with the rows written as its `files` relation, and the email says how many came.
 */
final readonly class SubmitForm
{
    public function __construct(private TurnstileVerifier $turnstile, private StoreLeadFile $storeFile) {}

    /**
     * @param  array<string, mixed>  $data  validated field values (no honeypot / token, no files)
     * @param  array{ip?: string|null, user_agent?: string|null, source_url?: string|null}  $meta
     * @param  array<string, list<UploadedFile>>  $files  the files sent, by file field key
     */
    public function handle(
        Form $form,
        array $data,
        ?string $honeypot = null,
        ?string $turnstileToken = null,
        array $meta = [],
        array $files = [],
    ): FormSubmission {
        $ip = $meta['ip'] ?? null;
        $data = $this->recordConsent($form, $data);
        $isSpam = $this->isSpam($honeypot, $turnstileToken, $ip);

        // Cap to the column limits at the storage boundary. name/email/phone are validated upstream,
        // but source_url (the Referer) is unbounded — without this an over-long URL trips Postgres'
        // varchar(255) (it rejects, not truncates) and 500s the submission, losing the lead.
        $submission = $form->submissions()->create([
            'data' => $data,
            'name' => $this->cap($data['name'] ?? null, 255),
            // Reply-To uses this column: only an address that is one (a site may key a plain text field "email").
            'email' => filter_var($data['email'] ?? null, FILTER_VALIDATE_EMAIL) !== false ? $this->cap($data['email'], 255) : null,
            'phone' => $this->cap($data['phone'] ?? null, 32),
            'source_url' => $this->cap($meta['source_url'] ?? null, 255),
            'ip_address' => $ip,
            'user_agent' => $meta['user_agent'] ?? null,
            'status' => $isSpam ? 'spam' : 'new',
            'is_spam' => $isSpam,
        ]);

        $submission->setRelation('files', new Collection($isSpam ? [] : $this->keepFiles($form, $submission, $files)));

        if (! $isSpam) {
            $this->notify($form, $submission);

            // In-app: everyone on the site who may read leads sees a new one in their bell.
            Notify::holdersOf('forms.view', 'lead.new', [
                'submission' => $submission->ulid,
                // Who to call back — the name, else the phone, else the email the visitor left.
                'name' => $submission->name ?: ($submission->phone ?: ($submission->email ?: '—')),
            ]);
        }

        return $submission;
    }

    /**
     * A ticked checkbox is kept as the sentence the visitor agreed to, in the language they read
     * it in — the evidence of consent; the submission's created_at says when. Reads the form's
     * fields as loaded (the lead request loads them with the form), so no second lookup.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recordConsent(Form $form, array $data): array
    {
        foreach ($form->fields as $field) {
            if ($field->type === 'checkbox' && ! empty($data[$field->key])) {
                $labels = Cast::arr($field->label);
                $data[$field->key] = Cast::string($labels[app()->getLocale()] ?? $labels['vi'] ?? null) ?: true;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, list<UploadedFile>>  $files
     * @return list<FormSubmissionFile>
     */
    private function keepFiles(Form $form, FormSubmission $lead, array $files): array
    {
        $rows = [];
        foreach ($form->fields as $field) {
            if ($field->type !== FormFieldTypes::FILE || ! FormFieldTypes::isCollectable($field)) {
                continue;
            }
            $kind = FormFieldTypes::fileKind($field);
            $max = FormFieldTypes::maxFiles($field);
            $i = 0;
            foreach ($files[$field->key] ?? [] as $file) {
                $i++;
                try {
                    $row = DB::transaction(fn (): ?FormSubmissionFile => $i <= $max
                        ? $this->storeFile->handle($lead, $field->key, $kind, $file)
                        : $this->storeFile->refuse($lead, $field->key, $file));
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** Narrow to a non-empty string capped to $limit characters (the column width), else null. */
    private function cap(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function isSpam(?string $honeypot, ?string $token, ?string $ip): bool
    {
        // A bot filled the hidden honeypot field — a human never sees it.
        if ($honeypot !== null && trim($honeypot) !== '') {
            return true;
        }

        // Turnstile fails closed when a secret IS configured; skipped (passes) in dev.
        return ! $this->turnstile->verify($token, $ip);
    }

    private function notify(Form $form, FormSubmission $submission): void
    {
        $recipients = Cast::strings($form->notify_emails);
        if ($recipients === []) {
            return;
        }

        try {
            app(TenantMailConfigurator::class)->apply();
            // The owner reads the lead in the SITE's language, whatever language the visitor used.
            Notification::route('mail', $recipients)
                ->notify((new NewLeadNotification($submission, $form, $submission->files->where('status', FormSubmissionFile::STORED)->count()))->locale(app(SiteLocale::class)->default()));
        } catch (Throwable $e) {
            // Best-effort: a mail/transport failure must not 500 the storefront or lose the lead.
            report($e);
            // …and the website's «Thư gần đây» shows the notice that did not leave.
            app(MailFailureRecorder::class)->mailFailed(NewLeadNotification::class, $e, $recipients);
        }
    }
}
