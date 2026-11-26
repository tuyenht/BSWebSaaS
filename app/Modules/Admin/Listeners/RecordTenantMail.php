<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Support\Mail\MailKinds;
use App\Modules\Admin\Support\Mail\MailLogWriter;
use App\Modules\Admin\Support\Mail\MailQuotaGate;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Once a website's notification mail has left: its «Thư gần đây» row and its day's count, and one
 * more on the platform's day when it went through the shared outbox. A mail that went to the log
 * mailer (nothing could be set up, or the website's own server is not allowed) never reached anyone:
 * it is logged as failed, with that reason. Only the mail apply() set up is recorded: a test send
 * (no notification) and mail on another mailer (the operator notices) are not the website's.
 */
final readonly class RecordTenantMail
{
    public function handle(MessageSent $event): void
    {
        $context = app(MailSendContext::class);
        if (! app(CurrentSite::class)->hasId() || ! $context->covers($event->data)) {
            return;
        }

        $kind = MailKinds::of(Cast::string($event->data['__laravel_notification'] ?? null));
        $id = $event->data['__laravel_notification_id'] ?? null;
        $id = is_string($id) ? $id : null;
        $via = Cast::string($context->via, 'platform');
        $recipients = $this->recipients($event);
        $writer = app(MailLogWriter::class);

        if ($context->mailer === 'log') {
            $writer->failed($kind, $via, $context->fallbackReason ?? 'config', $recipients, $id);
        } else {
            $sent = $writer->sent($kind, $via, $recipients, $id, ! $context->reserved);
            if ($via === 'platform') {
                $gate = app(MailQuotaGate::class);
                $gate->countPlatform();
                if ($sent !== null) {
                    $gate->thresholds($sent);
                }
            }
        }

        // The place taken for this mail is used: a later mail of the same request takes its own.
        $context->reserved = false;
    }

    /** @return list<string> */
    private function recipients(MessageSent $event): array
    {
        try {
            $addresses = $event->sent->getEnvelope()->getRecipients();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(fn (Address $address): string => $address->getAddress(), $addresses));
    }
}
