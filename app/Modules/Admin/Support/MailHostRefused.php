<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use RuntimeException;

/**
 * Reported (never thrown) when a site's own SMTP server is refused right before a send: the mail
 * goes to the log instead, and the operator sees why in the error log.
 */
final class MailHostRefused extends RuntimeException
{
    public function __construct(string $host, int $port, string $reason, private readonly ?int $siteId = null)
    {
        parent::__construct(sprintf('Site SMTP server refused (%s): %s:%d', $reason, $host, $port));
    }

    /** @return array{site_id: int|null} which site's sender was held back, for the error log */
    public function context(): array
    {
        return ['site_id' => $this->siteId];
    }
}
