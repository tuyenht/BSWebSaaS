<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

/**
 * What happens to the entries and products filed under a category being deleted: they move to
 * another category of the same vocabulary (taking the deleted one's rank), or simply lose it.
 */
enum CategoryItemsOnDelete: string
{
    case Reassign = 'reassign';
    case Detach = 'detach';
}
