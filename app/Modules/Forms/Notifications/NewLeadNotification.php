<?php

namespace App\Modules\Forms\Notifications;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadAnswers;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the form's recipients (forms.notify_emails) that a new lead arrived. Sent via the
 * resolved tenant mailer (SubmitForm applies it before dispatch) in the site's locale. The
 * lead's answers are listed under the form's field labels (LeadAnswers); spam is never sent.
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
            ->line(__('forms.lead_email_intro', ['form' => MailText::plain($formName)]));

        // Reply-To the lead's own address, so a recipient can reply straight to the customer
        // (the From is the platform/tenant sender, which a reply would otherwise hit).
        $email = $this->submission->email;
        if (is_string($email) && $email !== '') {
            $mail->replyTo($email, Cast::string($this->submission->name));
        }

        foreach ($this->leadLines() as $line) {
            // What a visitor typed is shown as typed — never as a link, an image or a heading; a site's
            // field label is the site's own text, so it is kept plain too.
            $mail->line('**'.MailText::plain($line['label']).':** '.MailText::plain($line['value']));
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
     * Label → value for every answer the visitor gave, in the form's order, skipping empties. A
     * ticked consent box is evidence kept on the lead's page, not something to act on, so it is left
     * out of the email.
     *
     * @return list<array{label: string, value: string}>
     */
    private function leadLines(): array
    {
        $answers = LeadAnswers::for($this->form, Cast::arr($this->submission->data), app()->getLocale());

        return array_values(array_map(
            static fn (array $answer): array => ['label' => $answer['label'], 'value' => $answer['value']],
            array_filter($answers, static fn (array $answer): bool => ! $answer['ticked']),
        ));
    }
}
