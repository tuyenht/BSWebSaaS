<?php

namespace App\Modules\Theme\Components;

use App\Modules\Shared\Contracts\Component;

/**
 * A static component: its props ARE the content (hero, richtext, features, cta).
 * Dynamic, data-bound components implement Component directly in their own module.
 */
final class SectionComponent implements Component
{
    /**
     * @param  array<string, array<string, mixed>>  $schema  §E7 typed prop schema
     */
    public function __construct(
        private readonly string $view,
        private readonly array $schema = [],
    ) {}

    public function view(): string
    {
        return $this->view;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        return $this->schema;
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
