<?php

namespace App\Modules\Theme\Components;

use App\Modules\Shared\Contracts\Component;

/**
 * A static component: its props ARE the content (hero, richtext, features, cta).
 * Dynamic, data-bound components implement Component directly in their own module.
 */
final class SectionComponent implements Component
{
    public function __construct(private readonly string $view) {}

    public function view(): string
    {
        return $this->view;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        return ['props' => $props];
    }
}
