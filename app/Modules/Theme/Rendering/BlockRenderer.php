<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders a Page-JSON body ({"blocks":[{type, props}, …]}) to SSR HTML. Each block
 * is dispatched to its registered Component (static or data-bound); the component
 * resolves its view data, then the theme section partial renders it. Theme runs NO
 * customer code (ADR-04): only registered (allow-listed) types render; props are
 * Blade-escaped; unknown/malformed blocks are skipped (fail-safe).
 */
final class BlockRenderer
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly ComponentRegistry $registry,
    ) {}

    public function allows(string $type): bool
    {
        return $this->registry->has($type);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function render(array $body): string
    {
        $blocks = is_array($body['blocks'] ?? null) ? $body['blocks'] : [];
        $html = '';

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                continue; // allow-list gate
            }

            /** @var array<string, mixed> $props */
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $component = $this->registry->get($type);

            $html .= $this->views->make($component->view(), $component->data($props))->render();
        }

        return $html;
    }
}
