<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A move of the website's Home page to the Trash, refused whatever path asked for it (a screen, a
 * bulk run, a direct call): the website would be left without a Home page. A refusal of the
 * request (403 when nothing catches it), carrying the sentence a member reads.
 */
final class HomePageLockedException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(__('entries.home_trash_why'));
    }
}
