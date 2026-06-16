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
}
