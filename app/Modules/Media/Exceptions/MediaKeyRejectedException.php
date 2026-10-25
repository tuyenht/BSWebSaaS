<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * A storage key that is not of the media key format, or that names another website's files. Raised
 * by application code that builds or reads keys; nothing a person uploading can fix, so the upload
 * endpoints log it and show their plain "try again" (it is a RuntimeException for that reason).
 */
final class MediaKeyRejectedException extends RuntimeException
{
    public static function malformed(string $what, string $value): self
    {
        return new self("Not a valid media {$what}: \"{$value}\".");
    }

    public static function foreignSite(string $key): self
    {
        return new self("Media storage key \"{$key}\" belongs to another website.");
    }
}
