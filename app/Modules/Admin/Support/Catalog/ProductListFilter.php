<?php

namespace App\Modules\Admin\Support\Catalog;

/**
 * What the products list asks for beyond the status group and the search, already cleaned by the
 * screen: values inside one group are alternatives (OR), the groups together all have to hold (AND).
 */
final readonly class ProductListFilter
{
    /** Translation states, as on the posts list: every language, some of them, one or none. */
    public const array TRANS = ['full', 'partial', 'none'];

    /** The labels a product can carry. */
    public const array TAGS = ['featured', 'best'];

    /** Stock kinds: in stock (more than none), sold out (zero), not tracked (no number). */
    public const array STOCK = ['in', 'out', 'none'];

    /**
     * @param  list<int>  $categoryIds  the chosen categories and everything under them
     * @param  list<string>  $trans  translation states
     * @param  list<string>  $locales  the site's enabled languages (the states count against these)
     * @param  list<string>  $tags  labels (any of)
     * @param  list<string>  $stock  stock kinds (any of)
     * @param  bool  $sale  only the products whose sale price is on
     */
    public function __construct(
        public array $categoryIds = [],
        public array $trans = [],
        public array $locales = [],
        public array $tags = [],
        public array $stock = [],
        public bool $sale = false,
    ) {}

    /** Is any condition on? */
    public function any(): bool
    {
        return $this->categoryIds !== [] || $this->trans !== [] || $this->tags !== [] || $this->stock !== [] || $this->sale;
    }
}
