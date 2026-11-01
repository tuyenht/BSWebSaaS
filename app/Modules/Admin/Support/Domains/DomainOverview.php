<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Domains;

/**
 * A site's names as its Domains screen lists them, with what counts against the plan: `used` is
 * the number of the customer's own names (a name and its `www.` spelling count once, a stopped
 * name still counts), `lost` how many of them stopped, `primary` the host visitors land on.
 */
final readonly class DomainOverview
{
    /**
     * @param  list<DomainGroup>  $groups
     */
    public function __construct(
        public array $groups,
        public int $used,
        public int $lost,
        public ?string $primary,
    ) {}

    public function find(string $name): ?DomainGroup
    {
        foreach ($this->groups as $group) {
            if (! $group->platform && $group->name === $name) {
                return $group;
            }
        }

        return null;
    }

    /** A certificate is on its way somewhere: the screen looks again shortly. */
    public function pending(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->state === 'issuing') {
                return true;
            }
        }

        return false;
    }

    /** @return list<DomainGroup> */
    public function custom(): array
    {
        return array_values(array_filter($this->groups, fn (DomainGroup $group): bool => ! $group->platform));
    }
}
