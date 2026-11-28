<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Admin\Support\Mail\MailTemplateMail;
use App\Modules\Admin\Support\Mail\MailTemplates;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The invitation mail: who invites the recipient to which site, with what roles, until when —
 * and one button to the invitation page. The same mail goes to every address, account or not;
 * the page decides the road (sign in, or create the account), so nothing in the mail or in its
 * timing tells the inviter anything about the address.
 *
 * Queued (ShouldQueue) so the inviter's reply returns at once. Everything is composed AT DISPATCH,
 * inside the request: a queue worker has no request host to build the link's absolute URL, and no
 * site context to read names from. Worker plumbing mirrors ResetPasswordLinkNotification:
 * WithinSite isolation + re-applied tenant mailer + bounded retries + audited final failure.
 */
final class MemberInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    /**
     * @param  list<array{name: string, system: bool}>  $roles  labelled in toMail(), i.e. in the mail's own locale
     */
    public function __construct(
        private readonly string $acceptUrl,
        private readonly string $siteName,
        private readonly string $inviterName,
        private readonly array $roles,
        private readonly ?string $personalMessage,
        private readonly string $expiresOn,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function failed(Throwable $e): void
    {
        // Class only, never the message — the full exception lands in failed_jobs; SMTP detail
        // must not leak into the audit trail.
        Audit::record('rbac.invitation.send_failed', [
            'meta' => ['site_id' => $this->tenantSiteId, 'error' => $e::class],
        ]);
        $this->recordMailFailure($e);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // «Lời mời thành viên» turned off on the website's «Mẫu thư» tab: no mail (read where it is sent, in the website's request).
        return app(MailTemplates::class)->isOff('team_invite') ? [] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();

        $roles = implode(', ', array_map(
            static fn (array $role): string => RoleLabel::forName($role['name'], $role['system']),
            $this->roles,
        ));
        // The website's (or the platform's) wording of «Lời mời thành viên» when it has one.
        $templated = app(MailTemplateMail::class)->message('team_invite', [
            '{inviter_name}' => $this->inviterName,
            '{site_name}' => $this->siteName,
            '{roles}' => $roles,
            '{invite_message}' => $this->personalMessage ?? '',
            '{accept_url}' => $this->acceptUrl,
            '{expires_at}' => $this->expiresOn,
        ]);
        if ($templated !== null) {
            return $templated;
        }

        $mail = (new MailMessage)
            ->subject(__('rbac.invitation.mail_subject', ['site' => $this->siteName]))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__('rbac.invitation.mail_intro', [
                'inviter' => MailText::plain($this->inviterName),
                'site' => MailText::plain($this->siteName),
                'roles' => implode(', ', array_map(
                    static fn (array $role): string => MailText::plain(RoleLabel::forName($role['name'], $role['system'])),
                    $this->roles,
                )),
            ]));

        if ($this->personalMessage !== null) {
            $mail->line('“'.MailText::plain($this->personalMessage).'”');
        }

        return $mail
            ->action(__('rbac.invitation.mail_action'), $this->acceptUrl)
            ->line(__('rbac.invitation.mail_expiry', ['date' => $this->expiresOn]))
            ->line(__('rbac.invitation.mail_ignore'));
    }
}
