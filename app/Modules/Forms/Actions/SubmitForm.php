<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Forms\Services\TurnstileVerifier;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Tenancy\Context\SiteLocale;
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
 */
final readonly class SubmitForm
{
    public function __construct(private TurnstileVerifier $turnstile) {}

    /**
     * @param  array<string, mixed>  $data  validated field values (no honeypot / token)
     * @param  array{ip?: string|null, user_agent?: string|null, source_url?: string|null}  $meta
     */
    public function handle(
        Form $form,
        array $data,
        ?string $honeypot = null,
        ?string $turnstileToken = null,
        array $meta = [],
    ): FormSubmission {
        $ip = $meta['ip'] ?? null;
        $isSpam = $this->isSpam($honeypot, $turnstileToken, $ip);

        // Cap to the column limits at the storage boundary. name/email/phone are validated upstream,
        // but source_url (the Referer) is unbounded — without this an over-long URL trips Postgres'
        // varchar(255) (it rejects, not truncates) and 500s the submission, losing the lead.
        $submission = $form->submissions()->create([
            'data' => $data,
            'name' => $this->cap($data['name'] ?? null, 255),
            'email' => $this->cap($data['email'] ?? null, 255),
            'phone' => $this->cap($data['phone'] ?? null, 32),
            'source_url' => $this->cap($meta['source_url'] ?? null, 255),
            'ip_address' => $ip,
            'user_agent' => $meta['user_agent'] ?? null,
            'status' => $isSpam ? 'spam' : 'new',
            'is_spam' => $isSpam,
        ]);

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
            app(TenantMailer::class)->apply();
            // The owner reads the lead in the SITE's language, whatever language the visitor used.
            Notification::route('mail', $recipients)
                ->notify((new NewLeadNotification($submission, $form))->locale(app(SiteLocale::class)->default()));
        } catch (Throwable $e) {
            // Best-effort: a mail/transport failure must not 500 the storefront or lose the lead.
            report($e);
        }
    }
}
