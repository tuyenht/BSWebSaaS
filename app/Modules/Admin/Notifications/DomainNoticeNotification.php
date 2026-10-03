<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Contracts\ToSiteDatabase;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Shared\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A website's own domain needs its managers (design.md §28): it stopped pointing here, it will
 * stop being served in a week, it stopped being served, it points here again, a name nobody
 * pointed was dropped — or another site proved it owns the name and took it. Both spellings of a
 * name (with and without `www.`) travel in one notice.
 *
 * The warnings also go by mail; good news and an expired wait only ring the bell. A takeover is a
 * security notice that cannot be switched off; the rest follow each person's choice.
 */
final class DomainNoticeNotification extends Notification implements ShouldQueue, ToSiteDatabase
{
    use Queueable;
    use QueuesTenantMail;

    public const array MAILED = ['failing', 'expiring', 'lost', 'taken_over'];

    public int $tries = 3;

    /**
     * @param  'failing'|'expiring'|'lost'|'recovered'|'wait_expired'|'taken_over'  $variant
     * @param  list<string>  $hostnames
     * @param  string|null  $until  the day the domain stops being served, in the site's time
     */
    public function __construct(
        public readonly string $variant,
        public readonly array $hostnames,
        public readonly ?string $until = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function kind(): string
    {
        return $this->variant === 'taken_over' ? 'security.domain_taken_over' : 'system.domain_problem';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $channels = [];

        if (NotificationPreferences::wants($notifiable, $this->kind(), 'in_app')) {
            $channels[] = SiteDatabaseChannel::class;
        }

        if (in_array($this->variant, self::MAILED, true) && NotificationPreferences::wants($notifiable, $this->kind(), 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toSiteDatabase(object $notifiable): array
    {
        // The domain-problem kind reads one sentence per variant; a takeover has a kind of its own.
        $sentence = $this->variant === 'taken_over' ? [] : ['sentence' => $this->variant];

        return ['kind' => $this->kind(), 'data' => [...$sentence, ...$this->facts()]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();
        $facts = $this->facts();

        return (new MailMessage)
            ->subject(__("notifications.mail.domain_{$this->variant}_subject", $facts))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__("notifications.mail.domain_{$this->variant}_intro", $facts))
            ->line(__("notifications.mail.domain_{$this->variant}_action", $facts));
    }

    /**
     * @return array{domains: string, until: string, target: string}
     */
    private function facts(): array
    {
        return [
            'domains' => implode(', ', $this->hostnames),
            'until' => $this->until ?? '',
            'target' => Cast::string(config('tenancy.custom_domains.target')),
        ];
    }
}
