<?php

use App\Modules\Theme\Rendering\TemplateResolver;

/*
 * Template fallback chain (design.md §188): override item → category → type → theme.
 */

it('resolves the layout via the override → category → type → theme chain', function (): void {
    $resolver = new TemplateResolver;

    expect($resolver->layoutFor('page'))->toBe('theme::layouts.page')
        ->and($resolver->layoutFor('post'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('post', null, 'project'))->toBe('theme::layouts.project')
        // override beats category beats type.
        ->and($resolver->layoutFor('post', 'job', 'project'))->toBe('theme::layouts.job')
        // category type + its list_template.
        ->and($resolver->layoutFor('category'))->toBe('theme::layouts.category')
        ->and($resolver->layoutFor('category', null, 'grid-news'))->toBe('theme::layouts.grid-news');
});

it('falls back to the default of the type for a layout of the wrong role or one the theme does not have', function (): void {
    $resolver = new TemplateResolver;

    expect($resolver->layoutFor('post', 'grid-news'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('post', null, 'grid-news'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('page', 'job'))->toBe('theme::layouts.page')
        ->and($resolver->layoutFor('post', 'khong-co'))->toBe('theme::layouts.post')
        ->and($resolver->layoutFor('category', null, 'post'))->toBe('theme::layouts.category')
        ->and($resolver->layoutFor('category', null, 'khong-co'))->toBe('theme::layouts.category')
        ->and($resolver->layoutFor('category', null, '@parent'))->toBe('theme::layouts.category')
        ->and($resolver->layoutFor('page', 'not-found'))->toBe('theme::layouts.page');
});
