<?php

namespace App\Modules\Theme\Rendering;

/**
 * Resolves which theme layout renders an entry (design.md §8/§188 fallback chain:
 * override item → category → type → theme). Stays Cms-agnostic — the caller resolves
 * the entry's category template name and passes it in, so the Theme module never
 * depends on Cms/Taxonomy (acyclic boundary).
 */
final class TemplateResolver
{
    public function layoutFor(string $type, ?string $override = null, ?string $categoryTemplate = null): string
    {
        if ($override !== null && $override !== '') {
            return "theme::layouts.{$override}";
        }

        if ($categoryTemplate !== null && $categoryTemplate !== '') {
            return "theme::layouts.{$categoryTemplate}";
        }

        return match ($type) {
            'post' => 'theme::layouts.post',
            default => 'theme::layouts.page',
        };
    }
}
