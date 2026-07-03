<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;

/**
 * A resolved storefront request: the published entry + its published translation
 * for the requested locale.
 */
final readonly class ResolvedContent
{
    public function __construct(
        public Entry $entry,
        public EntryTranslation $translation,
    ) {}
}
