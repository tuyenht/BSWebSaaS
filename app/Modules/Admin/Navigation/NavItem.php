<?php

namespace App\Modules\Admin\Navigation;

/**
 * One resolved admin nav entry, built by AdminNav from the entitled + permitted modules
 * (feature module from the catalog, or a content slot from a content_section) — so the
 * menu is data-driven, not hard-coded. `active` is the resolved current-route highlight.
 */
final readonly class NavItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public ?string $icon = null,
        public bool $active = false,
    ) {}
}
