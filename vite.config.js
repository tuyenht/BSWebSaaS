import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/storefront.css', 'resources/css/sf-hero.css', 'resources/css/sf-slider.css', 'resources/css/sf-about.css', 'resources/css/sf-cta.css', 'resources/css/sf-decor.css', 'resources/css/sf-cards.css', 'resources/css/sf-lists.css', 'resources/css/sf-blocks.css', 'resources/css/sf-rail.css', 'resources/css/sf-prose.css', 'resources/css/sf-post.css', 'resources/css/sf-product.css', 'resources/css/sf-form.css', 'resources/css/sf-media.css', 'resources/css/sf-stats.css', 'resources/css/sf-floating.css', 'resources/css/sf-404.css', 'resources/js/app.js', 'resources/js/storefront.js', 'resources/js/sf-slider.js', 'resources/js/sections.js', 'resources/js/sf-video.js', 'resources/js/sf-stats.js', 'resources/js/sf-bgvideo.js', 'resources/js/sf-gallery.js', 'resources/js/sf-tabs.js', 'resources/js/sf-branch-map.js', 'resources/js/sf-footer-rot.js', 'resources/js/sf-viewer.js', 'resources/js/sf-toc.js', 'resources/js/sf-post.js', 'resources/js/sf-turnstile.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
