<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * The X-Request-Id a request carries comes from the client (or the edge proxy in front of it), so
 * it is kept only when it looks like one — a uuid, a ray id… — and never stored or mailed
 * otherwise. One rule for every reader: the audit trail and the error notices.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    private const string PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    public static function accept(?string $value): ?string
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
