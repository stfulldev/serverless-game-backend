import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

const vitePort = 5173;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        hmr: {
            host: process.env.VITE_HMR_HOST ?? 'localhost',
            clientPort: Number(process.env.VITE_PORT ?? vitePort),
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
