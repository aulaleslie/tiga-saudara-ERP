import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel([
            'resources/sass/app.scss',
            'resources/js/app.js',
            'resources/js/chart-config.js',
            'resources/css/tw.css',
        ]),
    ],
});
