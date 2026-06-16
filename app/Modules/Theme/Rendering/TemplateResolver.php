<?php

namespace App\Modules\Theme\Rendering;

/**
 * Resolves which theme layout renders an entry (design.md §8 fallback chain:
 * override item → category → type → theme). Category-driven templates land with
 * taxonomy; for now the chain is override → type → theme default.
 */
final class TemplateResolver
{
    public function layoutFor(string $type, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return "theme::layouts.{$override}";
        }

        return match ($type) {
            'post' => 'theme::layouts.post',
            default => 'theme::layouts.page',
        };
    }
}
