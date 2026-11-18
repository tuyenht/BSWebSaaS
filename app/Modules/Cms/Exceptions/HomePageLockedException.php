<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A move that would leave the website without its Home page, refused whatever path asked for it (a
 * screen, a bulk run, a direct call): a move to the Trash, or a schedule that takes the live Home page
 * off until its time. A refusal of the request (403 when nothing catches it), carrying the sentence a
 * member reads.
 */
final class HomePageLockedException extends AuthorizationException
{
    /** @param  'entries.home_trash_why'|'entries.home_sched_why'  $reason  the lang key of the sentence */
    public function __construct(string $reason = 'entries.home_trash_why')
    {
        parent::__construct(__($reason));
    }
}
