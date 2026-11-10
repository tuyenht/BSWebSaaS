import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/storefront.css', 'resources/js/app.js', 'resources/js/storefront.js', 'resources/js/sf-slider.js', 'resources/js/sections.js', 'resources/js/sf-video.js', 'resources/js/sf-stats.js', 'resources/js/sf-bgvideo.js', 'resources/js/sf-gallery.js', 'resources/js/sf-tabs.js', 'resources/js/sf-viewer.js'],
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
