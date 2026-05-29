import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/convocation.js', 'resources/js/certificates.js', 'resources/js/topup.js', 'resources/js/logs.js', 'resources/js/tracking.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
