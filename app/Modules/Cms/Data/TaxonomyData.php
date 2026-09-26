<?php

declare(strict_types=1);

namespace App\Modules\Cms\Data;

/**
 * A category's editable fields, as the Categories screen submits them. `names` and `slugs` map an
 * editing language to its value: a language left out keeps what it has; a slug left out (or empty)
 * keeps the current one, or — for a language the category is not written in yet — is made from the
 * name. The two templates are theme layout names; null means the theme's default layout.
 */
final readonly class TaxonomyData
{
    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $slugs
     */
    public function __construct(
        public array $names = [],
        public array $slugs = [],
        public ?string $listTemplate = null,
        public ?string $detailTemplate = null,
    ) {}
}
