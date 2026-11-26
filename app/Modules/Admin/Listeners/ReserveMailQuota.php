<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Support\Mail\MailHeld;
use App\Modules\Admin\Support\Mail\MailKinds;
use App\Modules\Admin\Support\Mail\MailQuotaGate;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Mail\Events\MessageSending;

/**
 * Just before a website's mail leaves, once TenantMailer::apply() has picked the real outbox: a mail
 * of an ordinary kind going through the platform's shared outbox, sent under the MailQuota middleware,
 * takes its place on the website's day or is held back (MailHeld, caught by MailQuota). Anything else
 * passes untouched and is counted when it has left (RecordTenantMail).
 */
final readonly class ReserveMailQuota
{
    /** @throws MailHeld */
    public function handle(MessageSending $event): void
    {
        $context = app(MailSendContext::class);
        if (! app(CurrentSite::class)->hasId() || ! $context->covers($event->data) || ! $context->guarded) {
            return;
        }

        if ($context->via !== 'platform' || $context->mailer === 'log') {
            return;
        }

        if (! MailKinds::holdable(MailKinds::of(Cast::string($event->data['__laravel_notification'] ?? null)))) {
            return;
        }

        app(MailQuotaGate::class)->reserve($context);
    }
}
