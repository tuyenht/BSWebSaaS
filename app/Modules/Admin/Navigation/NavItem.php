<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

/**
 * One resolved admin nav entry (data-driven by AdminNav, not hard-coded). A node with
 * children renders as an expandable group (its own link is optional — a pure group has
 * $url = null and only toggles); a leaf carries a $url. $permission is the ability that
 * gates VISIBILITY only — the route itself is the real boundary (defense-in-depth).
 */
final readonly class NavItem
{
    /**
     * @param  list<NavItem>  $children  submenu leaves (present → this node is an expandable group)
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $url = null,
        public ?string $icon = null,
        public bool $active = false,
        public array $children = [],
        public ?string $permission = null,
    ) {}

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }
}
