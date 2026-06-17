<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\BlockRenderer;

/*
 * P1.5 — a page composes components from different objects. The Section Library
 * (ComponentRegistry) collects static theme components AND data-bound components
 * registered by modules (Cms latest_posts). Only registered types render.
 */

beforeEach(fn () => seedTenancy());

it('collects components from the theme and from modules into one registry', function () {
    $types = app(ComponentRegistry::class)->types();

    expect($types)->toContain('hero')        // theme static
        ->toContain('cta')                   // theme static
        ->toContain('latest_posts');         // Cms data-bound
});

it('renders a data-bound component sourced from another object (latest posts)', function () {
    app(Tenancy::class)->enter(1, function () {
        foreach (['Bai moi nhat', 'Bai cu hon'] as $i => $title) {
            $post = Entry::create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subDays($i)]);
            $post->translations()->create([
                'locale' => 'vi', 'title' => $title, 'slug' => 'bai-'.$i,
                'status' => 'published', 'body' => ['html' => 'x'],
            ]);
        }

        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'latest_posts', 'props' => ['heading' => 'Tin moi', 'limit' => 5]],
        ]]);

        expect($html)->toContain('Tin moi')
            ->toContain('Bai moi nhat')
            ->toContain('Bai cu hon');
    });
});

it('links each latest post to its canonical route', function () {
    app(Tenancy::class)->enter(1, function () {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

        $post = Entry::create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => 'Bai co route', 'slug' => 'bai-co-route', 'status' => 'published', 'body' => ['blocks' => []],
        ]);
        $post->assignCategories([$category->id]);
        app(RouteSync::class)->sync($post->fresh(), 'vi', 'bai-co-route');

        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'latest_posts', 'props' => ['heading' => 'Tin moi', 'limit' => 5]],
        ]]);

        expect($html)->toContain('href="/tin-tuc/bai-co-route"')->toContain('Bai co route');
    });
});

it('renders a homepage composed of several components', function () {
    app(Tenancy::class)->enter(1, function () {
        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'hero', 'props' => ['heading' => 'An Vinh']],
            ['type' => 'latest_posts', 'props' => ['heading' => 'Tin tuc']],
            ['type' => 'cta', 'props' => ['heading' => 'Lien he', 'button_label' => 'Goi ngay']],
        ]]);

        expect($html)->toContain('class="hero"')
            ->toContain('class="latest-posts"')
            ->toContain('class="cta"');
    });
});
