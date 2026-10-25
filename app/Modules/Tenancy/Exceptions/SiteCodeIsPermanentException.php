<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use LogicException;

/**
 * A website's code was about to change. The code prefixes every media storage key the site owns and
 * is never reissued, so changing it would orphan the site's files or hand them to another site. The
 * database refuses it too (the application role has no UPDATE on the column); this stops the write
 * before it gets there, with a clear reason.
 */
final class SiteCodeIsPermanentException extends LogicException
{
    public function __construct()
    {
        parent::__construct('A website code is permanent and cannot be changed.');
    }
}
