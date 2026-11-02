<?php

namespace App\Modules\Theme\Components;

use App\Modules\Shared\Contracts\Component;

/**
 * The Section Library: maps block types → components. Modules register their own
 * components (Cms content, Commerce products, …), so a page composes components
 * sourced from different objects. The registered set IS the render allow-list.
 */
final class ComponentRegistry
{
    /** @var array<string, Component> */
    private array $components = [];

    public function register(string $type, Component $component): void
    {
        $this->components[$type] = $component;
    }

    public function has(string $type): bool
    {
        return isset($this->components[$type]);
    }

    public function get(string $type): Component
    {
        return $this->components[$type];
    }

    /**
     * A type's full §E7 schema: its own props plus the look props every block shares (StyleProps).
     * Validation, rendering, the media index and the editor read a block's props through here, so
     * the look props are checked and resolved like any other prop.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemaFor(string $type): array
    {
        return $this->components[$type]->schema() + StyleProps::SCHEMA;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->components);
    }
}
