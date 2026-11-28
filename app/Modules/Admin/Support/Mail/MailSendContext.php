<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

/**
 * What TenantMailer::apply() last set up for the mail about to leave, read by the MessageSending and
 * MessageSent listeners (ReserveMailQuota, RecordTenantMail). Bound scoped: Octane forgets it between
 * requests and the queue worker between jobs, so nothing here survives into the next request or job.
 *
 *  - via: the outbox the mail goes through (`platform`, the shared one, or `own`, the website's).
 *    When nothing could be set up and the log mailer was forced, the outbox that was meant to send.
 *  - mailer: the mailer apply() made the default (`smtp`, `admin_xoauth2` or `log`). A listener acts
 *    only on a message of that mailer, so a mail on another one (the operator notices' `monitoring`
 *    mailer, a test send's own) is never counted for the website.
 *  - fallbackReason: why the log mailer was forced (`refused_host` or `config`).
 *  - reserved: a place on the website's day was reserved for this mail (counted already).
 *  - guarded: the mail is sent by a queued job under the MailQuota middleware, which can hold it back.
 *  - accountMail: applyPlatform() set it up, for a mail carrying a link or code that acts on the
 *    person's account on every website. SetTenantReplyTo then never adds the website's Reply-To, so
 *    a reply quoting the link or code cannot reach the website's owner.
 */
final class MailSendContext
{
    public ?string $via = null;

    public ?string $mailer = null;

    public ?string $fallbackReason = null;

    public bool $reserved = false;

    public bool $guarded = false;

    public bool $accountMail = false;

    /** A refusal of the website's own mail server noted while apply() runs, read once at its end. */
    public bool $hostRefused = false;

    /** apply() starts setting up a mailer: whatever the previous mail left is forgotten. */
    public function begin(): void
    {
        $this->via = null;
        $this->mailer = null;
        $this->fallbackReason = null;
        $this->reserved = false;
        $this->hostRefused = false;
        $this->accountMail = false;
    }

    public function applied(string $via, string $mailer, ?string $fallbackReason = null): void
    {
        $this->via = $via;
        $this->mailer = $mailer;
        $this->fallbackReason = $fallbackReason;
        $this->reserved = false;
    }

    /**
     * Whether a mail event's data is a website notification sent on the mailer apply() set up.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function covers(array $data): bool
    {
        return $this->mailer !== null
            && $this->via !== null
            && is_string($data['__laravel_notification'] ?? null)
            && ($data['mailer'] ?? null) === $this->mailer;
    }
}
