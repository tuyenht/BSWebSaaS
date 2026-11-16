<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

/**
 * One group of results: SiteSearch::PRODUCTS, SiteSearch::OTHER or "c{id}" for a level-1 post category;
 * how many items it holds ($capped: at least that many, the newest items were walked) and its rows in order.
 */
final readonly class SearchGroup
{
    /**
     * @param  list<SearchHit>  $hits
     * @param  bool  $more  rows follow the last one shown
     */
    public function __construct(
        public string $key,
        public int $count,
        public bool $capped,
        public array $hits,
        public bool $more,
    ) {}

    /** The level-1 post category's id of a category group, else null. */
    public function rootId(): ?int
    {
        return preg_match('/^c([1-9][0-9]*)$/', $this->key, $m) === 1 ? (int) $m[1] : null;
    }
}
