<?php

namespace App\Modules\Theme\Rendering;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders a Page-JSON body ({"blocks":[{type, props}, …]}) to SSR HTML by mapping
 * each block type to a Section-Library Blade partial. Theme runs NO customer code
 * (ADR-04): only an ALLOW-LISTED set of section types renders; props are escaped by
 * Blade. Unknown/malformed blocks are skipped (fail-safe — never break the page).
 */
final class BlockRenderer
{
    /** type => theme section view */
    private const SECTIONS = [
        'hero' => 'theme::sections.hero',
        'richtext' => 'theme::sections.richtext',
        'features' => 'theme::sections.features',
        'cta' => 'theme::sections.cta',
    ];

    public function __construct(private readonly ViewFactory $views) {}

    public function allows(string $type): bool
    {
        return isset(self::SECTIONS[$type]);
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
            if (! is_string($type) || ! isset(self::SECTIONS[$type])) {
                continue; // allow-list gate
            }

            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $html .= $this->views->make(self::SECTIONS[$type], ['props' => $props])->render();
        }

        return $html;
    }
}
