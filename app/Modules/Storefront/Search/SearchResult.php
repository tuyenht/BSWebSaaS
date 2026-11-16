<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Search;

/** What a search found: its groups (the first page), or the one group continued ($continued). */
final readonly class SearchResult
{
    /** @param list<SearchGroup> $groups */
    public function __construct(
        public array $groups,
        public bool $continued,
    ) {}

    /** How many items were found in all, and whether that is a lower bound. */
    public function total(): int
    {
        return array_sum(array_map(static fn (SearchGroup $group): int => $group->count, $this->groups));
    }

    public function capped(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->capped) {
                return true;
            }
        }

        return false;
    }
}
