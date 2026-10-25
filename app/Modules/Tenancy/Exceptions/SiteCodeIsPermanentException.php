<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A website's code was about to be chosen by the caller or changed. The code prefixes every media
 * storage key the site owns and is issued once by the database, which also skips codes that legacy
 * storage keys start with; a chosen code skips those checks, and a changed one orphans the site's files
 * or hands them to another site. The database refuses a change too (the application role has no
 * UPDATE on the column); this stops the write before it gets there, with a clear reason.
 */
final class SiteCodeIsPermanentException extends LogicException
{
    public static function changed(): self
    {
        return new self('A website code is permanent and cannot be changed.');
    }

    public static function chosen(): self
    {
        return new self('A website code is issued by the database and cannot be chosen.');
    }
}
