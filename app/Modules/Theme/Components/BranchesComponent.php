<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

use App\Modules\Shared\Contracts\Component;
use App\Modules\Theme\Chrome\BranchMap;

/**
 * Data-bound block: the site's branches and factories (Settings › storefront footer) as a row of
 * cards with a photo, name, area, role and address — one source for the footer, the contact page's
 * map and this list. The block holds only a heading, a line under it and the shared display group;
 * the branches themselves are never copied into the page. The pictures come in one batched lookup.
 * BranchMap is resolved per draw, not held: it reads the request's own (scoped) settings, and this
 * component lives in the registry singleton for the whole worker.
 */
final class BranchesComponent implements Component
{
    public function view(): string
    {
        return 'theme::sections.branches';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        return [
            'heading' => ['type' => 'text'],
            'description' => ['type' => 'textarea'],
        ] + SectionProps::LIST_LAYOUT;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        return ['props' => $props, 'branches' => app(BranchMap::class)->cards(app()->getLocale())];
    }
}
