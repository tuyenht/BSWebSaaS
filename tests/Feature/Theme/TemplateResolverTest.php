<?php

use App\Modules\Theme\Rendering\TemplateResolver;

/*
 * Template fallback chain (design.md §188): override item → category → type → theme.
 */

it('resolves the layout via the override → category → type → theme chain', function (): void {
    $resolver = new TemplateResolver;

    expect($resolver->layoutFor('page'))->toBe('theme::layouts.page')
        ->and($resolver->layoutFor('post'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('post', null, 'news_detail'))->toBe('theme::layouts.news_detail')
        ->and($resolver->layoutFor('page', 'landing'))->toBe('theme::layouts.landing')
        // override beats category beats type.
        ->and($resolver->layoutFor('post', 'special', 'news_detail'))->toBe('theme::layouts.special')
        // category type + its list_template.
        ->and($resolver->layoutFor('category'))->toBe('theme::layouts.category')
        ->and($resolver->layoutFor('category', null, 'news_list'))->toBe('theme::layouts.news_list');
});
