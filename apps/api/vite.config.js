import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/analytics-charts.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // On small installation hosts, skipping minification avoids V8/Rust
        // peak-memory spikes. This changes output size, not application behavior.
        minify: process.env.ALPHALINK_LOW_MEMORY_BUILD === '1' ? false : 'oxc',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
