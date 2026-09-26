<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

/**
 * What happened to categories, which decides whose public paths can have changed (CategoryRelinker):
 *  - Renamed: a slug changed — paths carrying that slug (category / category_path presets);
 *  - Moved:   the ancestors changed — paths carrying the chain (category_path), and for posts any
 *             non-flat preset, since the section a post belongs to follows its category's ancestors;
 *  - Refiled: items changed primary category (a category was deleted) — like a move for posts.
 */
enum CategoryChange
{
    case Renamed;
    case Moved;
    case Refiled;
}
