<?php

namespace App\Modules\Forms\Notifications;

use App\Modules\Admin\Support\Cast;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the form's recipients (forms.notify_emails) that a new lead arrived. Sent via the
 * resolved tenant mailer (SubmitForm applies it before dispatch) in the site's locale. The
 * lead's fields are rendered from the typed columns + the submission data; spam is never sent.
 */
final class NewLeadNotification extends Notification
{
    public function __construct(
        private readonly FormSubmission $submission,
        private readonly Form $form,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formName = $this->localizedFormName();

        $mail = (new MailMessage)
            ->subject(__('forms.lead_email_subject', ['form' => $formName]))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('forms.lead_email_intro', ['form' => $formName]));

        // Reply-To the lead's own address, so a recipient can reply straight to the customer
        // (the From is the platform/tenant sender, which a reply would otherwise hit).
        $email = $this->submission->email;
        if (is_string($email) && $email !== '') {
            $mail->replyTo($email, Cast::string($this->submission->name));
        }

        foreach ($this->leadLines() as $label => $value) {
            $mail->line("**{$label}:** {$value}");
        }

        return $mail->line(__('forms.lead_email_outro'));
    }

    private function localizedFormName(): string
    {
        $names = Cast::arr($this->form->name);

        return Cast::string(
            $names[app()->getLocale()] ?? $names['vi'] ?? null,
            __('forms.lead_default_name'),
        );
    }

    /**
     * Label → value for the fields a recipient cares about, skipping empties.
     *
     * @return array<string, string>
     */
    private function leadLines(): array
    {
        $data = Cast::arr($this->submission->data);
        $lines = [];

        foreach (['name', 'email', 'phone', 'message'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $lines[Cast::string(__("forms.lead_field_{$key}"))] = $value;
            }
        }

        return $lines;
    }
}
