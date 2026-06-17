<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;

/**
 * A resolved storefront category request: the taxonomy + its localized translation
 * (name/slug). The posts list is fetched by the caller (pagination depends on the
 * request), keeping this a thin, immutable identity.
 */
final readonly class ResolvedCategory
{
    public function __construct(
        public Taxonomy $taxonomy,
        public TaxonomyTranslation $translation,
    ) {}
}
