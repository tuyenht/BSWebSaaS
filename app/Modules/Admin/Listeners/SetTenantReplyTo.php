<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Mail\Events\MessageSending;

/**
 * Stamps the tenant's configured reply-to onto an outgoing message so a recipient's reply reaches
 * the CUSTOMER, not the shared platform inbox — even when the mail is sent through the shared SMTP.
 * Read at SEND time from the current site's `email.reply_to` setting (stateless — no Octane reply-to
 * leak, unlike Mail::alwaysReplyTo). No-op off the tenant plane, when unset, or when the message
 * already carries an explicit Reply-To. Registered on Illuminate\Mail\Events\MessageSending.
 */
final readonly class SetTenantReplyTo
{
    public function __construct(
        private CurrentSite $current,
        private SettingsRepository $settings,
    ) {}

    public function handle(MessageSending $event): void
    {
        if (! $this->current->hasId()) {
            return;
        }

        $cfg = $this->settings->get('email');
        $replyTo = is_array($cfg) ? ($cfg['reply_to'] ?? null) : null;
        if (! is_string($replyTo) || $replyTo === '') {
            return;
        }

        // An explicit Reply-To already on the message wins (never override a caller's choice).
        if ($event->message->getReplyTo() !== []) {
            return;
        }

        $event->message->replyTo($replyTo);
    }
}
