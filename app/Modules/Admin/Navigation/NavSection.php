<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

/**
 * One headed group of the sidebar: a heading over the links it groups. A section with no visible
 * link is never built, so a heading never stands alone. $label = null renders the links without
 * a heading (the top of the menu).
 */
final readonly class NavSection
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public string $key,
        public ?string $label,
        public array $items,
    ) {}
}
