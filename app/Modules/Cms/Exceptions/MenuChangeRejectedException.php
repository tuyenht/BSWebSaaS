<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A change to a site's menus refused for a BUSINESS reason the caller should explain in plain
 * words. Its own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason
 * key, one of: name_invalid · locale_invalid · item_invalid · target_invalid · url_invalid ·
 * label_invalid · parent_invalid · depth_exceeded · region_invalid · menu_in_use.
 */
final class MenuChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
