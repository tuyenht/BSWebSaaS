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
    /** The list layouts that show cards (the controller loads card data only for these). */
    public const array CARD_LIST_TEMPLATES = ['grid-product', 'grid-project', 'grid-news'];

    /**
     * Whether a category's list layout shows titles (the default list) or cards. Pure.
     *
     * @return 'titles'|'cards'
     */
    public function listShape(?string $listTemplate): string
    {
        return in_array($listTemplate, self::CARD_LIST_TEMPLATES, true) ? 'cards' : 'titles';
    }

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
            'category' => 'theme::layouts.category',
            'product' => 'theme::layouts.product',
            default => 'theme::layouts.page',
        };
    }
}
