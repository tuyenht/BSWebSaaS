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

it('collects components from the theme and from modules into one registry', function (): void {
    $types = app(ComponentRegistry::class)->types();

    expect($types)->toContain('hero')        // theme static
        ->toContain('cta')                   // theme static
        ->toContain('latest_posts');         // Cms data-bound
});

it('renders a data-bound component sourced from another object (latest posts)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
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

it('links each latest post to its canonical route', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
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

        // No section is pinned to a preset → the post routes flat (platform default); the component
        // links to whatever RouteSync stored, so it reflects the flat canonical route.
        expect($html)->toContain('href="/bai-co-route"')->toContain('Bai co route');
    });
});

it('renders a homepage composed of several components', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
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

it('every registered component declares a typed prop schema (§E7)', function (): void {
    $registry = app(ComponentRegistry::class);

    foreach ($registry->types() as $type) {
        $schema = $registry->get($type)->schema();
        expect($schema)->toBeArray();

        foreach ($schema as $spec) {
            expect($spec)->toBeArray()->toHaveKey('type');
            expect(['text', 'textarea', 'url', 'int', 'list', 'image'])->toContain($spec['type']);

            // A repeater (list) must declare its item shape so the editor can build new items.
            if ($spec['type'] === 'list') {
                expect($spec)->toHaveKey('of');
            }
        }
    }
});

it('renders a features block from its items[] schema shape', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'features', 'props' => [
                'heading' => 'Dich vu',
                'items' => [
                    ['title' => 'Bao on', 'text' => 'Cach nhiet duong ong'],
                    ['title' => 'Gian giao', 'text' => 'Lap dung'],
                ],
            ]],
        ]]);

        expect($html)->toContain('Dich vu')
            ->toContain('Bao on')->toContain('Cach nhiet duong ong')
            ->toContain('Gian giao');
    });
});

it('renders a richtext block from its paragraphs[] schema shape', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'richtext', 'props' => ['heading' => 'Ve chung toi', 'paragraphs' => ['Doan mot.', 'Doan hai.']]],
        ]]);

        expect($html)->toContain('Ve chung toi')
            ->toContain('Doan mot.')->toContain('Doan hai.');
    });
});

it('coerces a string limit from the editor and honours it (int prop round-trip)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 4) as $i) {
            $post = Entry::create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subDays($i)]);
            $post->translations()->create([
                'locale' => 'vi', 'title' => "Bai {$i}", 'slug' => "bai-{$i}", 'status' => 'published', 'body' => ['html' => 'x'],
            ]);
        }

        // Livewire number inputs arrive as strings; the component must coerce, not fall back to 3.
        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'latest_posts', 'props' => ['heading' => 'Tin', 'limit' => '2']],
        ]]);

        expect(substr_count($html, 'latest-posts__item'))->toBe(2);
    });
});
