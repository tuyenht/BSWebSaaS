<?php

declare(strict_types=1);

namespace App\Modules\Theme\Rendering;

/**
 * Resolves which theme layout renders an entry (design.md §8/§188 fallback chain:
 * override item → category → type → theme). Stays Cms-agnostic — the caller resolves
 * the entry's category template name and passes it in, so the Theme module never
 * depends on Cms/Taxonomy (acyclic boundary).
 */
final class TemplateResolver
{
    /**
     * Whether a category's list layout shows titles (the default list) or cards. Pure.
     *
     * @return 'titles'|'cards'
     */
    public function listShape(?string $listTemplate): string
    {
        return TemplateCatalog::cards($listTemplate) !== null ? 'cards' : 'titles';
    }

    /**
     * The layout to render. A name that is not in the theme's template list for the page's role,
     * or that the theme no longer has, is ignored — the page falls back to the default of its type
     * and never errors.
     */
    public function layoutFor(string $type, ?string $override = null, ?string $categoryTemplate = null): string
    {
        $role = match ($type) {
            'category' => TemplateCatalog::ROLE_LIST,
            default => TemplateCatalog::roleOfEntry($type),
        };

        if ($role !== null && $override !== null && $override !== '' && $type !== 'category' && TemplateCatalog::fits($override, $role)) {
            return "theme::layouts.{$override}";
        }

        if ($role !== null && $categoryTemplate !== null && $categoryTemplate !== '' && TemplateCatalog::fits($categoryTemplate, $role)) {
            return "theme::layouts.{$categoryTemplate}";
        }

        return match ($type) {
            'post' => 'theme::layouts.post',
            'category' => 'theme::layouts.category',
            'product' => 'theme::layouts.product',
            default => 'theme::layouts.page',
        };
    }
}
