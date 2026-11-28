<?php

declare(strict_types=1);

namespace App\Modules\Admin\Jobs;

use App\Modules\Admin\Support\Mail\MailDnsCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One run of a website's SPF / DKIM / DMARC check (MailDnsCheck::run), off the request, on the
 * default queue. The website is read and written in short tenant steps of its own, never around
 * the DNS lookups, so this takes no WithinSite middleware (it would hold one transaction across
 * them). While a record is missing the run queues the next one itself, up to the check's deadline.
 * One attempt: a run that fails or runs out of time ends the chain; the owner can check again.
 */
final class CheckEmailDns implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * At most five TXT lookups, each held by the resolver's own timeout; kept well under the
     * queue's retry_after (90s) so a slow run is never handed out a second time.
     */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** Queued once the request's transaction commits: never inside a site's open tenant transaction. */
    public function __construct(public int $siteId, public string $token)
    {
        $this->afterCommit();
    }

    public function handle(MailDnsCheck $check): void
    {
        $check->run($this->siteId, $this->token);
    }

    public function failed(?Throwable $e): void
    {
        app(MailDnsCheck::class)->stop($this->siteId, $this->token);
    }
}
