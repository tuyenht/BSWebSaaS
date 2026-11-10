<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use DomainException;

/**
 * An outbound request SafeHttp refused or could not make. The message IS the reason key, one of
 * REASONS; each caller turns it into its own words (the media picker, the SEO check). `$host` names
 * where a redirect wanted to go when it left the host it was told to stay on.
 */
final class SafeHttpException extends DomainException
{
    public const array REASONS = [
        'url_invalid', 'host_unresolvable', 'private_address', 'redirect_invalid', 'too_many_redirects',
        'redirect_off_host', 'fetch_unreachable',
    ];

    public function __construct(public readonly string $reason, public readonly ?string $host = null)
    {
        parent::__construct($reason);
    }
}
