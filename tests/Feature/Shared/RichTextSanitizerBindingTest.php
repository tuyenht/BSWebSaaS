<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Shared\Providers\SharedServiceProvider;
use App\Modules\Shared\Support\RichTextSanitizer;
use Illuminate\Container\Container;

/*
 * The sanitizer builds one HTMLPurifier per profile, which is the expensive part: it is meant to be
 * built once per worker and shared by every module that cleans HTML (catalog descriptions, the page
 * body casts, the storefront's print check, content packs). The binding lives with the class in
 * Shared, so no consumer's provider has to be loaded for the others to get the shared instance.
 */

it('gives every module the same sanitizer instance', function (): void {
    expect(app()->isShared(RichTextSanitizer::class))->toBeTrue()
        ->and(app(RichTextSanitizer::class))->toBe(app(RichTextSanitizer::class));
});

it('shares the sanitizer with only the Shared provider registered, so no other module is needed for it', function (): void {
    // A bare container (not the application, whose constructor would replace the global instance).
    $app = new Container;
    (new SharedServiceProvider($app))->register();

    expect($app->isShared(RichTextSanitizer::class))->toBeTrue()
        ->and($app->make(RichTextSanitizer::class))->toBe($app->make(RichTextSanitizer::class));
});

it('is bound by the Shared provider only, not by a consumer module', function (): void {
    $app = new Container;
    (new CatalogServiceProvider($app))->register();

    expect($app->isShared(RichTextSanitizer::class))->toBeFalse();
});
