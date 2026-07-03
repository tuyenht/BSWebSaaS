<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

/**
 * One resolved admin nav entry. Built from a content_section the current user is
 * entitled to + permitted for (AdminNav), so the menu is data-driven, not hard-coded.
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
