<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

/**
 * What happens to the sub-categories of a category being deleted: they move up to its parent
 * (taking its place among the siblings), or they are deleted with it.
 */
enum CategoryChildrenOnDelete: string
{
    case Lift = 'lift';
    case Delete = 'delete';
}
