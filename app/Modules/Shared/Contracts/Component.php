<?php

namespace App\Modules\Shared\Contracts;

/**
 * A page component (a Page-JSON block). Static components return their props as the
 * view data; dynamic components resolve data from other objects (posts, products,
 * banners…) at render time. Lives in Shared\Contracts so any module can implement
 * and register one without the Theme depending on that module (acyclic boundary).
 */
interface Component
{
    /** The theme section view that renders this component. */
    public function view(): string;

    /**
     * Resolve the view data from the block props (may query other objects).
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function data(array $props): array;

    /**
     * The author-facing prop schema (§E7): prop name → spec. Each spec has a `type`
     * (text|textarea|url|int|list); a `list` adds `of` = the item schema (a scalar spec
     * like ['type'=>'text'], or a map of fields for object items). One declaration drives
     * the editor inputs, the repeater, the default props, and per-prop handling.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array;
}
