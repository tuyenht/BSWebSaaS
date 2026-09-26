<?php

declare(strict_types=1);

namespace App\Modules\Cms\Data;

use App\Modules\Cms\Models\MenuItem;

/**
 * What one menu item points at and how it reads, as the Menus screen submits it. Exactly one
 * target field matters for a given link type: `targetId` for entry / taxonomy / product (and any
 * kind a module registers), `url` for an external or site-relative link, `dynamicKey` for a
 * computed link such as 'home'. `labels` maps an editing language to its label; an empty label
 * removes it (the website then shows the target's own name) and a language left out is untouched.
 * `parent` places a NEW item — moving an existing one is MoveMenuItem's job.
 */
final readonly class MenuItemData
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $linkType,
        public ?int $targetId = null,
        public ?string $url = null,
        public ?string $dynamicKey = null,
        public array $labels = [],
        public bool $newTab = false,
        public ?MenuItem $parent = null,
    ) {}
}
