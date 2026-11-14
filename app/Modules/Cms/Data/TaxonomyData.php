<?php

declare(strict_types=1);

namespace App\Modules\Cms\Data;

/**
 * A category's editable fields, as the Categories screen submits them. `names` and `slugs` map an
 * editing language to its value: a language left out keeps what it has; a slug left out (or empty)
 * keeps the current one, or — for a language the category is not written in yet — is made from the
 * name. The two templates are theme layout names; null means the theme's default layout.
 *
 * `descriptions`, `seoTitles` and `seoDescriptions` work like `names`: per language, a language
 * left out keeps what it has, an empty value clears it (TaxonomyRules::description / seoText say
 * what is accepted). `cover` is the picture shared by every language, named by its media key
 * (`media.storage_key`, never an id): null keeps the current picture, '' removes it. `cardLabels`
 * (the short label the category's cards show instead of its name) works like `descriptions`
 * (TaxonomyRules::cardLabel says what is accepted).
 */
final readonly class TaxonomyData
{
    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $slugs
     * @param  array<string, string>  $descriptions
     * @param  array<string, string>  $seoTitles
     * @param  array<string, string>  $seoDescriptions
     * @param  array<string, string>  $cardLabels
     */
    public function __construct(
        public array $names = [],
        public array $slugs = [],
        public ?string $listTemplate = null,
        public ?string $detailTemplate = null,
        public array $descriptions = [],
        public array $seoTitles = [],
        public array $seoDescriptions = [],
        public ?string $cover = null,
        public array $cardLabels = [],
    ) {}
}
